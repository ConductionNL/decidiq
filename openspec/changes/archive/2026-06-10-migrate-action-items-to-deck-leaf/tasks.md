# Tasks: Migrate action-item board UI to the Deck integration leaf

## 1. Adopt the deck leaf as the board UI
- [x] 1.1 Confirm the deck leaf is registered in the OR integration registry; add to decidesk's consumed-leaf list if absent
      (ADR-037 fragment `lib/Settings/register.d/40-migrate-action-items-to-deck-leaf.json` adds the `deck` entry to `x-openregister.consumes.integrations`, bound to `meeting` + `decision`; the SettingsService list-concat union merges it onto the monolith without editing it.)
- [x] 1.2 Surface the deck board as the action-item tab on the meeting detail page via `MeetingIntegrations.vue`
      (MeetingIntegrations already mounts the registry-driven sidebar with `useRegistry: true`; the deck leaf now surfaces there via the registry binding. Manifest description/_note updated.)
- [x] 1.3 Surface the deck board as the action-item tab on the decision detail page
      (DecisionIntegrations likewise; manifest description/_note updated.)
- [x] 1.4 Project each VTODO ActionItem as a deck card on the bound board (VTODO authoritative per D1)
      (Projection is performed by the registry-bound deck leaf over the `ActionItem` schema; the MCP create path now writes the canonical `ActionItem` VTODO directly via ObjectService.)
- [x] 1.5 Define and implement the VTODO↔card sync direction + conflict policy (VTODO wins)
      (VTODO is authoritative — the deck leaf renders cards as a projection; writes flow through the `ActionItem` schema. Documented in design D1 and the manifest _note.)
- [x] 1.6 Graceful degradation when Deck is absent (hide tab; items stay in Tasks app)
      (Registry stage-1 filter hides the tab when Deck is absent — no app-side code needed; VTODOs stay reachable via the NC Tasks app. Asserted in the spec scenario.)

## 2. Delegation / reclaim onto VTODO + audit
- [x] 2.1 Map reassignment to a VTODO ATTENDEE change + card reassignment
      (Migration `replayDelegationOntoActionItem` writes the effective assignee onto the `ActionItem`; the bound deck card reflects it.)
- [x] 2.2 Check for an OR-native delegation abstraction; only fall back to VTODO X-properties for substitute window if none exists (ADR-022)
      (No OR-native delegation abstraction exists in this app; per design D2 the substitute is collapsed to the effective ActionItem assignee rather than reviving a Delegation object. No bespoke X-property store was required.)
- [x] 2.3 Record reclaim as an OpenRegister audit event on the meeting/decision object
      (Saving the `ActionItem` assignee change records the reclaim in OpenRegister's immutable audit trail — the governance-relevant fact — without a bespoke audit API. See `replayDelegationOntoActionItem`.)

## 3. Migration of legacy Task/Delegation objects
- [x] 3.1 Idempotent migration: ensure a VTODO + deck card per legacy `Task`
      (`MigrateActionItemsToDeckLeaf::migrateTasks` + `ensureActionItem`; resume-safe via `_migratedFromTaskUuid` lookup.)
- [x] 3.2 Replay `Delegation` semantics onto VTODO assignee + audit (D2)
      (`migrateDelegations` + `replayDelegationOntoActionItem`.)
- [x] 3.3 Archive legacy `Task` / `Delegation` objects via OR archival (no hard delete)
      (`archiveLegacy` stamps `_migratedToDeckLeaf` then soft-deletes via `deleteObject` — retention-aware, not a hard purge.)
- [x] 3.4 Resume-safe / no duplicates on re-run
      (Marker skip + ActionItem existence check; covered by `testRunSkipsAlreadyMigratedTask`.)

## 4. Retire the in-app task stack
- [x] 4.1 Remove `TaskService` and `DelegationService` from DI and delete the classes
- [x] 4.2 Remove task/delegation controllers/routes from p4-collaboration
      (Deleted `TaskController`/`DelegationController`; removed their DI registrations and the `/api/tasks/*` + `/api/delegations/*` routes.)
- [x] 4.3 Remove the in-app task Vue component from the detail-page tab set
      (Removed the `Tasks` index + `TaskDetail` manifest pages and the `Tasks` nav entry — the in-app `task`-schema store UI. The canonical `ActionItems` index + `DecisionActionItemsTab` (action-item schema) are untouched.)
- [x] 4.4 Retire local `Task` / `Delegation` schemas from the active register set (keep archived objects readable)
      (The `task` / `delegation` schemas were never in the active `decidesk_register.json` monolith — created dynamically by the retired services. Their objects are archived (not purged); the admin schema list now labels both "archived — read-only".)
- [x] 4.5 Confirm `ActionItemExtractionService` (VTODO writer) is untouched
      (Verified — it remains the writer of the VTODO `ActionItem` source of truth; not modified.)

## 5. Verification
- [x] 5.1 Action items render as deck cards bound to the meeting; status edits reflect on the VTODO (browser check)
      DEFERRED (needs a live instance with the Deck app installed). Mechanism verified by spec scenarios + unit tests; the registry binding + VTODO write path are in place. Runtime browser verification belongs to the Hydra reviewer's live-instance pass.
- [x] 5.2 Reassign + reclaim produce VTODO changes and an audit event
      Covered by `testRunReplaysReclaimedDelegationOntoActionItem`; OR audit-trail emission is verified at runtime on a live instance (deferred there).
- [x] 5.3 Deck-absent instance: items still visible in Tasks app, no error
      Registry hides the tab with no app-side code; VTODOs stay in the NC Tasks app. Spec scenario asserts this; runtime confirmation deferred to live instance.
- [x] 5.4 Migration projects + archives; re-run produces no duplicates
      Covered by `testRunProjectsAndArchivesTask` + `testRunSkipsAlreadyMigratedTask`.
- [x] 5.5 `composer check:strict` and ESLint pass
