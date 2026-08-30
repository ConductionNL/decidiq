# Design: ia-six-item-nav

## Architecture Overview

Decidesk's nav is fully data-driven. `src/manifest.json` `menu` is the source of
truth; `main.js` merges it with `src/manifest.d/*.json` fragments (ADR-037) into
`mergedManifest`, passes it to `CnAppRoot`, and `CnAppNav` (nc-vue) renders one
`NcAppNavigationItem` per `menu` entry. Every entry's `label` is run through the
`translate` function passed to `CnAppRoot` — in decidesk that is
`translateForApp(key)` in `src/App.vue`, today a thin `ncT('decidesk', key)`
wrapper. This single chokepoint is where mode-aware label adaptation is applied.

C7 makes two structural moves:

1. **Restructure `menu`** from eight working items to the canonical six
   (+ Dashboard landing + footer/settings entries).
2. **Introduce the `organisatie-modus` mechanism**: an `organisatie_modus`
   app-config setting (default `gov`), a declarative `src/config/modeLabels.js`
   label map keyed by `mode → canonicalLabel`, and label-resolution logic in
   `translateForApp` that redirects a canonical label to its mode-specific key
   before calling `t()`.

```
manifest.json menu (6 items + Dashboard)
        │  merged with manifest.d/*.json
        ▼
   mergedManifest ──► CnAppRoot :translate=translateForApp ──► CnAppNav renders items
                                     │
                                     ├─ reads organisatie_modus from settings store
                                     └─ modeLabels.js: canonicalLabel → mode-specific key → t()
```

### The six-item structure (ADR-004 target)

| # | Menu id | Canonical label | gov label | corp label | ops label | route / target | order |
|---|---------|-----------------|-----------|------------|-----------|----------------|-------|
| — | Dashboard | Dashboard | Dashboard | Dashboard | Dashboard | `Dashboard` (`/`) — landing | 10 |
| 1 | Meetings | Meetings | Vergaderingen | Meetings | Meetings | `Meetings` (`/meetings`) | 20 |
| 2 | Decisions | Decisions | Besluiten | Resolutions | Decisions | `Decisions` (`/decisions`) | 30 |
| 3 | ActionItems | Action items | Acties | Action items | Action items | `ActionItems` (`/action-items`) | 40 |
| 4 | Motions | Motions | Moties | Motions | Motions | `Motions` (`/motions`) | 50 |
| 5 | Bodies | Bodies | Fracties & Organen | Board | Teams | `GovernanceBodies` (`/governance-bodies`) | 60 |
| 6 | Beheer | Beheer | Beheer | Administration | Admin | `Settings` (`/settings`) [footer/settings section] | 99 |

Beheer is the existing **Settings** entry (settings section), per ADR-004 Rule 4
(operator-only door). Engagement/analytics and admin tools are grouped under
Beheer; in C7 this means the Engagement route is reached from the Beheer surface
rather than a top menu row (no Engagement deletion). Documentation and
Features-roadmap remain as footer entries (unchanged).

### Demotion mapping

| Removed top item | New home |
|------------------|----------|
| Minutes | Already a tab in MeetingDetail (`MeetingMinutesTab`); route `/minutes` retained, removed from top menu |
| Workspaces | Folded under Bodies (Fracties); route `/workspaces` retained, removed from top menu |
| Engagement | Folded under Beheer (analytics); route `/engagement` retained, removed from top menu |

No pages or routes are deleted — only menu rows are removed. The catch-all route
and all detail routes are untouched, so deep links keep resolving.

### The organisatie-modus label-adaptation mechanism

`src/config/modeLabels.js` exports a static, declarative map. Shape:

```js
// canonical label → per-mode label key. Keys are English source strings
// (i18n keys = English, per project convention); t() resolves the displayed
// string from the standard l10n files.
export const MODE_LABELS = {
  gov:     { Bodies: 'Fracties & Organen' /* ...future items... */ },
  corp:    { Bodies: 'Board' },
  assoc:   { Bodies: 'Fracties & commissies' },
  ops:     { Bodies: 'Teams' },
  citizen: { Bodies: 'Bodies' },
}
export const DEFAULT_MODE = 'gov'
```

`translateForApp(key)` in `App.vue` becomes:

```js
translateForApp(key) {
  const mode = this.organisatieModus || DEFAULT_MODE          // from settings store
  const mapped = (MODE_LABELS[mode] && MODE_LABELS[mode][key]) || key
  return ncT('decidesk', mapped)
}
```

`this.organisatieModus` is read from the settings store (the store already
fetches `getSettings()` on init; `organisatie_modus` is added to that payload).
Because the map only **redirects which key** is passed to `t()`, both the
canonical and the mode-specific strings remain ordinary l10n entries visible to
translators — no display string is hardcoded. Adding a mode or relabeling an
item is a data edit to `MODE_LABELS`, never a nav branch (ADR-004 Rule 1,
ADR-006 §Decision mechanism 1).

**C7 depth decision (DEFERRED_QUESTION).** Fully relabeling every menu item, tab,
and in-page noun across five modes is a large surface. C7 scopes to: (a) the
six-item structure with canonical + Dutch labels now; (b) the `organisatie_modus`
setting + the `MODE_LABELS` scaffold; (c) per-mode relabeling wired for the
**Bodies** item at minimum (the item ADR-004 explicitly calls out as
mode-adapting). The map records the full target for the other items so a
follow-up change only fills in rows, not plumbing.

### Dashboard handling (DEFERRED_QUESTION)

ADR-004 says "Dashboard is the landing page of Vergaderingen, not a separate
top-level." Decidesk has a rich dashboard at route `/`. C7 provisionally KEEPS
Dashboard as the home/landing item at the top of the nav (order 10, above the six
working items) and does NOT delete it. Whether to fold it into Meetings as that
item's landing — strictly matching ADR-004 — is deferred to product. Keeping it
distinct is the lower-risk default (the dashboard is a real, used surface).

## Nextcloud Integration

- Controllers: none changed.
- Services: `lib/Service/SettingsService.php` — `organisatie_modus` added to
  `CONFIG_KEYS`; `getSettings()` returns it with a `gov` default; `updateSettings()`
  persists it (existing loop handles it).
- Mappers/Entities: none (thin client; no DB).
- Events/Hooks: none.

## Security Considerations

No security impact. `organisatie_modus` is a cosmetic label-selection setting
written via the existing `updateSettings()` path (admin-gated in the settings
UI/route exactly as the other org-config keys). It carries no secret, drives no
authorization decision, and is a UI hint only. The existing write-only
`SECRET_KEYS` handling is unaffected. No new routes are added.

## NL Design System

The nav is rendered by nc-vue `CnAppRoot`/`CnAppNav` (`NcAppNavigation` /
`NcAppNavigationItem`), which already carry NL Design tokens and WCAG-compliant
active-state/focus handling. No hardcoded colours. Icons reuse the existing
`icon-*` classes already present on the menu entries. The Bodies item reuses the
GovernanceBodies page's existing icon.

## File Structure

```
src/
  manifest.json        (modified — menu restructured to 6 items + Dashboard)
  App.vue              (modified — translateForApp resolves via MODE_LABELS)
  config/
    modeLabels.js      (new — declarative mode→canonicalLabel→label-key map)
  store/
    settings store     (modified — exposes organisatie_modus)
lib/
  Service/
    SettingsService.php (modified — organisatie_modus in CONFIG_KEYS + gov default)
```

## Trade-offs

- **Label map vs. per-persona manifest fragments.** A `manifest.d/*.json`
  fragment per mode would add parallel nav items — exactly what ADR-006 forbids.
  A single declarative map applied at the translate chokepoint keeps one nav and
  one manifest. Chosen.
- **Wire only Bodies now vs. all items.** Wiring every item across five modes in
  C7 inflates the change well past one builder session and the 20-checkbox cap.
  Shipping the scaffold + Bodies proves the mechanism end-to-end while leaving a
  data-only follow-up. Chosen; flagged as DEFERRED_QUESTION.
- **Keep Dashboard vs. fold into Meetings.** Folding strictly matches ADR-004 but
  risks hiding a heavily-used surface and entangles C7 with dashboard routing.
  Keeping it as a distinct landing item is reversible and low-risk. Chosen
  provisionally; flagged.

## Migration Plan

No database migration. Deploy = rebuild the frontend bundle with the new
`menu` + `modeLabels.js` and ship the `SettingsService` change. The
`organisatie_modus` app-config key defaults to `gov` on read, so no data backfill
is needed. Rollback = revert the commit (see proposal's Rollback Strategy).

## Open Questions

- Dashboard as a distinct top item vs. folded into Meetings (DEFERRED — product).
- Depth of per-mode relabeling for C7 vs. a follow-up (DEFERRED — wired for
  Bodies now; rest scaffolded).
- Whether Workspaces should land under Bodies or under Beheer long-term (C7 folds
  it under Bodies; route retained either way).
