# Tasks: live-room-conference-system-link

## Implementation tasks

### Task 1: The connection and the seat field
- **spec_ref**: `openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-002-a-members-seat-is-recorded-on-the-member`
- **files**: `lib/Settings/connections.json` (`room-system` with `sourceTemplate`), `lib/Settings/register.d/92-room-system-link.json` (`Participant.roomSeat`, read grant for the synchronisation user), `lib/Settings/profiles/municipality.json`
- **acceptance_criteria**:
  - GIVEN the connections file WHEN hydra gate `connections-declaration` runs THEN it passes
  - GIVEN the synchronisation user WHEN it lists participants (Newman) THEN `roomSeat` is present
- [ ] Implement
- [ ] Test

### Task 2: The inbox schema and who may write it
- **spec_ref**: `openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-003-only-the-integriq-synchronisation-user-writes-room-system-messages`
- **files**: `lib/Settings/register.d/92-room-system-link.json` (`RoomSystemMessage`, `authorization`, register `schemas` list), `tests/Unit/Settings/RegisterDescriptorTest.php`
- **acceptance_criteria**:
  - GIVEN a member session WHEN it creates a message (Newman) THEN 403, red before the rule and green after
  - GIVEN the descriptor test WHEN it runs THEN the new schema is attached
- [ ] Implement
- [ ] Test

### Task 3: Apply a speech
- **spec_ref**: `openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-004-a-speaker-marking-becomes-a-speech-on-the-members-engagement-record`
- **files**: `lib/Listener/RoomSystemMessageListener.php`, `lib/Service/RoomSystemMessageService.php`, `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`
- **acceptance_criteria**:
  - GIVEN a real `OCA\OpenRegister\Event\ObjectCreatedEvent` for a speech from a linked seat WHEN handled THEN the engagement record gains the speech (PHPUnit, red then green)
  - GIVEN an unlinked seat WHEN handled THEN `rejected` with the reason
- [ ] Implement
- [ ] Test

### Task 4: Apply a vote, with `castVia`
- **spec_ref**: `openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-005-a-desk-vote-becomes-a-named-vote-under-the-normal-voting-rules`
- **files**: `lib/Service/RoomSystemMessageService.php`, `lib/Settings/register.d/92-room-system-link.json` (`Vote.castVia`, or a value on the field `voting-named-paper-vote-entry` adds if it landed first)
- **acceptance_criteria**:
  - GIVEN an open round and a linked seat WHEN a vote message is handled THEN one vote with `castVia: room-system` (PHPUnit with the real event)
  - GIVEN a secret round, a closed round, or a repeated `externalId` WHEN handled THEN `rejected` and no vote saved
- [ ] Implement
- [ ] Test

### Task 5: Apply again, and the widget
- **spec_ref**: `openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-006-a-message-that-cannot-be-applied-is-kept-and-can-be-applied-again`
- **files**: `lib/Controller/RoomSystemController.php`, `appinfo/routes.php`, `src/components/tabs/MeetingRoomSystemTab.vue`, `src/registry.js`, `src/manifest.json` (`MeetingDetail` widget, layout and slot), `tests/e2e/room-system-link.spec.ts`
- **acceptance_criteria**:
  - GIVEN a non-staff member WHEN they call the apply route THEN 403 (PHPUnit and Newman)
  - GIVEN hydra gates `route-auth`, `no-admin-idor` and `route-reachability` WHEN they run THEN they pass
  - GIVEN the Playwright spec WHEN it runs THEN it references the UI scenarios of REQ-RCSL-001 and REQ-RCSL-006
- [ ] Implement
- [ ] Test

### Task 6: Strings and docs
- Dutch and English strings for the widget, reasons and the seat field (`test:l10n`, `check:schema-l10n` green).
- `docs/features/room-system-link.md`: linking the delegate system in integriq, setting seats, reading rejected messages.
- [ ] Implement
- [ ] Test
