# TikTok sync — source reference (forbes-skin-app develop)

Source of truth: forbes-skin-app, branch `develop`, commit `25e15204` ("Merge pull request #192 from alvin371/feat/endorse-refresh-rust-worker"). Line references (`src :N`) point to that snapshot. This document records the production semantics that this repository's port must match, and the deliberate deviations of the port.

## Deviations in this port

| Area | Source (develop) | This port |
|---|---|---|
| TikTok content fetch | Fetch-mode switch `ENDORSE_REFRESH_FETCH_MODE` (`rapidapi_batch` / `bhskin_scrape`), direct tiktok.com HTML scrape with hardcoded cookie, RapidAPI fallback | **RapidAPI only.** No scrape paths, no cookies, no fetch-mode plumbing |
| Queue consumer | Cron worker or long-lived Rust `endorse-refresh-worker` (`ENDORSE_REFRESH_DRIVER=rust`) | **Cron driver only.** claim/result HTTP endpoints ported (auth-guarded) for future use; no Rust worker deployed |
| Platforms | TikTok only; Instagram returns "Layanan belum tersedia"; Threads absent | Instagram (RapidAPI `instagram-looter2`) and Threads (official `graph.threads.net` Graph API + OAuth) dispatch shims in `Template::get_social_media` / `get_account_id` / `get_post_list`; all platforms ride the same queue |
| Snapshots (initial/final) | Content-optimization snapshot enqueue triggers + reconcile cron active | `apply_snapshot` machinery ported **dormant** — no enqueue triggers; optimization columns not migrated |
| Campaign rollup impl | Two implementations: `Api_v2::update_endorse_parent` (legacy crons) and `Endorse_sync::update_campaign_parent` (queue) | `Endorse_sync::update_campaign_parent` only (see Known bugs) |
| Non-TikTok influencer profiles | ScrapingBot queue (`scraping_queue`, submit/poll crons) | Direct fetch in `cronjob_influencer` via IG/Threads Template methods; ScrapingBot code retained but unscheduled |

## 1. Fetch primitives (`application/libraries/Template.php`)

RapidAPI host `tiktok-video-no-watermark10.p.rapidapi.com`, configured by `RAPIDAPI_HOST` / `RAPIDAPI_KEY`, headers `x-rapidapi-host` / `x-rapidapi-key`. Transport: GET/cURL with retry (3 attempts, 300 ms backoff).

Three endpoints:

1. **`/index/Tiktok/getUserInfo?unique_id={username}`** — profile resolve (`getDataFromFirstEndpoint`, src :806). Success: `code === 0 && data.user.uniqueId` non-empty. Mapping: `account_id = data.user.secUid`, `follower = data.stats.followerCount`, `media_count = data.stats.videoCount`, `img = avatarLarger|avatarMedium|avatarThumb`.
2. **`/index/Tiktok/getUserVideos?unique_id=@{username}&count=10&cursor=0`** — recent videos (`get_post_list`, src :1363). Per video: `digg_count→like`, `share_count→share`, `comment_count→comment`, `collect_count→collect`, `play_count→view`, plus `video_id/aweme_id/title/cover/duration/play/create_time/author.*`.
3. **`/index/Tiktok/getVideoInfo?url={encoded}&hd=0`** — single post detail (`buildTiktokDetailUrl` src :558, validated by `isValidRapidApiTiktokDetailResponse` src :696). Consumes `data.{id,digg_count,share_count,comment_count,collect_count,play_count,create_time,cover,origin_cover,ai_dynamic_cover,images,play}`.

Normalized `get_social_media` output (the contract every apply consumes):
`data.{like, share, comment, collect, view, created_at, content_id, media_type, video_link, cover, images}`.

Transport-failure classification (`classifyRapidApiTransportFailure`, src :622): DNS → `infra_dns`, connect → `infra_connect`, TLS → `infra_tls`, timeout/stall → `infra_stall`. Auth/config failures (`isRapidApiAuthFailure`) → `config`.

**Batch fan-out** — `get_social_media_batch($tasks, $parallel, $deadline)` (src :1525):
- curl_multi, HTTP/2 multiplexing, chunk size = `ENDORSE_REFRESH_PARALLEL_HTTP` (default 10, clamp 1–20).
- Brownout backoff: on failure-heavy chunks the effective chunk halves (floor 4); recovery adds +2 per healthy chunk.
- Wall-clock deadline (`ENDORSE_REFRESH_DEADLINE_SEC`, default 45): over-budget remaining tasks return `deferredBatchResult` (src :1641) — a distinguished `deferred` marker, not a failure.
- **Rescue lane**: an item whose previous attempt failed with `error_class = infra_stall` is fetched alone (chunk 1), with `timeout_sec = max(45, ENDORSE_REFRESH_HTTP_TIMEOUT + 15)`, and `hd=1` for photo posts.
- Non-TikTok tasks fall through to single `get_social_media` calls (in this port: with `influencer_id` for Threads token lookup).

## 2. Pipeline A — influencer profile sync

**`Template::syncTiktokProfile($entityType, $entityId, $type, $url)`** (src :1184; `ratecard` is loaded from the entity row):
1. `getUserInfo` → update `influencer`/`influencer_dummy`: `account_id, img, follower, media_count, full_name` (username), `updated_by='1'`.
2. `getUserVideos` (last 10 posts) → sums → `frequency_2, view_2, like_2, collect_2, share_2, comment_2, avg_view_2, avg_interaksi_2`, plus:
   - `er = avg_interaksi_2 / avg_view_2 * 100`
   - `cpm_2 = ratecard / avg_view_2 * 1000`
   - `sync_at` stamped; no-post fallback still stamps `sync_at`.
3. `influencer` entity type only: upsert daily **`influencer_logs`** row keyed `(id_influencer, DATE(date))` with raw sums `like/comment/collect/share/view`, `avg_view`, `avg_interaksi`, `er`, `sync_at`.

**`Api_v2::cronjob_tiktok_sync`** (route `api/cronjob/tiktok-sync`, src :7506): no time gate; max 20 records/run; 300 ms `usleep` between records; 4-tier staleness priority over `influencer` + `influencer_dummy` where `type='Tiktok' AND status='Aktif' AND url != ''`:

| Tier | Condition | Quota |
|---|---|---|
| 1 | `sync_at IS NULL` | 10 influencer + 10 dummy |
| 2 | has active campaign AND `sync_at < now-3d` | 10 |
| 3 | `sync_at < now-7d` | 5 + 5 dummy |
| 4 | `sync_at < now-14d` | 5 |

First-party aggregation from `endorse` (`frequency, total_cost, views, likes, comment, share, avg_view, avg_interaksi, cpm`) is NOT part of this cron — it lives in `cronjob_influencer` (src :4659, 11:00-gated), which in this port also performs direct Instagram/Threads profile fetches.

## 3. Pipeline B — endorse content sync (queue)

### Tables

**`endorse_refresh_queue`**: `id, id_endorse, id_campaign, platform, link_upload, purpose ENUM-like VARCHAR ('daily'|'initial'|'final', DEFAULT 'daily'), status ENUM(pending, processing, completed, failed), priority (default 10), attempts, max_attempts (3), worker_id, claimed_at, started_at, completed_at, error_message, enqueued_by, retry_source_id, created_at`. Indexes: `idx_pop`, `idx_dedup`, `idx_campaign_status`, `idx_purpose_dedup(id_endorse, purpose, status)`.

**`endorse_refresh_queue_attempts`**: `id, queue_id, attempt_no, worker_id, status ENUM(processing, retrying, completed, failed), error_class, error_message, started_at, finished_at, created_at`. **One row per upstream request** — this is the unit the rate caps count.

### Enqueue

- UI per-campaign: `Endorse::bulk_refresh` → `enqueueCampaign`; `Ajax::refresh_campaign_endorses`.
- UI "Refresh Semua": `Ajax::refresh_all_active_endorses` → `enqueueAllActive` (active campaigns + active posts only).
- Cron: `api/cronjob/endorse-refresh-enqueue-all` → `enqueueAllActive(0)` (worker-secret guarded). Daily driver `cronjob_endorse` (11:00-gated) enqueues stale rows.
- Retry failed: `Endorse::force_retry` → `cloneFailedRows` (fresh pending rows, `retry_source_id` lineage).
- Mechanics (`enqueueRows`): insert chunk 250, priority 10, max_attempts 3. **Purpose-scoped dedup**: skip if a pending/processing row exists for the same `(id_endorse, purpose)`. **Known-URL-issue exclusion**: skip endorses whose latest queue row failed with message like `Stats data tidak ditemukan` / `url tidak ditemukan`.

### Claim contract (`claimBatch`, src :617)

Order matters:
1. `resetStuck` — `processing` rows older than 5 min return to `pending`; their attempt row marked `retrying` ("Worker stalled").
2. Daily cap `ENDORSE_REFRESH_DAILY_CAP` (0 = off): counts attempt rows with `started_at` today.
3. Per-minute cap `ENDORSE_REFRESH_RATE_PER_MIN` (default 250): attempt rows started in the last 60 s.
4. Atomic single-`UPDATE` claim: `WHERE status='pending' AND worker_id IS NULL ORDER BY priority DESC, attempts ASC, created_at ASC LIMIT n`, tagging `worker_id = uniqid('w_')`, `status='processing'`, `claimed_at/started_at`.
5. Insert one `processing` attempt row per claimed item immediately (so caps see in-flight work).
6. Per-item fetch hints: rescue lane (see §1) when the prior attempt's `error_class = infra_stall`.

`force=true` bypasses both caps and uses `ENDORSE_REFRESH_FORCE_BATCH` (250) instead of `ENDORSE_REFRESH_BATCH_SIZE` (40).

### Apply contract (`applyResults`, src :799)

Per item:
- `deferred` → row returns to `pending` **and the up-front attempt row is deleted** — deferrals never burn caps or attempts.
- Success (`Endorse_sync::apply`, or `apply_snapshot` for initial/final purpose) → `completed`, attempt finalized, campaign marked touched.
- Failure → `Endorse_sync::is_terminal_class`: **only `permanent` and `empty` terminate immediately.** Every other class (`transient`, `infra*`, `config`) retries until `attempts >= max_attempts (3)`, then `failed`.
- Touched campaigns rolled up once per batch via `Endorse_sync::update_campaign_parent` (daily purpose only).

### Worker (`Api_v2::cronjob_endorse_refresh`, src :7869)

`@set_time_limit(55)`. Driver gate: when `ENDORSE_REFRESH_DRIVER=rust` and not forced, the cron stands down (this port keeps the gate; default and only used mode is `cron`). Flow: `claimBatch` → build fetch tasks (link, platform, rescue hints, and in this port `influencer_id`) → `get_social_media_batch(tasks, PARALLEL_HTTP, DEADLINE_SEC)` → `applyResults`. Three staggered cron entries per minute (offsets 0/20/40 s) ≈ 90–120 items/min, bounded by the per-minute cap.

Manual paths:
- `Endorse::sync_process` — synchronous single-row `get_social_media` + `apply` + rollup; bypasses queue and caps.
- `Endorse::run_worker` ("Proses Sekarang") — loopback `api/cronjob/endorse-refresh?force=1`: bypasses caps, larger batch, runs inline regardless of driver.

## 4. Metric mapping and derivations (`Endorse_sync`)

### Daily `apply` (src :114)

| API field | endorse column | endorse_logs |
|---|---|---|
| `like` | `likes` | `likes_after` (cumulative), `likes` (diff) |
| `comment` | `comment` | `comment_after`, `comment` |
| `share + collect` | `share_save` (**merged**) | `share_save_after`, `share_save` |
| `view` | `views` | `views_after`, `views` |
| `created_at` | `posting_at` | — |

- Baseline: latest prior `endorse_logs` row with `views_after > 0` supplies `*_before`.
- Diff columns = `max(0, after − before)`.
- **Views are non-decreasing**: a lower API reading never lowers `endorse.views`.
- **FYP rule**: `views >= 50000 AND (follower == 0 OR views >= follower * 0.30)` → `is_fyp='1'`.
- `cpm = total_cost / views * 1000` (also `cpm_before` / `cpm_after` on the log row).
- TikTok media columns when platform is TikTok and columns exist: `tiktok_content_id, tiktok_media_type, tiktok_cover, tiktok_content_link, tiktok_fetched_at`.
- Daily log upsert keyed `(id_endorse, date)` — this repo enforces it with unique index `uniq_id_endorse_date` and writes through `upsert_daily_log_row` (kept in the port; safer than source's select-then-insert).

### Snapshots — `apply_snapshot` (src :292, ported dormant)

Frozen columns `like_initial/comment_initial/share_initial/save_initial/view_initial` + `initial_fetched_at` (write-once), `*_final` + `final_fetched_at`, `*_growth`. **`share` and `save` are stored separately here** (`save` = API `collect`) — unlike the merged daily `share_save`. Snapshots never touch daily columns or `endorse_logs`.

## 5. Campaign rollup

- `Endorse_sync::update_campaign_parent` (src :443): upserts a day row in `endorse_campaign_logs` (SUM likes/comment/share_save/views, AVG cpm, `ce_*`/`ci_*` endorse/influencer counters as now/before/after) and updates `endorse_campaign` counters.
- Hourly full-sweep backstop inside `cronjob_endorse`: rolls up ALL active campaigns at most once per hour, gated by file marker `APPPATH/cache/endorse_rollup_sweep.txt`.
- `Api_v2::cronjob_endorse_rollup` (route `api/cronjob/endorse-rollup`, src :8147): maintains **`endorse_logs_daily_rollup`** (PK `(id_endorse, log_date)`) for the KOL overview chart. Window `ENDORSE_ROLLUP_WINDOW_DAYS` (60); `?full=1` full backfill. Delta = `SUM(GREATEST(col,0))`, after = `MAX(cumulative)`. Requires the `endorse_logs.log_date` STORED generated column. Readers switch to the rollup table when `ENDORSE_ROLLUP_READ=1`.

## 6. Error-class taxonomy

| Class | Meaning | Terminal? | Rescue lane? |
|---|---|---|---|
| `ok` | success | — | — |
| `permanent` | URL invalid / content gone ("Stats data tidak ditemukan", "url tidak ditemukan") | **yes** | no |
| `empty` | response valid but no stats | **yes** | no |
| `transient` | generic retryable upstream failure | no (retry to 3) | no |
| `infra` | generic infrastructure failure | no | no |
| `infra_dns` | DNS resolution failure | no | no |
| `infra_connect` | TCP connect failure | no | no |
| `infra_tls` | TLS handshake failure | no | no |
| `infra_stall` | timeout / stalled transfer | no | **yes** (next attempt: chunk 1, extended timeout, hd=1 for photos) |
| `config` | missing/invalid RapidAPI credentials | no | no |

## 7. Gates and schedules

- **11:00 Asia/Jakarta gate** on `cronjob_endorse` and `cronjob_influencer` (bypass with `?mode=true`). `cronjob_tiktok_sync` and the queue worker are ungated.
- Worker: 3 staggered entries per minute (0/20/40 s).
- Hourly rollup sweep file marker (§5).
- Recommended crontab for this deployment: see the port plan / deployment docs.

## 8. Environment variables

| Var | Default | Notes |
|---|---|---|
| `RAPIDAPI_HOST` | `tiktok-video-no-watermark10.p.rapidapi.com` | TikTok RapidAPI |
| `RAPIDAPI_KEY` | — | required |
| `INSTAGRAM_RAPIDAPI_HOST` | `instagram-looter2.p.rapidapi.com` | port addition |
| `INSTAGRAM_RAPIDAPI_KEY` | — | port addition |
| `THREADS_APP_ID` / `THREADS_APP_SECRET` | — | port addition (separate Meta app per deployment) |
| `WORKER_SHARED_SECRET` | — | guards claim/result + enqueue-all |
| `WORKER_IP_ALLOWLIST` | — | optional CSV |
| `ENDORSE_REFRESH_BATCH_SIZE` | **40** | source `.env.example` says 250 but code default is 40 — standardized on 40 |
| `ENDORSE_REFRESH_FORCE_BATCH` | 250 | force path |
| `ENDORSE_REFRESH_PARALLEL_HTTP` | 10 | clamp 1–20 |
| `ENDORSE_REFRESH_RATE_PER_MIN` | 250 | attempt rows / 60 s |
| `ENDORSE_REFRESH_DAILY_CAP` | 0 (off) | attempt rows / day |
| `ENDORSE_REFRESH_DEADLINE_SEC` | 45 | batch wall clock |
| `ENDORSE_REFRESH_HTTP_TIMEOUT` | 30 | per request |
| `ENDORSE_REFRESH_DRIVER` | `cron` | `rust` unused in this port |
| `ENDORSE_ROLLUP_READ` | 0 | flip to 1 after `?full=1` backfill |
| `ENDORSE_ROLLUP_WINDOW_DAYS` | 60 | rollup window |

Not carried over (scrape/Rust-only): `ENDORSE_TIKTOK_SCRAPE_ENABLED`, `ENDORSE_REFRESH_FETCH_MODE`, `TIKTOK_METRICS_PREFER_RAPIDAPI`, `ENDORSE_REFRESH_CONCURRENCY`, `ENDORSE_REFRESH_IDLE_SLEEP_MS`, `ENDORSE_REFRESH_QUEUE_ENABLED` (queue is always on in this port).

## 9. Known source bugs NOT ported

- `Api_v2::update_endorse_parent` references undefined `$user['id']` (src :5304) and `$dt['now_before']` (src :5325) — empty `updated_by`, wrong deltas. All rollups in this port go through `Endorse_sync::update_campaign_parent`.
- `cronjob_endorse_by_campaign` depends on `endorse_campaign.refresh_requested_at`, a column no migration creates. Superseded by `bulk_refresh`; not ported.

## 10. Naming trap (source repo)

`services/tiktok-worker-rust` / the `tiktok-worker` compose service in the source repo sync **TikTok Shop orders** (`open-api.tiktokglobalshop.com`) and are unrelated to metric sync. The metrics worker there is `services/endorse-refresh-worker` — deliberately not deployed in this port.
