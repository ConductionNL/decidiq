# Migration: retire-board-portal

## Current State

`lib/Settings/decidesk_register.json` `components.schemas` declares 7 parallel
corporate schemas alongside the universal entities:

- `Board` (slug `board`, 3 seeds)
- `BoardMember` (slug `board-member`, 10 seeds)
- `BoardMeeting` (slug `board-meeting`, 5 seeds)
- `BoardVote` (slug `board-vote`, 25 seeds)
- `BoardMinutes` (slug `board-minutes`, 5 seeds)
- `BoardMaterial` (slug `board-material`, 8 seeds)
- `BoardAuditLogEntry` (slug `board-audit-log-entry`, 0 seeds)

These are surfaced by `src/manifest.d/board-portal.json`, six `Board*`/
`Resolution*` Vue views + 2 board modals, board routes in `appinfo/routes.php`,
board DI registrations + the `BoardMeetingCalDavBridge` listener in
`lib/AppInfo/Application.php`, board controllers/services, and the `resolution`
entry in `DecideskSearchProvider`. Corporate decision-makers and resolutions
were already migrated to the universal entities in C2 (`popolo-decision-makers`:
Person + Membership) and C1 (`unify-decision-supertype`: `decision` with
`decisionType=resolution`).

## Target State

The 7 board schemas are gone from the register. Corporate concepts are
re-expressed on the universal entities (ADR-006). Three `mode=corp` seeds exist
so the corporate scenario is demonstrable on install:

- `governance-body` slug `raad-van-commissarissen-acme-bv` (`bodyType=corporate-board`)
- `meeting` slug `rvc-vergadering-2025-q2`
- `minutes` slug `notulen-rvc-2025-q2`

No board views, routes, board-only controllers/services, DI registrations,
CalDAV bridge, or search reference to a deleted schema remains. The app boots,
the nav renders without the parallel board items, and search returns decisions
(formerly resolutions) and meetings.

## Migration Class

```
No lib/Migration/VersionXXXXXXXXXX.php class is created.
```

This is a thin-client (ADR-022) configuration + code change. Entities are
OpenRegister JSON objects, not Decidesk database tables, so there is no Nextcloud
schema migration. Schema changes are applied by editing
`lib/Settings/decidesk_register.json`; OpenRegister reconciles the register on
the next settings sync. Object data is NOT transformed by this change — the C1
and C2 migrations already moved resolution and board-member data onto the
universal entities.

## Migration Steps

1. **Delete the 7 board schemas** from `lib/Settings/decidesk_register.json`
   `components.schemas` (`Board`, `BoardMember`, `BoardMeeting`, `BoardVote`,
   `BoardMinutes`, `BoardMaterial`, `BoardAuditLogEntry`). Their inline
   `x-openregister-seeds` are removed with them.
2. **Delete the manifest fragment** `src/manifest.d/board-portal.json`.
3. **Delete the six Vue views** and the 2 board modals; **strip** their imports
   and `page(...)` registrations from `src/registry.js`.
4. **Clean dangling references** per design.md "Reference cleanup": delete the
   board route block in `appinfo/routes.php`; delete board DI registrations + the
   `BoardMeetingCalDavBridge` listener in `lib/AppInfo/Application.php`; delete
   board-only controllers/services/lifecycle guards; remove the `resolution`
   entry from `DecideskSearchProvider`; retarget-or-remove the flagged
   board-coupled controllers/services (apply decides per file).
5. **Re-seed the corporate demo**: add the three `mode=corp` seeds to the
   `GovernanceBody`, `Meeting`, and `Minutes` schema `x-openregister-seeds`.

## Data Impact

No object data is created, transformed, or lost by this change. Corporate
resolution data already lives as `decision` objects (C1); corporate
board-member data already lives as Person + Membership objects (C2). The deleted
board schemas held only seed/demo data, which is re-provided as three corp seeds
on the universal entities. Safe to run on live data: existing OpenRegister
objects for the deleted schemas (if any were created beyond seeds) become
unreferenced but are not deleted by this change — they can be removed manually or
left inert; no universal-entity data depends on them.

## Rollback Procedure

Revert the change's commits on branch `refactor/decidesk-decision-model`
(`git revert` or branch reset). The register JSON, manifest fragment, views,
registry, routes, DI registrations, and CalDAV listener return to their prior
state. Because no migration class runs, there is nothing to reverse at the data
layer — OpenRegister still holds the C1/C2-migrated decisions and
Person/Membership objects regardless of rollback.

## Validation

- `python3 -c "import json; s=json.load(open('lib/Settings/decidesk_register.json'))['components']['schemas']; assert not ({'Board','BoardMember','BoardMeeting','BoardVote','BoardMinutes','BoardMaterial','BoardAuditLogEntry'} & set(s)), 'board schema still present'; print('schemas OK')"`
- `test ! -f src/manifest.d/board-portal.json && echo "manifest fragment gone"`
- `! ls src/views/BoardList.vue src/views/ResolutionList.vue 2>/dev/null` (views gone).
- `grep -rnE "board-meeting|boardMeeting|'resolution'|BoardList|board-material" lib/ src/ appinfo/` returns no live schema/route/component reference (only retargeted prose, if any).
- App boots: navigate to the Decidesk root, nav renders without Boards / Board meetings / Resolutions items; unified search returns decisions + meetings.
- The three corp seeds exist and validate after settings sync.
