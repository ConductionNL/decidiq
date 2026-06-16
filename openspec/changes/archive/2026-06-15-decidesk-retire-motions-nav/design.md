# Design — retire the standalone Motions nav, make it a filtered view of Decisions

## Goal

Align the top-level navigation with the already-shipped Decision-supertype model (ADR-005):
stop presenting **Motions** as a sibling of **Decisions** and instead surface Motions as a
**filtered view of Decisions** (`decisionType=motion`), while keeping the Motions page and its
routes reachable for deep links. This is a declarative `src/manifest.json` change (ADR-037) —
no schema, no PHP, no migration.

## What already exists (reused — see Phase 0)

| Existing thing | Reused for |
|---|---|
| `Decision` supertype + `decisionType` discriminator (ADR-005, `decision-management`) | Motion *is* a Decision with `decisionType=motion`; no Motion entity exists to remove |
| `Motions` index page (`src/manifest.json` id `Motions`, route `/motions`, type `index`, `config.filter.decisionType="motion"`) | This IS the "filtered view of Decisions" — kept as the retained, deep-linkable page |
| `MotionDetail` (`/motions/:id`) + `MotionIntegrations` (`/motions/:id/integrations`) + motion tabs (amendments / voting-order / votes / voting-round) | Motion-specific detail surfaces — kept reachable; the Motions index actions navigate to them |
| `ia-six-item-nav` demote-not-delete precedent (Minutes / Workspaces / Engagement removed from the menu, routes retained) | The exact mechanism reused to retire the `Motions` menu item while keeping the route |
| The `Decisions` index page (`src/manifest.json` id `Decisions`, route `/decisions`, type `index`) | The surface the Motions filtered-view is reached *from* |

## The exact thing being retired

- **Menu entry** — `src/manifest.json` `menu[]` object: `{ "id": "Motions", "label":
  "Motions", "icon": "icon-comment", "route": "Motions", "order": 50 }`. This is the only
  top-level Motions nav leaf and the sole thing this change removes.
- **NOT touched:** the `Motions` **page** (id `Motions`, route `/motions`), the
  `MotionDetail` / `MotionIntegrations` pages, and the `/motions*` routes — all retained.

## Key decisions

### D1 — Demote-not-delete (reuse the `ia-six-item-nav` pattern, ADR-037)

decidesk's manifest expresses nav as a declarative `menu` array in `src/manifest.json` (there
is no separate `menu-layout.json` `removals` file in this repo — the established repo pattern,
proven by `ia-six-item-nav`, is to **remove the menu-array entry directly** while leaving the
corresponding `pages[]` entry and its route registered). We follow that pattern verbatim:
delete the `Motions` `menu` object; leave the `Motions` page and `/motions` route in place.
This keeps deep links, bookmarks, and the Motions-index action navigations
(`route: "MotionDetail"`, `route: "MotionIntegrations"`) working — identical to how
`ia-six-item-nav` retained `/minutes`, `/workspaces`, `/engagement`.

### D2 — Reach Motions as a filtered view *from* Decisions

To preserve discoverability after removing the sibling item, the `Decisions` index surfaces a
**Motions filtered view** scoped to `decisionType=motion`. Two declarative options exist in
the manifest vocabulary; we pick the one that requires no new page:

- **Chosen:** add a declarative **quick-filter / sub-view link** on the `Decisions` index
  (`config.quickFilters` entry — or an equivalent `config.subViews` link — keyed
  `decisionType: "motion"`, label "Motions") that navigates to the *retained* `Motions` page
  (`/motions`). The `Motions` page already carries `filter.decisionType="motion"`, so the
  filtered view is the existing page reached through Decisions — no duplicate page, no schema
  query change.
- **Rejected:** a brand-new "Decisions (motions)" page. That would duplicate the existing
  `Motions` page and violate ADR-012 dedup; the existing filtered page already does the job.

Per the existing `_inForceFilterNote` in the manifest, a static `filter` / `quickFilters` map
merges into the OpenRegister fetch and works over **stored** fields — `decisionType` IS a
stored field, so this filter is server-resolvable (unlike the client-derived `effectiveStatus`
case documented in that note). No client-side derivation is needed for the motion filter.

### D3 — No schema / no PHP / no migration

`decisionType=motion` already exists on the `Decision` supertype; the Motions page already
filters on it. The change touches only `src/manifest.json` (`menu` deletion + `Decisions`
quick-filter/sub-view link). No `decision` schema edit, no controller/service/route change,
no data migration — existing motions remain Decisions with `decisionType=motion`, unmoved.

## Risks & mitigations

- **Risk:** a user who bookmarked the Motions *menu item* loses the top-level entry.
  **Mitigation:** the `/motions` route and page are retained (deep link still resolves) and
  Motions is now reachable as a Decisions filtered view — discoverability is preserved, just
  relocated under its parent concept.
- **Risk:** the Motions-index actions (`MotionDetail`, `MotionIntegrations`) break.
  **Mitigation:** those routes/pages are explicitly out of scope and remain registered; only
  the `menu` entry is removed.
- **Risk:** the filtered-view link silently filters on a non-stored field.
  **Mitigation:** `decisionType` is a stored schema field (per the manifest's own
  `_inForceFilterNote`), so the `decisionType=motion` filter is server-resolvable.
