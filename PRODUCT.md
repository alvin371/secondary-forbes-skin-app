# Product

## Register

product

## Users

Internal marketing and campaign operations team at Forbes Skin. They manage influencer (KOL) endorse campaigns day-to-day: assigning creators, monitoring scraping progress, flagging performance gaps. Power users fluent in campaign data; not casual or first-time. Context: on a laptop, mid-campaign, checking a dashboard to identify problems before they escalate.

## Product Purpose

An internal analytics and campaign management tool for tracking influencer endorsement campaigns. Surfaces four data domains: missing scraping data (creators who haven't logged views), performer rankings (top/bottom by views, engagement, CPM), daily view trends per creator (sparkline history), and anomaly detection (spikes, drops, stagnant periods). Success means a campaign manager can open this page and immediately know which creators need attention, who is overperforming, and where the data has gaps.

## Brand Personality

Data-rich, functional, uncluttered. Information density is a feature, not a flaw. Speed and directness over decoration. The tool should disappear into the task.

## Anti-references

- Bloated enterprise dashboards (SAP, Salesforce): heavy chrome, nested menus, slow visual hierarchy
- Generic SaaS analytics (Datadog, Grafana): dark-mode overload, too much chrome per panel, chart-first layout
- Playful consumer apps: illustrations, confetti, oversized empty states, rounded pill components
- Unstyled Bootstrap defaults: gray table striping, stock `.btn-primary`, no spatial hierarchy

## Design Principles

1. **Information first** — data is the product; UI chrome earns its space or gets cut
2. **Density without noise** — pack tables tightly but use spacing and weight to separate signal from context
3. **Status at a glance** — every row, card, and badge should communicate state without requiring the user to read prose
4. **Predictable vocabulary** — same color means same thing everywhere; no reuse of semantic tokens for decoration
5. **Efficiency over delight** — no motion that doesn't convey state; no animation longer than 150ms on data tables

## Accessibility & Inclusion

WCAG AA minimum. Color cannot be the sole indicator of status (red/green must have secondary cue: icon or label). No specific reduced-motion requirements stated; respect `prefers-reduced-motion` for sparkline animations.
