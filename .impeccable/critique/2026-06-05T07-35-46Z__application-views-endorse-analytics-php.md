---
target: application/views/endorse/analytics.php
total_score: 17
p0_count: 1
p1_count: 3
timestamp: 2026-06-05T07-35-46Z
slug: application-views-endorse-analytics-php
---
## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 1 | No `.fail()` on any `$.getJSON` — spinner persists on error forever |
| 2 | Match System / Real World | 3 | Indonesian copy fits audience; "CPM" unexplained; "Views Gain" vs "Views +/-" inconsistent |
| 3 | User Control and Freedom | 2 | No date reset, no filter clear; modal has close button but missing Esc shortcut |
| 4 | Consistency and Standards | 2 | Detail buttons styled differently per tab; "Views Gain" vs "Views +/-" same concept, two names |
| 5 | Error Prevention | 1 | No start > end date validation; no error handling on 5 AJAX calls |
| 6 | Recognition Rather Than Recall | 3 | Most controls visible; "go to trends" is icon-only; no KPI card tooltips |
| 7 | Flexibility and Efficiency | 2 | Threshold field is power-user friendly; no date presets, no export, no keyboard shortcuts |
| 8 | Aesthetic and Minimalist Design | 2 | 4 simultaneous tabs + 4 KPI cards = heavy landing; filter row has 5 controls with no visual grouping |
| 9 | Error Recovery | 0 | No error states anywhere; failing AJAX shows nothing; "Tidak ada data" has no guidance |
| 10 | Help and Documentation | 1 | One engagement tooltip is good; zero contextual help on KPIs, anomaly algorithm, CPM formula |
| **Total** | | **17/40** | **Poor — significant improvements needed before users are comfortable** |

---

## Anti-Patterns Verdict

**LLM assessment**: Not AI-generated look — this is clearly hand-written production code with real business logic. The slop here is of a different kind: the page leans entirely on Bootstrap 5 semantic color tokens (`bg-danger`, `bg-warning`, `text-success`) with no brand layer on top, making it feel like an unstyled internal prototype rather than a finished tool. The user anti-referenced "Low-effort Bootstrap defaults" — and that describes the current state precisely. Card borders, table hover states, and badge colors are all stock Bootstrap. No tinted neutrals, no spatial hierarchy beyond Bootstrap's grid. The one genuine bright spot is the sparkline in the Trends tab — that's a real design decision (bar chart in a table cell) that earns its space.

**Deterministic scan**: `detect.mjs` returned `[]`, exit 0 — no mechanical anti-patterns found (no gradient text, no glassmorphism, no sketchy SVG, no stripe backgrounds). The slop here is vocabulary-level (Bootstrap defaults), not pattern-level.

**Visual overlays**: Browser automation unavailable in this session; no overlay injected. CLI scan is the sole detector signal.

---

## Overall Impression

Functional and correctly structured. Tabs are the right IA choice for these four data domains. Sparklines show design judgment. But the page has two large failure modes: (1) zero error handling on all five AJAX calls makes failures invisible to users, and (2) everything is rendered in Bootstrap defaults — there's no spatial or visual system that makes the page feel like a finished tool. The single biggest opportunity is fixing the AJAX failure path first (P0), then establishing a consistent filter bar pattern across all four tabs.

---

## What's Working

**1. Sparklines in the Trends tab** — Embedding Chart.js bar charts directly in table cells is exactly the right call for this data. It gives instant per-creator trend context without a separate page. The color coding (red = negative, blue = positive) adds meaning.

**2. Tab badges showing live counts** — `#badge-missing` and `#badge-anomaly` update after data loads, giving the user a quick scan of campaign health before entering any tab. This is a genuinely good UX decision.

**3. Missing tab empty state** — `<i class="bi bi-check-circle"></i> Semua creator sudah log data` (line 424) is the only tab with a positive confirmation. This pattern — specific, reassuring, status-communicating — should be replicated across all empty states.

---

## Priority Issues

**[P0] Silent AJAX failures — all five `$.getJSON` calls have no `.fail()` handler**
- **Why it matters**: Network error, server 500, or endpoint timeout leaves spinners (`fa-circle-o-notch fa-spin`) running indefinitely. The user sees no feedback, has no idea the data failed, and cannot retry. This is the worst failure mode for an operational tool — it looks like slow data rather than a broken request.
- **Fix**: Add `.fail(function(xhr){ $('#tbody-X').html('<tr><td colspan="N" class="text-center text-danger py-3"><i class="bi bi-exclamation-circle"></i> Gagal memuat data. <button class="btn btn-sm btn-link" onclick="loadX()">Coba lagi</button></td></tr>'); })` to all five `$.getJSON` chains in `loadSummary`, `loadMissing`, `loadPerformers`, `loadTrends`, `loadAnomalies`.
- **Suggested command**: `$impeccable harden`

**[P1] No date range validation — start > end is silently accepted**
- **Why it matters**: User sets start date after end date; all five API calls fire with an invalid range; APIs return empty data; every tab shows "Tidak ada data" with no explanation. User assumes no data exists for this campaign, not that their date input was wrong.
- **Fix**: In `loadAll()`, validate `anStart() < anUntil()` before firing any requests. Show an inline error near the date inputs if invalid. Also disable the "Terapkan" button when the range is invalid.
- **Suggested command**: `$impeccable harden`

**[P1] Detail buttons inconsistently styled across tabs**
- **Why it matters**: Trends tab uses `btn-outline-primary` for the detail button; Anomalies tab uses `btn-outline-secondary`. Same action type, different visual treatment. Performers tab uses `btn-outline-secondary` too. Users fluent in the tool will hesitate — is `btn-outline-primary` more important? Does it do something different? It doesn't, but the inconsistency forces a cognitive question.
- **Fix**: Standardize all row-level detail buttons to one style. `btn-outline-secondary` is the right choice (detail is secondary to the data itself). Update line 562–564 in the Trends block.
- **Suggested command**: `$impeccable polish`

**[P1] KPI cards show `-` during load — indistinguishable from "no data"**
- **Why it matters**: On page load, all four KPI cards display `-` until `loadSummary()` resolves. This looks identical to the final state when a campaign has zero data. Users with slow connections or failed summary requests cannot tell if they're waiting or if the campaign has nothing.
- **Fix**: Replace `-` initial values with a loading skeleton or spinner element. Simplest: `<span class="spinner-border spinner-border-sm text-muted"></span>` in each KPI div on page load, replaced by real data on response.
- **Suggested command**: `$impeccable harden`

**[P2] Five filter controls in a flex row with no visual grouping — Performers tab**
- **Why it matters**: The Performers filter bar has: search input, platform select, sort select, order select, limit select — five controls in a single unwrapped flex row. At 1280px width these compress together without spatial grouping. Related controls (sort + order) are not visually grouped; unrelated controls (search vs. sort) share the same visual weight. The filter row also has no background or border separation from the table header below it — it merges visually.
- **Fix**: Group sort + order together (they're one composite control), give the filter row a subtle background tint or bottom border, reduce to max 4 visible controls. Consider moving "Top 10 / Bottom 10 / All" into a button group (more scannable than a select).
- **Suggested command**: `$impeccable layout`

---

## Persona Red Flags

**Alex (Power User)** — primary persona for a campaign analytics tool:
- No date range presets (Last 7 days, This Month). Every session requires manually typing start and end dates into two `<input type="date">` fields. For a daily-use operational dashboard this is the highest-friction repeated action.
- No data export. A campaign manager reviewing performer rankings needs to share this with a team. No CSV, no copy-as-table. Must screenshot.
- `#perf-limit` select has **both** an `onchange="renderPerformers()"` HTML attribute (line 171) AND a jQuery event listener (line 514). `renderPerformers()` fires twice on every change. Not user-visible usually, but wastes a render and could cause flicker in slower browsers.
- Sort preference resets to "Views" on every page load — no localStorage persistence.

**Sam (Accessibility-Dependent User)**:
- `*:focus { outline: none; background-color: rgba(221,72,20,.2); }` in `style.css` line 78–80 removes the CSS outline from ALL focusable elements and replaces it with a red tint. The tint (`rgba(221,72,20,.2)`) is at ~20% opacity — it will fail WCAG 2.2's 3:1 non-text contrast ratio for focus indicators on white backgrounds.
- Red table rows in the Missing tab (`table-danger`) convey status via background color alone. Screen reader sees a `<tr class="table-danger">` — the class name is not announced. No `aria-label`, no textual cue in a cell.
- All Chart.js sparkline canvases (`<canvas id="spark-X">`) have no `aria-label` or `role="img"`. Completely invisible to screen readers.
- `statusBadge()` renders `<span class="badge bg-success">Posted Content</span>` etc. — text content is announced by screen reader, which is fine, but the color carries no additional semantic meaning.

**Riley (Stress Tester)**:
- `Chart.helpers.each(Chart.instances, function(c){ c.destroy(); })` at line 532. In Chart.js v3+, `Chart.instances` is an object `{id: instance}`, not an array. `Chart.helpers.each` in v3 handles objects, but this API was deprecated in v3.7+ and may throw in future versions. Silently skipping chart cleanup would cause canvas ID collisions on re-render.
- `loadPerformers()` is triggered by `$('#perf-sort').val()` and `$('#perf-order').val()` onchange (lines 162–168), but also called by `loadAll()`. No debounce. Rapid changes to sort/order fire multiple in-flight requests; the last to resolve wins. Can produce incorrect displayed data if responses arrive out of order.
- What happens when `id_campaign` is injected via PHP but the campaign has been deleted? All five API endpoints would likely return empty or error — no guard in the frontend.

---

## Minor Observations

1. `#perf-limit` double-fire: the `<select onchange="renderPerformers()">` attribute AND the jQuery `.on('input change')` binding both call `renderPerformers()` on change. Line 171 vs. line 514. Remove the `onchange` attribute, keep the jQuery binding.
2. Date filter card has no `.card-body` wrapper — the `<div class="row align-items-end g-2">` is a direct child of `.card`, so content has zero horizontal padding. Add `<div class="card-body py-2">` wrapper.
3. `AN_BASE` and `AN_CAMPAIGN` are bare global JS variables — minor namespace pollution. Low risk in this single-view context.
4. "Kembali" button link (line 27) hardcodes `?id_campaign=` query param without URL encoding. If campaign ID is ever non-numeric this breaks. Low risk currently.
5. Missing loading threshold: threshold changes require explicit "OK" button click but sort/order changes auto-load. Inconsistent trigger model within the same page.

---

## Questions to Consider

- "After finding a missing creator, what's the next action? Is there a direct link to the creator's profile or a way to trigger a re-scrape from this page? If not, is this page completing the user's job or just telling them there's a problem?"
- "Should the first open tab be determined by data — open Missing if `missing_count > 0`, otherwise open Trends? The current hardcoded 'Missing' tab as default may be the right call, but it might also frustrate users who visit during healthy campaign periods."
- "Could 80% of sessions be served by 3 preset date ranges (This Week / This Month / Last 30 Days) instead of the manual date picker? The picker could still exist as an 'advanced' option."
