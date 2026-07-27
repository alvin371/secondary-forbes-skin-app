# Superseded: Legacy endorse sync handoff

> This handoff is historical and was superseded on 2026-07-17 by [tiktok-sync-source-reference.md](tiktok-sync-source-reference.md). The active implementation keeps the queue enabled, uses the cron consumer, and routes TikTok, Instagram, and Threads content through the shared queue.

## Current state

This application combines direct TikTok refreshes with `endorse_refresh_queue` for campaign refresh actions. The queue worker is `Api_v2::cronjob_endorse_refresh()` and writes the same `endorse` and `endorse_logs` records as direct paths.

The operational tables are `endorse`, `endorse_logs`, `endorse_campaign`, `influencer`, `influencer_logs`, `endorse_refresh_queue`, and its attempt history. `endorse_logs` is unique by `(id_endorse, date)`.

### Active call paths

- `Api_v2::cronjob_endorse` and `Api_v2::cronjob_influencer` are legacy direct controllers with behavior that diverged from bhskin.
- `Endorse::bulk_refresh`, campaign refresh actions, and queue UI call `EndorseRefreshQueueService`.
- `Api_v2::cronjob_endorse_refresh` consumes queue rows and writes endorse/log values.
- Content-optimization snapshot features also enqueue into this queue.

After cutover, these writers cannot run alongside the direct bhskin-compatible service because they share `endorse` and `endorse_logs`.

## Known limitations

- Instagram methods return unavailable responses.
- The queue can coexist with direct writers.
- Queue-dependent optimization snapshots are not compatible with the bhskin simple-cron target.
- Threads OAuth, tokens, and scheduled sync do not exist.

All existing rows remain intact through the rebuild.

## Source-of-truth comparison

The target is the checked-out bhskin `Api_v2` and `Template` behavior: RapidAPI TikTok/Instagram requests, official Threads Graph requests, bounded batches, daily logs, and campaign aggregation. Its observable selector and metric semantics remain the compatibility standard.
