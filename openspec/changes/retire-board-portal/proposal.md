# Proposal: retire-board-portal

## Summary

Retire the parallel corporate "board portal" entity set and re-express every
corporate concept as a **mode adaptation** of the universal Decidesk entities,
fully realizing ADR-006 ("one schema per concept; domain differences via mode
adaptation, never parallel entities"). This deletes 7 `board-*`/`resolution`
schemas, the `board-portal.json` manifest fragment, six bespoke `Board*` /
`Resolution*` Vue views, and cleans every dangling reference to them across the
backend (controllers, services, routes, search/dashboard, DI registrations).
Corporate demo data is re-seeded onto the universal `governance-body`,
`meeting`, and `minutes` schemas with `mode=corp` so the corporate scenario
survives the deletion.

## Motivation

ADR-006 (accepted 2026-06-14) makes ADR-004 Rule 1 ("the same six top-level
items serve all four audiences — only the labels shift via tenant mode")
binding at the **data-model** layer, not just the nav layer. The board portal
(Phase 8) violated this by shipping a complete parallel entity set — `board`,
`board-meeting`, `board-member`, `board-vote`, `board-minutes`, `board-material`,
`board-audit-log-entry`, plus `resolution` — each duplicating a universal
concept that already exists (`governance-body`, `meeting`, Person+Membership,
`vote`, `minutes`, attachment, `auditTrail`, `decision`). Parallel schemas
guarantee drift: a vote stored as both `vote` and `board-vote` will diverge.
This change removes that duplication so corporate vocabulary is achieved by
relabelling, type discriminators, and progressive disclosure on the single
universal entity set — exactly the failure mode ADR-006 forbids.

It is also a hard prerequisite for `ia-six-item-nav` (Cycle 1, C7): the parallel
"Boards / Board meetings / Resolutions" nav items must disappear before the
six-item mode-aware nav can be realized.

## Affected Projects

- [x] Project: `decidesk` — delete 7 board schemas + their seeds, the
  `board-portal.json` manifest fragment, six `Board*`/`Resolution*` Vue views;
  clean every dangling backend reference (controllers, services, routes,
  search/dashboard, DI); re-seed corporate demo data onto the universal
  `governance-body`/`meeting`/`minutes` schemas with `mode=corp`.

## Scope

### In Scope

- Delete `Board`, `BoardMember`, `BoardMeeting`, `BoardVote`, `BoardMinutes`,
  `BoardMaterial`, `BoardAuditLogEntry` from
  `lib/Settings/decidesk_register.json` `components.schemas` (deleting a schema
  also removes its `x-openregister-seeds`).
- Delete `src/manifest.d/board-portal.json` (the nav + pages fragment).
- Delete the six bespoke views `src/views/BoardList.vue`, `BoardDetail.vue`,
  `BoardMeetingList.vue`, `BoardMeetingDetail.vue`, `ResolutionList.vue`,
  `ResolutionDetail.vue` and their imports/registrations in `src/registry.js`.
- Clean every dangling reference to the deleted schemas/views/routes across the
  codebase (see design.md "Reference cleanup" — the authoritative inventory).
- Re-seed the corporate demo onto the universal entities with `mode=corp`: a
  supervisory `governance-body`, a board `meeting`, board `minutes`.

### Out of Scope

- **Resolution → Decision migration** — already done in C1
  (`unify-decision-supertype`, `decisionType=resolution`). This change only
  removes the now-redundant `Resolution` schema/views/routes.
- **board-member → Person + Membership** — already done in C2
  (`popolo-decision-makers`). This change only confirms the corporate
  Person/Membership seeds exist; no new person/membership work.
- **eIDAS signing** — promoted to a pluggable *decision method* (`signature`)
  in Cycle 2 (`decision-methods`), not built here. Left as a note only.
- **The six-item mode-aware nav itself** — delivered by `ia-six-item-nav` (C7).
  This change only removes the parallel board nav that blocks it.

## Approach

Config-first deletion followed by code cleanup and re-seed:

1. Delete the 7 board schemas (and their inline seeds) from the register JSON.
2. Delete `board-portal.json` and the six Vue views; strip their `registry.js`
   imports/registrations.
3. Walk the "Reference cleanup" inventory and remove or retarget every dangling
   reference — board routes in `appinfo/routes.php`, board DI registrations and
   the `BoardMeetingCalDavBridge` listener in `lib/AppInfo/Application.php`, the
   `resolution` entry in `DecideskSearchProvider`, board-portal dashboard
   widgets, and the board-only / board-coupled controllers and services.
4. Add `mode=corp` seeds on `governance-body`, `meeting`, `minutes` so the
   corporate demo survives the deletion.

Delivered as **one** change (ADR-032 mixed-spec, `kind: code`) per the C1/C2
supervised-local precedent — see design.md "Mixed-spec rationale".

## New Dependencies

None.

## Impact

- **Schemas**: 7 board schemas removed from the register; corporate demo
  re-seeded onto 3 universal schemas.
- **Frontend**: 6 views deleted, `registry.js` + bundled manifest shrink, the
  parallel board nav disappears.
- **Backend**: board routes removed from `appinfo/routes.php`; board controllers,
  services, lifecycle guards, listener, and DI registrations removed or
  retargeted; `DecideskSearchProvider` stops indexing the deleted `resolution`
  schema (decisions with `decisionType=resolution` remain searchable via the
  `decision` schema).
- **No orphaned data**: C1 migrated resolutions → decisions and C2 migrated
  board members → Person + Membership before this deletion (see design.md
  "Deletion safety").

## Cross-Project Dependencies

None at the app boundary. Depends on prior **in-app** cycles C1
(`unify-decision-supertype`) and C2 (`popolo-decision-makers`) having migrated
the resolution and board-member data, and unblocks C7 (`ia-six-item-nav`).

## Risks

### Risk 1: Dangling references break the app at runtime

**Severity:** High — **Mitigation:** design.md carries a thorough, grep-derived
"Reference cleanup" inventory listing every file that references a deleted
schema/view/route. The apply step works the inventory file-by-file and the
test-plan includes a regression sweep (`grep` for board slugs/components,
app boots, nav renders, search returns).

### Risk 2: Board-coupled Phase 4-6 services (eIDAS, conflict-of-interest,
audit-log, proxy-vote, governance-report, regulator-export, multilingual) are
entangled with the deleted board schemas

**Severity:** Medium — **Mitigation:** design.md flags each such service with
its board-schema coupling; the apply step decides per file whether to retarget
it onto the unified entities (`meeting`/`decision`/`minutes`) or remove it.
None is deleted blindly by this proposal.

### Risk 3: Corporate demo disappears after schema deletion

**Severity:** Low — **Mitigation:** add `mode=corp` re-seeds on
`governance-body`/`meeting`/`minutes` in the same change so the corporate
scenario remains demonstrable on install.

## Rollback Strategy

This change lives entirely on branch `refactor/decidesk-decision-model`.
Rollback = revert the branch (or the change's commits); the register JSON,
manifest fragment, views, registry, routes, and DI registrations all return to
their prior state. No database migration runs, so no data transformation needs
reversing — OpenRegister still holds the C1/C2-migrated decisions and
Person/Membership objects regardless.

## Open Questions

- The `GovernanceBody.bodyType` enum currently offers `corporate-board`, not the
  `supervisory-board` / `executive-board` values named in ADR-006. The corp
  re-seed uses the existing `corporate-board` enum value to stay schema-valid;
  splitting the enum is deferred (recorded in DEFERRED_QUESTIONS).
