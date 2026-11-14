# Tasks: live-room-conference-system-link

## Implementation tasks

### Task 1: The connection and the seat field
- **spec_ref**: `openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-002-a-members-seat-is-recorded-on-the-member`
- **files**: `lib/Settings/connections.json` (`room-system` with `sourceTemplate`), `lib/Settings/register.d/92-room-system-link.json` (`Participant.roomSeat`, read grant for the synchronisation user), `lib/Settings/profiles/municipality.json`
- **acceptance_criteria**:
  - GIVEN the connections file WHEN hydra gate `connections-declaration` runs THEN it passes
  - GIVEN the synchronisation user WHEN it lists participants (Newman) THEN `roomSeat` is present
- [x] Implement
- [ ] Test
  - Built: `Participant.roomSeat` and the `room-system` connection. The fragment is `lib/Settings/register.d/99-room-system-link.json`, not 92-: fragments merge in sorted name order, so 99- is needed for its Participant and Vote version bumps to win. The synchronisation user already reads participant, meeting and agenda-item through the register's `read: authenticated`, so no grant was added. Unit: `RoomSystemLinkRegisterTest`, `ConnectionsDeclarationTest`. Open: the Newman request as the synchronisation user, and the seats in `profiles/municipality.json`.

### Task 2: The inbox schema and who may write it
- **spec_ref**: `openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-003-only-the-integriq-synchronisation-user-writes-room-system-messages`
- **files**: `lib/Settings/register.d/92-room-system-link.json` (`RoomSystemMessage`, `authorization`, register `schemas` list), `tests/Unit/Settings/RegisterDescriptorTest.php`
- **acceptance_criteria**:
  - GIVEN a member session WHEN it creates a message (Newman) THEN 403, red before the rule and green after
  - GIVEN the descriptor test WHEN it runs THEN the new schema is attached
- [x] Implement
- [ ] Test
  - Built: `RoomSystemMessage`, attached to the register. `create` is open only to the new group `integriq-synchronisation` (OpenRegister provisions it; an administrator adds integriq's user), and update and delete are closed. Unit: `RoomSystemLinkRegisterTest`, plus `RegisterAuthorizationTest`, which classifies the new block. Open: the Newman 403 as a member.

### Task 3: Apply a speech
- **spec_ref**: `openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-004-a-speaker-marking-becomes-a-speech-on-the-members-engagement-record`
- **files**: `lib/Listener/RoomSystemMessageListener.php`, `lib/Service/RoomSystemMessageService.php`, `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`
- **acceptance_criteria**:
  - GIVEN a real `OCA\OpenRegister\Event\ObjectCreatedEvent` for a speech from a linked seat WHEN handled THEN the engagement record gains the speech (PHPUnit, red then green)
  - GIVEN an unlinked seat WHEN handled THEN `rejected` with the reason
- [x] Implement
- [x] Test
  - `RoomSystemMessageService::apply()` with `RoomSystemMessageListener` on `ObjectCreatedEvent`, registered in `lib/Listener/Registrar/ObjectListenerRegistrar.php`. `RoomSystemMessageServiceTest` uses the real ObjectCreatedEvent; it errors without the service.

### Task 4: Apply a vote, with `castVia`
- **spec_ref**: `openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-005-a-desk-vote-becomes-a-named-vote-under-the-normal-voting-rules`
- **files**: `lib/Service/RoomSystemMessageService.php`, `lib/Settings/register.d/92-room-system-link.json` (`Vote.castVia`, or a value on the field `voting-named-paper-vote-entry` adds if it landed first)
- **acceptance_criteria**:
  - GIVEN an open round and a linked seat WHEN a vote message is handled THEN one vote with `castVia: room-system` (PHPUnit with the real event)
  - GIVEN a secret round, a closed round, or a repeated `externalId` WHEN handled THEN `rejected` and no vote saved
- [x] Implement
- [x] Test
  - A desk vote goes through `castVote()`. The saved vote is then stamped `castAs: in-person`, `castVia: room-system` (`Vote.castVia` added, since `voting-named-paper-vote-entry` had not landed). Secret rounds, an already applied `externalId`, and voting-rule refusals are rejected with their reason.

### Task 5: Apply again, and the widget
- **spec_ref**: `openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-006-a-message-that-cannot-be-applied-is-kept-and-can-be-applied-again`
- **files**: `lib/Controller/RoomSystemController.php`, `appinfo/routes.php`, `src/components/tabs/MeetingRoomSystemTab.vue`, `src/registry.js`, `src/manifest.json` (`MeetingDetail` widget, layout and slot), `tests/e2e/room-system-link.spec.ts`
- **acceptance_criteria**:
  - GIVEN a non-staff member WHEN they call the apply route THEN 403 (PHPUnit and Newman)
  - GIVEN hydra gates `route-auth`, `no-admin-idor` and `route-reachability` WHEN they run THEN they pass
  - GIVEN the Playwright spec WHEN it runs THEN it references the UI scenarios of REQ-RCSL-001 and REQ-RCSL-006
- [x] Implement
- [ ] Test
  - Built: `RoomSystemController` (`GET /api/meetings/{id}/room-system`, `POST /api/room-system-messages/{id}/apply`), both behind `TranscriptionStaffGuard::forMeeting()` on the message's own meeting before anything is read or applied; `RoomSystemMessageService` gains `isConnected()`, `forMeeting()` (rejected first) and `meetingOf()`, and refuses to apply while no source is linked ("No room system is connected", REQ-RCSL-001). `MeetingRoomSystemTab` on `MeetingDetail` (widget, layout, slot, simple-profile tab under More), both routes in `openapi.json`. Unit: `RoomSystemControllerTest` (403 before apply, 404 after the guard, read after the guard), `RoomSystemMessageServiceTest` (not connected, rejected first); vitest `meetingRoomSystemTab.spec.js`. route-auth and route-reachability pass; no-admin-idor lists `meeting()` as it lists `CaseSystemController::send()`, the same guard through the injected staff guard. Open: the Playwright spec and the Newman 403.

### Task 6: Strings and docs
- Dutch and English strings for the widget, reasons and the seat field (`test:l10n`, `check:schema-l10n` green).
- `docs/features/room-system-link.md`: linking the delegate system in integriq, setting seats, reading rejected messages.
- [ ] Implement
- [ ] Test

### Task 7: Send the agenda, speakers and vote results to the room
- **spec_ref**: `openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-007-the-room-system-receives-the-agenda-the-speakers-and-the-vote-results`
- **files**: `lib/Service/RoomSystemMessageService.php` (outgoing messages through the `room-system` connection), `lib/Listener/RoomSystemMessageListener.php`, `tests/Unit/Service/RoomSystemMessageServiceTest.php`
- [ ] Implement
- [ ] Test

