# Tasks: Migrate action-item board UI to the Deck integration leaf

## 1. Adopt the deck leaf as the board UI
- [ ] 1.1 Confirm the deck leaf is registered in the OR integration registry; add to decidesk's consumed-leaf list if absent
- [ ] 1.2 Surface the deck board as the action-item tab on the meeting detail page via `MeetingIntegrations.vue`
- [ ] 1.3 Surface the deck board as the action-item tab on the decision detail page
- [ ] 1.4 Project each VTODO ActionItem as a deck card on the bound board (VTODO authoritative per D1)
- [ ] 1.5 Define and implement the VTODO↔card sync direction + conflict policy (VTODO wins)
- [ ] 1.6 Graceful degradation when Deck is absent (hide tab; items stay in Tasks app)

## 2. Delegation / reclaim onto VTODO + audit
- [ ] 2.1 Map reassignment to a VTODO ATTENDEE change + card reassignment
- [ ] 2.2 Check for an OR-native delegation abstraction; only fall back to VTODO X-properties for substitute window if none exists (ADR-022)
- [ ] 2.3 Record reclaim as an OpenRegister audit event on the meeting/decision object

## 3. Migration of legacy Task/Delegation objects
- [ ] 3.1 Idempotent migration: ensure a VTODO + deck card per legacy `Task`
- [ ] 3.2 Replay `Delegation` semantics onto VTODO assignee + audit (D2)
- [ ] 3.3 Archive legacy `Task` / `Delegation` objects via OR archival (no hard delete)
- [ ] 3.4 Resume-safe / no duplicates on re-run

## 4. Retire the in-app task stack
- [ ] 4.1 Remove `TaskService` and `DelegationService` from DI and delete the classes
- [ ] 4.2 Remove task/delegation controllers/routes from p4-collaboration
- [ ] 4.3 Remove the in-app task Vue component from the detail-page tab set
- [ ] 4.4 Retire local `Task` / `Delegation` schemas from the active register set (keep archived objects readable)
- [ ] 4.5 Confirm `ActionItemExtractionService` (VTODO writer) is untouched

## 5. Verification
- [ ] 5.1 Action items render as deck cards bound to the meeting; status edits reflect on the VTODO (browser check)
- [ ] 5.2 Reassign + reclaim produce VTODO changes and an audit event
- [ ] 5.3 Deck-absent instance: items still visible in Tasks app, no error
- [ ] 5.4 Migration projects + archives; re-run produces no duplicates
- [ ] 5.5 `composer check:strict` and ESLint pass
