# app-navigation Specification

**Status:** proposed
**Scope:** decidesk
**OpenSpec changes:**
- decidesk-retire-motions-nav

## Purpose

Retire the leftover standalone **Motions** top-level navigation leaf that survives from before
the Decision-supertype refactor, and surface Motions as a **filtered view of Decisions**
(`decisionType=motion`) instead. After ADR-005 made `Decision` the universal supertype
discriminated by `decisionType`, a Motion is simply a Decision with `decisionType=motion`;
presenting Motions as a *sibling* of Decisions in the top nav contradicts that unified model.
This change removes the `Motions` menu item (reusing the `ia-six-item-nav` demote-not-delete
pattern) while keeping the `Motions` page and `/motions` route reachable for deep links, and
adds a declarative Decisions quick-filter / sub-view that reaches the retained Motions page.
It is a navigation / IA change only — no schema, controller, route-table, or data change.

## ADDED Requirements

### Requirement: REQ-RMN-001 — Retire the standalone Motions top-level menu item

The system SHALL remove the standalone **Motions** top-level navigation item from the decidesk
`src/manifest.json` `menu` so that Motions is no longer presented as a sibling of Decisions.
The removed entry is the `menu` object `{ id: "Motions", label: "Motions", route: "Motions",
order: 50 }`. The removal SHALL follow the repo's established demote-not-delete pattern (the
`ia-six-item-nav` precedent: delete the `menu` array entry only, leaving the corresponding
page and route registered, per the ADR-037 declarative-manifest conventions). No other
top-level menu item SHALL be removed or reordered by this change.

#### Scenario: Top navigation no longer shows a standalone Motions item

- GIVEN the decidesk app is in the ready state
- WHEN the top-level navigation renders from the manifest `menu`
- THEN no menu item whose route is `Motions` is shown
- AND the Dashboard, Meetings, Decisions, Action items, and Bodies items remain present

#### Scenario: The Motions menu entry is removed from the manifest, not merely hidden

- GIVEN `src/manifest.json`
- WHEN the `menu` array is inspected after this change
- THEN it contains no entry with `id: "Motions"` / `route: "Motions"`

---

### Requirement: REQ-RMN-002 — Keep the Motions page and routes reachable for deep links

The system SHALL keep the `Motions` page (`pages[]` id `Motions`, route `/motions`, type
`index`, `config.filter.decisionType = "motion"`) and the motion detail surfaces
(`MotionDetail` at `/motions/:id` and `MotionIntegrations` at `/motions/:id/integrations`)
registered and routable after the menu item is retired. Removing the top-level menu item
SHALL NOT remove or rename any page or route, so deep links, bookmarks, and the Motions-index
action navigations (the `view` action routing to `MotionDetail` and the `Discussion` action
routing to `MotionIntegrations`) continue to resolve — exactly the demote-not-delete behaviour
established by `ia-six-item-nav`.

#### Scenario: The Motions deep link still renders after the menu item is retired

- GIVEN the standalone Motions menu item has been removed
- WHEN a user navigates directly to `/motions`
- THEN the Motions index page renders, filtered to `decisionType = motion`

#### Scenario: Motion detail deep link still resolves

- GIVEN a motion with id `abc-123`
- WHEN a user navigates directly to `/motions/abc-123`
- THEN the `MotionDetail` page renders for that motion

#### Scenario: Motions-index actions still navigate

- GIVEN the user is on the retained `/motions` index page
- WHEN the user triggers the row `view` action
- THEN the app navigates to the `MotionDetail` route for that row

---

### Requirement: REQ-RMN-003 — Surface Motions as a filtered view of Decisions

The system SHALL surface Motions as a filtered view of Decisions reachable from the
`Decisions` surface, scoped to `decisionType = motion`, so that Motions is discoverable under
its parent concept rather than as a sibling top-level item. The filtered view SHALL be
declared declaratively in `src/manifest.json` (a quick-filter / sub-view link on the
`Decisions` index, per ADR-037) and SHALL resolve to the retained `Motions` page (`/motions`),
filtering over the STORED `decisionType` field (no client-side derivation). The change SHALL
NOT introduce a new page or duplicate the existing Motions index, and SHALL NOT alter the
`decision` schema or the `decisionType` enum (Motion is already a Decision subtype per
ADR-005).

#### Scenario: A Motions filtered view is reachable from Decisions

- GIVEN the user is on the `Decisions` surface
- WHEN the user selects the Motions filtered view (`decisionType = motion`)
- THEN the app shows Decisions filtered to `decisionType = motion`, resolving to the retained Motions page

#### Scenario: The filtered view reuses the existing page, not a new one

- GIVEN `src/manifest.json` after this change
- WHEN the pages and menu are inspected
- THEN no new Motions page is added — the Decisions filtered view links to the existing `Motions` page (`/motions`)

#### Scenario: No schema change accompanies the nav change

- GIVEN the change is applied
- WHEN the `decision` schema and its `decisionType` enum are inspected
- THEN they are unchanged — Motions remain Decisions with `decisionType = motion`

## Non-Functional Requirements

- **Accessibility:** The remaining navigation items and the Decisions filtered-view control
  MUST keep nc-vue `NcAppNavigationItem` / filter active-state, focus, and screen-reader
  semantics (WCAG 2.1 AA); removing the Motions item changes only which items render, not the
  markup contract of the rest.
- **Internationalization:** Dutch and English MUST be supported; the "Motions" filtered-view
  label MUST exist in the en/nl l10n source (English key, Dutch value) so external translators
  can contribute it.
- **Performance:** The Decisions filtered view MUST resolve via the existing stored-field
  `decisionType` filter merged into the OpenRegister fetch — no extra network round-trip and
  no client-side derivation.

## Acceptance Criteria

- [ ] `src/manifest.json` `menu` contains no entry with `id: "Motions"` / `route: "Motions"`
- [ ] The `Motions` page (`/motions`), `MotionDetail` (`/motions/:id`), and `MotionIntegrations`
      (`/motions/:id/integrations`) remain registered and reachable by deep link
- [ ] The `Decisions` index exposes a declarative `decisionType=motion` quick-filter / sub-view
      that links to the retained `Motions` page
- [ ] No change is made to the `decision` schema, the `decisionType` enum, `appinfo/routes.php`,
      or any controller/service
- [ ] The "Motions" filtered-view label is present in the en/nl l10n source

## Notes

- ADR-005 (Decision as the universal supertype discriminated by `decisionType`): Motion =
  Decision with `decisionType=motion`.
- ADR-037 (declarative manifest / menu-layout conventions): nav is expressed as the
  `src/manifest.json` `menu` array; this repo's demote-not-delete pattern removes the menu
  entry while retaining the page + route (no separate `menu-layout.json` `removals` file is
  used in decidesk — the `ia-six-item-nav` change established the in-manifest pattern).
- Precedent: the archived `ia-six-item-nav` change demoted Minutes / Workspaces / Engagement
  from the top menu while keeping their routes reachable (REQ-NAV-002 / REQ-NAV-003); this
  change applies the identical mechanism to `Motions`.
