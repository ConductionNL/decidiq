# Migration: retire-board-portal

## Current State

`lib/Settings/decidesk_register.json` `components.schemas` declared 7 parallel
corporate schemas alongside the universal entities: `Board`, `BoardMember`,
`BoardMeeting`, `BoardVote`, `BoardMinutes`, `BoardMaterial`,
`BoardAuditLogEntry`. These were surfaced by `src/manifest.d/board-portal.json`,
six `Board*`/`Resolution*` Vue views + 2 board modals, board routes in
`appinfo/routes.php`, board DI registrations + the `BoardMeetingCalDavBridge`
listener in `lib/AppInfo/Application.php`, board controllers/services, and the
`resolution` entry in `DecideskSearchProvider`.

## Target State

The 7 board schemas are gone from the register. Corporate concepts are
re-expressed on the universal entities (ADR-006). Three `mode=corp` seeds exist
so the corporate scenario is demonstrable on install:

- `governance-body` slug `raad-van-commissarissen-acme-bv` (`bodyType=supervisory-board`)
- `meeting` slug `rvc-vergadering-2025-q2`
- `minutes` slug `notulen-rvc-2025-q2`

No board views, routes, board-only controllers/services, DI registrations,
CalDAV bridge, or search reference to a deleted schema remains. The board-coupled
governance services/controllers are retargeted onto the unified entities. The app
boots, the nav renders without the parallel board items.

## Migration Class

```
No lib/Migration/VersionXXXXXXXXXX.php class is created.
```

Thin-client (ADR-022) configuration + code change. OpenRegister reconciles the
register on the next settings sync. Object data is NOT transformed — C1 and C2
already moved resolution and board-member data onto the universal entities.

## Data Impact

No object data is created, transformed, or lost. The deleted board schemas held
only seed/demo data, re-provided as three corp seeds on the universal entities.

## Rollback Procedure

Revert the change's commits on branch `refactor/decidesk-decision-model`. No
migration class runs, so there is nothing to reverse at the data layer.
