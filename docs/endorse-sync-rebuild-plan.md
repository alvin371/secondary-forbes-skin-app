# Superseded: Bhskin-compatible endorse sync handoff

> This plan is historical and was superseded on 2026-07-17 by [tiktok-sync-source-reference.md](tiktok-sync-source-reference.md). The active implementation uses the shared refresh queue with a cron consumer and RapidAPI-only TikTok fetching; do not deploy the direct-sync or queue-disabled instructions below.

## Target behavior

Port the checked-out bhskin direct sync behavior for TikTok, Instagram, and Threads: profile and content metrics, daily endorse logs, campaign rollups, manual refresh, and bounded cron batches after 11:00 Asia/Jakarta. Bhskin's established selectors and metric semantics are retained.

### Public endpoints

- `/api/cronjob/endorse`, `/api/cronjob/influencer`, and `/api/cronjob/endorse-campaign`
- `/api/cronjob/endorse-threads` and `/api/cronjob/influencer-threads`
- `/api_v2/threads_authorize?influencer_id={id}`, callback, refresh, deauthorize, deletion, and deletion-status endpoints

Standard batches process at most 10 records; Threads batches process at most 5. Existing daily endorse-log uniqueness is preserved through update-or-insert behavior.

## Required configuration

- TikTok: `RAPIDAPI_HOST`, `RAPIDAPI_KEY`.
- Instagram: `INSTAGRAM_RAPIDAPI_HOST`, `INSTAGRAM_RAPIDAPI_KEY`.
- Threads: `THREADS_APP_ID`, `THREADS_APP_SECRET`; use a separate Meta app for this deployment.
- Queue off: `ENDORSE_REFRESH_QUEUE_ENABLED=0`.

Credentials stay outside git. Meta callback, deauthorization, and deletion URLs use this deployment's public base URL and are documented as deployment placeholders.

## Operations and validation

Run endorse, influencer, campaign, and both Threads crons every minute; their code gates execution before 11:00 Asia/Jakarta. Run `api_v2/threads_refresh_token` at 00:05 Asia/Jakarta. Disable former queue and ScrapingBot sync schedules.

### Deployment sequence

1. Back up endorse, logs, campaign, influencer, and queue/attempt history.
2. Deploy the Threads schema migration and environment configuration with `ENDORSE_REFRESH_QUEUE_ENABLED=0`.
3. Stop old queue and ScrapingBot schedules before enabling direct sync schedules.
4. Configure this deployment's separate Meta Threads app using its exact public callback, deauthorization, and deletion URLs.
5. Enable Threads content jobs only after an approved KOL authorizes the app.

Back up first, then validate TikTok endorse `10558` and influencer `35`. Confirm direct writes update the daily log and never enqueue. Instagram and Threads validation waits for approved production records and an authorized Threads KOL.
