# Tasks — retire the standalone Motions nav, make it a Decisions filtered view

## Phase 0: Deduplication Check (ADR-012)

Document what already exists so this change REMOVES a redundant nav surface rather than
adding a new entity/page/route.

- [x] **Motion is already a Decision subtype (ADR-005).** `decision-management` + the
  `unify-decision-supertype` change made `Decision` the universal supertype discriminated by
  `decisionType`, which already includes the `motion` value. → No Motion entity/schema exists
  to remove; this change introduces NO schema and NO new `decisionType`.
- [x] **The "filtered view of Decisions" already exists as a page.** `src/manifest.json` page
  id `Motions` (route `/motions`, type `index`) already sets
  `config.filter.decisionType="motion"` over `register=decidesk, schema=decision`. → The
  filtered view is the EXISTING page; this change adds NO new page — it links the existing
  page from Decisions and removes the duplicate top-level menu leaf.
- [x] **The demote-not-delete mechanism already exists in the repo.** The archived
  `ia-six-item-nav` change removed Minutes / Workspaces / Engagement from the `menu` array
  while retaining their pages + routes (REQ-NAV-002 / REQ-NAV-003 "routes remain reachable").
  → This change REUSES that exact pattern for `Motions`; it adds NO new menu mechanism.
- [x] **Confirmed the exact surface being removed.** `grep` of `src/manifest.json` shows
  exactly one top-level Motions menu leaf: `menu[]` entry `{ "id": "Motions", "label":
  "Motions", "icon": "icon-comment", "route": "Motions", "order": 50 }`. The page id `Motions`
  (`/motions`), `MotionDetail` (`/motions/:id`), and `MotionIntegrations`
  (`/motions/:id/integrations`) are separate `pages[]` entries and are NOT removed.
- [x] **Conclusion:** dedup clean. Net change = remove one `menu` entry + add one declarative
  `decisionType=motion` quick-filter / sub-view link on the `Decisions` index, both in
  `src/manifest.json`. No new schema, no new page, no new route, no PHP, no migration, no
  parallel concept (ADR-005 / ADR-012 honoured).

## Phase 1: Retire the top-level Motions menu leaf (ADR-037, REQ-RMN-001)

- [x] In `src/manifest.json` `menu`, remove the `Motions` menu object (`id: "Motions"`,
  `route: "Motions"`, `order: 50`) — the demote-not-delete pattern proven by `ia-six-item-nav`
  (drop the menu item only).
- [x] Confirm the `menu` array no longer contains any entry whose `route` is `Motions`.
- [x] Leave the menu `order` values of the remaining items unchanged (gaps are harmless; no
  reflow required).

## Phase 2: Keep the Motions page + routes reachable (REQ-RMN-002)

- [x] Verify the `Motions` page (`pages[]` id `Motions`, route `/motions`, type `index`,
  `config.filter.decisionType="motion"`) remains present and unchanged.
- [x] Verify `MotionDetail` (`/motions/:id`) and `MotionIntegrations`
  (`/motions/:id/integrations`) pages remain present so the Motions-index `view` /
  `Discussion` actions and deep links keep resolving.
- [x] Do NOT touch `appinfo/routes.php` (manifest-driven nav; no server route change needed).

## Phase 3: Add the Decisions → Motions filtered view (ADR-037, REQ-RMN-003)

- [x] On the `Decisions` index page (`pages[]` id `Decisions`, route `/decisions`), add a
  declarative `decisionType=motion` quick-filter / sub-view link (e.g. a `config.quickFilters`
  entry or `config.subViews` link, label "Motions") that navigates to the retained `Motions`
  page (`/motions`).
- [x] Ensure the filter targets the STORED `decisionType` field (server-resolvable per the
  manifest's `_inForceFilterNote`) — no client-side derivation.
- [x] Add the "Motions" filtered-view label to the en/nl l10n source so it is translatable
  (English key, Dutch value) — i18n keys are English source strings.

## Phase 4: Verify

- [x] Manifest build: rebuild the bundle and confirm `src/manifest.json` parses (no orphaned
  menu reference; the `Motions` route is no longer in `menu` but the page/route still exist).
- [x] e2e: the top nav no longer shows a standalone "Motions" item; the `/motions` deep link
  still renders the Motions index; the Decisions surface exposes a Motions filtered view that
  reaches the Motions page.
- [x] `openspec validate decidesk-retire-motions-nav --strict` passes.
