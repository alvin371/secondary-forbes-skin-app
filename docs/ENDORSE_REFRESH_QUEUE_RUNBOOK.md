# Endorse refresh queue runbook

The queue is always enabled in this deployment. TikTok content uses RapidAPI only; Instagram and Threads use their platform adapters. The queue consumer is the PHP cron endpoint—no Rust metrics worker is deployed.

For the full fetch, claim/apply, metric, and retry contract, see [tiktok-sync-source-reference.md](tiktok-sync-source-reference.md).

## Deployment prerequisites

1. Run the application migrations through `20260715002000`.
2. Confirm `endorse_refresh_queue.purpose`, `endorse_logs.log_date`, and `endorse_logs_daily_rollup` exist.
3. Configure `RAPIDAPI_KEY`; configure the Instagram/Threads credentials when those platforms are enabled.
4. Set `WORKER_SHARED_SECRET` and send it to the enqueue-all, claim, and result endpoints as `X-Worker-Secret`.
5. Keep `ENDORSE_REFRESH_DRIVER=cron`.
6. Backfill the chart rollup once with `/api/cronjob/endorse-rollup?full=1`, compare it with raw results, then set `ENDORSE_ROLLUP_READ=1` only after validation.

## Cron schedule (Asia/Jakarta)

```cron
* * * * * curl -s --max-time 55 "https://HOST/api/cronjob/endorse-refresh" >/dev/null 2>&1
* * * * * sleep 20 && curl -s --max-time 55 "https://HOST/api/cronjob/endorse-refresh" >/dev/null 2>&1
* * * * * sleep 40 && curl -s --max-time 55 "https://HOST/api/cronjob/endorse-refresh" >/dev/null 2>&1
*/10 11-13 * * * curl -s "https://HOST/api/cronjob/endorse" >/dev/null 2>&1
30 11 * * * curl -s -H "X-Worker-Secret: SECRET" "https://HOST/api/cronjob/endorse-refresh-enqueue-all" >/dev/null 2>&1
*/15 * * * * curl -s "https://HOST/api/cronjob/endorse-rollup" >/dev/null 2>&1
*/5 * * * * curl -s --max-time 55 "https://HOST/api/cronjob/tiktok-sync" >/dev/null 2>&1
*/10 11-17 * * * curl -s "https://HOST/api/cronjob/influencer" >/dev/null 2>&1
0 3 * * * curl -s "https://HOST/api_v2/threads_refresh_token" >/dev/null 2>&1
```

The endorse and non-TikTok influencer crons self-gate at 11:00. Leave the retained ScrapingBot crons unscheduled.

## Runtime controls

- `/endorse/queue` shows queue totals, worker health, attempt history, and the likely stall cause.
- `Proses Sekarang` calls `/endorse/run-worker`, which runs one forced inline batch and bypasses rate caps.
- `Lepas yang Macet` returns processing rows older than five minutes to pending.
- `Retry Gagal Terpilih` creates new pending rows and preserves the failed rows for audit.
- `Clear Semua Data` deletes both queue rows and attempt history; use it only when that audit loss is intentional.

## Status and retry behavior

- `pending`: waiting to be claimed.
- `processing`: claimed and currently being fetched.
- `completed`: metrics were applied successfully.
- `failed`: a permanent/empty result terminated immediately, or a retryable result exhausted three attempts.
- `deferred`: an internal batch result, not a stored queue status; the row returns to pending and the attempt does not count toward caps.

Only `permanent` and `empty` are immediately terminal. Transport, configuration, and transient failures retry up to `max_attempts`. A prior `infra_stall` failure uses the single-item rescue lane on its next attempt.

## First checks when jobs do not move

1. Open `/endorse/queue` and inspect the health banner and attempt history.
2. Confirm all three staggered worker calls are running.
3. Check `RAPIDAPI_KEY`, platform credentials, and outbound DNS/TLS connectivity.
4. Confirm stale processing rows are recycled after five minutes.
5. Check the per-minute and daily caps in `.env`.

Important defaults: batch `40`, forced batch `250`, parallel HTTP `10`, rate `250/min`, daily cap off, batch deadline `45s`, HTTP timeout `30s`.
