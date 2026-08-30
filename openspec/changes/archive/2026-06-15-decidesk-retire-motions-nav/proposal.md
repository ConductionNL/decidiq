# Proposal: decidesk-retire-motions-nav

kind: navigation / information-architecture (retire a leftover top-level nav leaf that
contradicts the unified Decision model) — cites ADR-005 (Decision as the universal
supertype discriminated by `decisionType`), and the hydra ADR-037 manifest / menu-layout
conventions (declarative `src/manifest.json` `menu` + `pages`). Follows the precedent set by
the archived `ia-six-item-nav` change (demote-not-delete: drop the menu item, keep the route
reachable for deep links).

## Summary

Decidesk's decision-model refactor (ADR-005, already shipped via `unify-decision-supertype`
and `decision-management`) made **`Decision`** the canonical *supertype*, discriminated by a
`decisionType` field (`motion` / `amendment` / `resolution` / `contract` / …). A **Motion is
simply a Decision with `decisionType=motion`** — it is not a separate entity. The data model
already reflects this: the `Motions` index page (`src/manifest.json` page id `Motions`,
route `/motions`, type `index`) is just a filtered view of the `decision` schema
(`config.filter.decisionType = "motion"`).

The **top-level navigation still carries a standalone "Motions" menu item** (menu id
`Motions`, label `Motions`, route `Motions`, order 50) as a **sibling of "Decisions"** (menu
id `Decisions`, route `Decisions`, order 30). That sibling placement is a leftover from the
pre-refactor era when Motion was modelled as its own concept. Presenting Motions and
Decisions as peers contradicts the unified supertype model: it implies Motions are a separate
category of object rather than one `decisionType` of Decision.

This change **retires the standalone "Motions" top-level menu leaf** and makes Motions a
**filtered view of Decisions** reachable *from* the Decisions surface (a quick-filter /
sub-view scoped to `decisionType=motion`). It is a **nav / IA change only** — no schema
change (Motion is already a Decision subtype), no controller change, no data migration. The
existing **`Motions` page and its `/motions` route stay registered and routable** so deep
links, bookmarks, action-handlers (the `MotionDetail` / `MotionIntegrations` navigations from
the Motions index actions), and the motion-specific detail/amendment/voting tabs all keep
working — exactly the demote-not-delete pattern proven by `ia-six-item-nav`.

## Motivation

- **Consistency with ADR-005.** After the Decision-supertype refactor, the top-level nav
  should reflect that Decisions are the single category of governance decision objects, with
  Motions being one `decisionType` of them. A sibling "Motions" item re-introduces, at the IA
  layer, the very parallel-concept split the refactor removed.
- **Reachability is preserved.** The Motions page is genuinely useful (motion-specific
  columns `motionType`/`proposer`, the amendments / voting-order / votes / voting-round tabs).
  Retiring the *menu item* must not break that surface; it remains reachable as a filtered
  view of Decisions and by deep link.
- **No model churn.** Because `decisionType=motion` already exists and the `Motions` page
  already filters on it, this is the lowest-risk way to align nav with model: a manifest
  `menu` edit plus a declarative Decisions quick-filter/sub-view — no PHP, no schema, no data.

## Affected Projects

- [x] Project: `decidesk` — `src/manifest.json` `menu` edit (remove the `Motions` top-level
  menu item) and `pages` edit (add a `decisionType=motion` quick-filter / linked sub-view on
  the `Decisions` index that reaches the retained `Motions` page); the `Motions` page +
  `/motions` route and the `MotionDetail` / `MotionIntegrations` routes stay registered.

## Scope

### In Scope

- Remove the top-level **`Motions`** menu entry (menu id `Motions`, route `Motions`, order
  50) from `src/manifest.json` `menu`, using the repo's established demote-not-delete pattern
  (the `ia-six-item-nav` precedent: drop the menu item, keep the page + route).
- Add a **Decisions → Motions filtered view** reachable from the `Decisions` surface — a
  declarative quick-filter / sub-view scoped to `decisionType=motion`, so a user navigates to
  Motions *through* Decisions rather than via a sibling top-level item. The filtered view
  resolves to (or reuses) the retained `Motions` page (`/motions`, already
  `filter.decisionType=motion`).
- Keep the **`Motions` page**, the **`/motions` route**, and the motion detail surfaces
  (`MotionDetail` `/motions/:id`, `MotionIntegrations` `/motions/:id/integrations`)
  registered and reachable by deep link / bookmark / action-handler.

### Out of Scope

- Any change to the `decision` schema or the `decisionType` enum (Motion is already a
  Decision subtype — no schema delta).
- Any controller, service, route-table (`appinfo/routes.php`), or data migration change.
- Renaming or removing the `Motions` page, its columns, or its motion-specific tabs.
- Broader nav restructuring beyond retiring this single leftover leaf (the six-item IA was
  already delivered by `ia-six-item-nav`).

## Deduplication rationale (ADR-012)

This change **removes** a redundant nav surface; it does NOT add a new entity, schema, page,
or capability. Phase 0 (see `tasks.md`) records that: (a) `decisionType=motion` already
exists on the `Decision` supertype (ADR-005) so no Motion entity/schema is introduced; (b)
the `Motions` index page already filters on `decisionType=motion` so the "filtered view of
Decisions" is the *existing* page, not a new one; (c) the demote-not-delete mechanism already
exists in the repo (the `ia-six-item-nav` change demoted Minutes / Workspaces / Engagement by
removing their menu items while retaining their routes) — this change reuses that exact
pattern for `Motions`. Net change = one `menu` deletion + one declarative Decisions
quick-filter/sub-view link in `src/manifest.json`. No new schema, no new page, no new route,
no parallel concept.
