# Proposal: ia-six-item-nav

## Summary

Restructure the Decidesk top-level navigation to ADR-004's six-item,
mode-aware information architecture. This is C7, the final Cycle-1 change on
`refactor/decidesk-decision-model`. After C1 (`unify-decision-supertype`)
collapsed the decision vocabulary and C3 (`retire-board-portal`) removed the
parallel corporate nav, the dual vocabulary is gone; C7 delivers the clean
six-item structure and introduces the `organisatie-modus` mechanism so the
same six items relabel per tenant mode (gov / corp / assoc / ops / citizen)
without branching the nav.

## Motivation

The current `src/manifest.json` `menu` carries **eight** main items
(Dashboard, Minutes, Decisions, Action items, Motions, Meetings, Workspaces,
Engagement) plus Documentation / Settings / Features-roadmap in the footer and
settings sections. ADR-004 sets a hard six-item ceiling and a fixed placement
rubric; the current nav exceeds it and surfaces operator-only and demoted
surfaces (Minutes, Workspaces, Engagement) at the top level. ADR-006 closes
with: *"ADR-004's six-item nav is realized (Cycle 1, change `ia-six-item-nav`):
the parallel Boards / Board meetings / Resolutions items disappear, replaced by
mode-aware labels on the universal six."* The board items are already gone after
C3; what remains is to compress the eight items to the canonical six, promote
the **GovernanceBodies** page (currently a page but NOT in the menu) into the
nav as **Bodies**, demote Minutes / Workspaces / Engagement, and add the
`organisatie-modus` label-adaptation mechanism that ADR-004 Rule 1 and ADR-006
mandate. Now is the moment because the vocabulary churn of C1–C3 is settled —
doing the IA restructure on top of a stable entity model avoids reworking it
twice.

## Affected Projects

- [x] Project: `decidesk` — manifest menu restructure (8 → 6 working items + Dashboard landing), GovernanceBodies promoted to a Bodies menu item, Minutes/Workspaces/Engagement demoted, `organisatie-modus` admin setting + a declarative label-map scaffold, and label-resolution wiring in the app shell's `translateForApp`.

## Scope

### In Scope

- Rewrite `src/manifest.json` `menu` to the canonical six working items
  (Meetings, Decisions, Action items, Motions, Bodies, Beheer) plus the
  Dashboard landing item and the existing footer/settings entries.
- Promote the existing `GovernanceBodies` page into the menu as **Bodies**.
- Demote **Minutes** (already a tab under MeetingDetail via `MeetingMinutesTab`),
  **Workspaces** (folded under Bodies/Beheer), and **Engagement** (folded under
  Beheer) — remove them from the top menu. The pages/routes stay reachable.
- Add an `organisatie_modus` app-config setting (enum gov / corp / assoc / ops /
  citizen; default `gov`) read/written through `SettingsService`, exposed in the
  Decidesk admin settings UI.
- Add a declarative, data-driven **label map** keyed by mode (no per-persona nav
  branch) and wire per-mode relabeling for the **Bodies** item at minimum,
  applied at the single `translateForApp` chokepoint in `src/App.vue`.

### Out of Scope

- Full runtime relabeling of every menu item, tab, and in-page noun across all
  five modes — C7 wires the Bodies item and ships the scaffold + the canonical
  Dutch/English labels; broader per-mode relabeling is **deferred** (see Open
  Questions and the design's label-map table, which records the full target map
  for a follow-up change).
- Deleting the Dashboard, Minutes, Workspaces, or Engagement pages/routes — they
  are demoted in the nav, not removed.
- Reworking the Beheer drawer's internal contents (schemas, integrations, MCP
  tools) — C7 only groups Settings + Engagement/analytics + admin tools under the
  Beheer entry; their detailed reorganisation is a later change.

## Approach

The nav is fully data-driven: `src/manifest.json` `menu` is the source of truth,
merged with `src/manifest.d/*.json` fragments in `main.js`, and rendered by
`CnAppRoot`/`CnAppNav` (nc-vue). Every menu `label` is run through
`translateForApp(key)` in `src/App.vue`, which today is a thin
`ncT('decidesk', key)` wrapper — the single chokepoint for label adaptation. C7
restructures the `menu` array to the six items + Dashboard, and changes
`translateForApp` to first resolve the canonical label through a mode-keyed label
map (driven by the `organisatie_modus` setting, read from the settings store)
before calling `t()`. The label map is a static JS object keyed by
`mode → canonicalLabel → modeLabel`, so adding a mode or relabeling an item is a
data edit, never a nav branch. The setting itself is a new app-config key on
`SettingsService::CONFIG_KEYS` with a `gov` default, surfaced in the admin
settings UI.

## New Dependencies

None.

## Impact

- `src/manifest.json` — `menu` array rewritten; no schema/data change.
- `src/App.vue` — `translateForApp` gains label-map resolution; reads
  `organisatie_modus` from the settings store.
- `src/store/` (settings store) — exposes `organisatie_modus`.
- `lib/Service/SettingsService.php` — `organisatie_modus` added to `CONFIG_KEYS`
  with a `gov` default in `getSettings()`.
- A new declarative label map module under `src/` (e.g.
  `src/config/modeLabels.js`).
- No backend routes, no controllers, no database, no register schema change.

## Cross-Project Dependencies

None. Self-contained within decidesk. Depends on C1 (`unify-decision-supertype`)
and C3 (`retire-board-portal`) being applied first on the same branch — both are
prior Cycle-1 changes; C7 assumes the board nav items and the dual decision
vocabulary are already gone.

## Risks

### Risk 1: Demoted surfaces become undiscoverable

**Severity:** Medium — **Mitigation:** Minutes is already reachable as a tab in
MeetingDetail (`MeetingMinutesTab`); Workspaces and Engagement keep their routes
and are linked from Bodies and Beheer respectively. The catch-all route is
unchanged, so any bookmarked deep link still resolves. The demotions remove menu
rows, not pages.

### Risk 2: Label map drifts from t() translation keys

**Severity:** Low — **Mitigation:** The label map resolves the canonical label to
a mode-specific **key**, which is then passed to `t()`; both the canonical and
the mode-specific strings live in the standard l10n source files, so external
translators see them. The map only redirects which key is looked up; it never
hardcodes a display string.

### Risk 3: organisatie_modus default surprises existing tenants

**Severity:** Low — **Mitigation:** Default is `gov`, which keeps the current
Dutch governance labels (Vergaderingen, Besluiten, Moties, Acties), so existing
installs see no visible change until an admin opts into another mode.

## Rollback Strategy

Pure config + frontend change with no migration. Revert the commit: restore the
eight-item `menu` in `src/manifest.json`, restore the thin `translateForApp`,
remove the `modeLabels.js` module, and drop `organisatie_modus` from
`SettingsService::CONFIG_KEYS`. The app-config key (if written) is inert once the
code no longer reads it; no data cleanup required.

## Open Questions

- **Dashboard as a distinct top item vs. folded into Meetings.** ADR-004 says
  "Dashboard is the landing page of Vergaderingen, not a separate top-level."
  Decidesk has a rich dashboard at route `/`. C7 provisionally KEEPS Dashboard as
  the home/landing item at the top of the nav (above the six working items) and
  does NOT delete it. Whether to ultimately fold it into Meetings is deferred to
  product (recorded in DEFERRED_QUESTIONS).
- **Depth of per-mode relabeling for C7.** C7 ships the structure + canonical
  Dutch/English labels + the `organisatie_modus` setting + the label-map scaffold,
  with per-mode relabeling wired for the **Bodies** item at minimum. Whether to
  expand relabeling to every menu item / tab / in-page noun in this change or a
  follow-up is deferred (recorded in DEFERRED_QUESTIONS).
