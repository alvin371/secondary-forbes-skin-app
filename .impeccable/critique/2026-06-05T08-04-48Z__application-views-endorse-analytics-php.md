---
target: application/views/endorse/analytics.php
total_score: 25
p0_count: 0
p1_count: 2
p2_count: 3
p3_count: 1
timestamp: 2026-06-05T08-04-48Z
slug: application-views-endorse-analytics-php
---
## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3 | Spinner per table is solid; no global indicator across 5 concurrent requests |
| 2 | Match System / Real World | 3 | Mixed ID/EN language; domain terms legible for target audience |
| 3 | User Control and Freedom | 3 | No one-click filter reset per tab; no clear button on date inputs |
| 4 | Consistency and Standards | 3 | "Threshold + OK" vs "Terapkan" — two apply patterns for the same concept |
| 5 | Error Prevention | 2 | Sort dropdown auto-fires loadPerformers(); search input doesn't auto-reload — inconsistent reactivity |
| 6 | Recognition Rather Than Recall | 3 | Engagement tooltip via dotted underline is obscure; anomaly types undefined anywhere |
| 7 | Flexibility and Efficiency of Use | 2 | No column-header sort; no export; no keyboard shortcuts |
| 8 | Aesthetic and Minimalist Design | 3 | Anomalies tab: 10 columns forces heavy horizontal scroll to reach actionable columns |
| 9 | Error Recovery | 2 | KPI error state shows bare `!`; all tab errors identical, no specificity |
| 10 | Help and Documentation | 1 | No explanation of anomaly types (Minus/Spike/Stagnan); threshold has no definition |
| **Total** | | **25/40** | **Acceptable** |

## Anti-Patterns Verdict

**LLM assessment**: Not AI-generated. Hand-built, domain-specific, pragmatic. Competent Bootstrap 5 usage. Rank medals (★ gold/silver/bronze), the `goToTrends()` cross-tab shortcut, and per-tab client-side filtering show deliberate design choices. No decorative clutter, gradient text, glassmorphism, or hero-metric templates. Passes the product slop test.

**Deterministic scan**: `detect.mjs` returned `[]` — zero automated findings. No absolute-ban patterns present in the markup.

**Browser overlay**: Skipped — browser automation unavailable in this session. Source review only.

## Overall Impression

The bones are good. This is a functional, purposeful analytics surface that stays out of its own way. The biggest gap isn't visual — it's that the page finds problems but doesn't help users act on them. Missing creators are surfaced, but there's no next step. Anomalies are flagged, but undefined. The platform knows more than it communicates.

## What's Working

1. **Loading state discipline** — `_setLoading()` counter, spinner-per-tbody, and Apply button disabled during requests is a clean pattern. The table placeholders (`colspan=N` spinner) preserve layout during load without jarring reflows.
2. **Cross-tab navigation** — `goToTrends(name)` from the Performers table is a genuine power-user affordance. Someone thought about the workflow, not just the feature list.
3. **Color + badge status vocabulary** — `statusBadge()` function centralizes the status-to-color mapping. Consistent across tabs, readable at a glance.

## Priority Issues

**[P1] No column-header click-to-sort**
- **Why it matters**: Every analytics tool in this category sorts by clicking a column header. The current dropdown requires 2-3 extra interactions and breaks the mental model campaign managers bring from every other tool they use.
- **Fix**: Add `data-sort="views_gain"` attributes to sortable `<th>` elements. On click, update the sort state and call the existing `renderPerformers()` / `renderTrends()` functions. Replace the Sort + Order dropdowns with an inline `↑↓` indicator on the active column header.
- **Suggested command**: `$impeccable polish`

**[P1] Anomaly types and threshold have no explanation**
- **Why it matters**: "Minus", "Spike", "Stagnan" are algorithm-specific terms. A campaign manager seeing "Spike" for the first time doesn't know if it's good or bad. The threshold input says "2" with no unit — days? percent? — until you read the adjacent label carefully.
- **Fix**: Add a `<small class="text-muted">hari tanpa log scraping</small>` below the threshold input. Add a `data-bs-toggle="tooltip"` to the "Alasan" column header and the "Anomali" tab link explaining each type in 1 line each.
- **Suggested command**: `$impeccable clarify`

**[P2] Row color is the only severity signal in Missing tab**
- **Why it matters**: `table-danger` and `table-warning` row backgrounds are the sole severity indicator. WCAG AA requires a secondary cue beyond color. Screen readers announce no severity information.
- **Fix**: In the "Hari Tanpa Log" cell, append a severity badge: `<span class="badge bg-danger ms-1">Kritis</span>` for >3 days / never, `<span class="badge bg-warning text-dark ms-1">Waspada</span>` for 2–3 days. Keep row colors as reinforcement, not primary signal.
- **Suggested command**: `$impeccable audit`

**[P2] KPI error state shows `!` — uninformative**
- **Why it matters**: On API failure, all KPI cells get `.text('!')` and `.addClass('text-danger')`. Users don't know if this means "data loading" or "server error" or "no data." The exclamation mark pattern is not a recognized convention for API errors.
- **Fix**: Replace the `.fail()` handler to show `<span title="Gagal memuat"><i class="bi bi-dash"></i></span>` in KPI cells, and render a single dismissible `alert-warning` above the KPI strip: "Gagal memuat ringkasan. Periksa koneksi dan coba lagi."
- **Suggested command**: `$impeccable harden`

**[P2] Anomalies table: 10 columns, most actionable columns buried**
- **Why it matters**: "Alasan" and "Detail" — the columns users act on — are columns 9 and 10. Reaching them requires scrolling past Views Before, Views After, and Views +/- which can be merged. 
- **Fix**: Merge the three views columns into one "Perubahan Views" column: `"23K → 18K (−5K)"`. Reorder: Creator → Platform → Tanggal → Perubahan Views → Alasan → Link → Detail. Saves 2 columns, moves decision-relevant data left.
- **Suggested command**: `$impeccable distill`

**[P3] Empty states give no diagnostic context**
- **Why it matters**: "Tidak ada data" in Performers/Trends is opaque. Users don't know if it's a date range with no postings, or no enrollments, or an API issue.
- **Fix**: Extend empty state text: "Tidak ada data posting dalam periode ini. Coba perluas rentang tanggal." Pass a `reason` field from the API for context-specific messages.
- **Suggested command**: `$impeccable harden`

## Persona Red Flags

**Alex (Power User)**:
- No click-to-sort on any of the 4 tables — must use dropdown, costs 2-3 extra clicks per sort change
- No export button — campaign reporting requires sharing data with team members
- Threshold input requires type + click OK rather than being reactive on `change`
- No keyboard shortcut to switch tabs; Alt+1–4 would be natural for this audience
- `goToTrends()` cross-tab jump is a genuine win — preserve and extend this pattern

**Sam (Accessibility-Dependent)**:
- Platform `<img>` icons: use `title` attribute but no `alt` — screen readers use `alt`, not `title`; these icons convey platform identity and need `alt="Tiktok"` etc.
- Canvas sparklines (Chart.js bar charts): no `aria-label` or `role="img"` — invisible to screen readers, total data blackout for trend information
- `table-danger`/`table-warning` row highlighting: color-only severity in Missing tab — see P2 fix above
- Status badges convey text (e.g. "Posted Content") — these are accessible ✓

**Dian (Campaign Manager — project-specific)**:
- No export of Missing Creator list — team needs to distribute follow-up tasks outside the tool
- Missing tab identifies the problem but provides no next action (no "notify" shortcut, no notes field)
- Global date range applies to all tabs — comparing anomalies last week vs performers this month requires tab-switching and re-filtering

## Minor Observations

- `fs-12` used in Performers tab toolbar (`label class="fs-12 text-muted"`) but not defined in the inline `<style>` block — only `fs-10`, `fs-11`, `fs-14`, `fs-24` are declared. Likely fallback to Bootstrap's `.fs-*` which uses a different rem scale.
- `#an-kpi-top-views` always gets class `text-success` regardless of direction — if top creator has negative views gain, this shows green incorrectly.
- `perf-limit` option has mixed value types: `value="10"`, `value="bottom10"`, `value="all"` — string vs numeric inconsistency in the conditional logic.
- Date range filter has no "Reset to current month" shortcut — users must manually retype both fields to reset.
- Sparklines use `animation: false` globally. This is intentional for performance but means no `prefers-reduced-motion` override is needed. Worth noting if animation is ever added.

## Questions to Consider

- "If a campaign manager opens this page with 15 missing creators, what is their next action? The page surfaces the problem but doesn't support the action — is that in scope?"
- "The 4 KPI metrics have equal visual weight. Which matters most during an active campaign? Should Missing Data be visually dominant over Avg Views/Hari?"
- "Column-header sorting or dropdown sorting — these are incompatible patterns. If you had to converge on one for all 4 tables, which ships first and which gets dropped?"
