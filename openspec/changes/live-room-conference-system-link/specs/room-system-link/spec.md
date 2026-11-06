# room-system-link Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [live-room-conference-system-link](../../) (this delta)
- supersedes the speaker recognition part of [raadsvergadering-livestream-transcript](../../../raadsvergadering-livestream-transcript/)

## Purpose

Links the chamber's conference and voting system with decidiq through
integriq: the published agenda and the seat list go out, speaker markings and
per-seat votes come back and pass through decidiq's own voting rules. Closes
matrix row liv-22.

**Standards**: Schema.org `VoteAction` and `Event`.

## ADDED Requirements

### Requirement: REQ-RCSL-001 The room system is an integriq connection

decidiq SHALL declare a `room-system` connection in
`lib/Settings/connections.json`. When no source is linked to it, decidiq SHALL
accept no room system message as applied and the Room system widget SHALL say
"No room system is connected".

#### Scenario: No room system linked

- GIVEN no source is linked to the `room-system` connection
- WHEN the secretary opens a meeting page
- THEN the Room system widget reads "No room system is connected"

### Requirement: REQ-RCSL-002 A member's seat is recorded on the member

`Participant` SHALL carry an optional `roomSeat`, the delegate unit id the room
system sends. The published agenda, the agenda items and the participants with
their seats SHALL be readable by the integriq synchronisation user, so the room
system can receive them.

#### Scenario: The clerk links a seat

- GIVEN council member J. de Vries without a seat
- WHEN the secretary sets seat "12" on the member's page and saves
- THEN the member's `roomSeat` is "12"

#### Scenario: The synchronisation user reads the seat list

- GIVEN the integriq synchronisation user
- WHEN it lists `participant` objects of the council through the OpenRegister API
- THEN each row carries `roomSeat`
- @e2e exclude RBAC contract; covered by a Newman request as the synchronisation user

### Requirement: REQ-RCSL-003 Only the integriq synchronisation user writes room system messages

`RoomSystemMessage` SHALL be creatable only by the integriq synchronisation
user. A message SHALL change nothing by itself: only decidiq's listener sets
its `status` and applies it.

#### Scenario: A member cannot forge a vote from the desk

- GIVEN a council member's session
- WHEN they create a `room-system-message` through the OpenRegister API
- THEN the request is refused with 403
- @e2e exclude RBAC contract; covered by a Newman IDOR request

### Requirement: REQ-RCSL-004 A speaker marking becomes a speech on the member's engagement record

A `speech` message whose seat resolves to exactly one member of the meeting's
body SHALL be applied through `EngagementService::captureEngagement()` with its
start time, end time and agenda item, and SHALL be marked `applied`.

#### Scenario: A member speaks on an agenda item

- GIVEN seat 12 linked to J. de Vries and a speech message from seat 12 on "Vaststelling omgevingsvisie" from 19:42 to 19:46
- WHEN the message arrives
- THEN J. de Vries's engagement record for the meeting holds that speech, `speakingDuration` grows by 240 seconds, and the message is `applied`
- @e2e exclude listener path; covered by PHPUnit constructing the real OpenRegister ObjectCreatedEvent

### Requirement: REQ-RCSL-005 A desk vote becomes a named vote under the normal voting rules

A `vote` message SHALL be applied through `VoteCastingService::castVote()` for
the member its seat resolves to, and the saved vote SHALL carry `castAs:
in-person` and `castVia: room-system`. A vote on a round that is not open, on a
secret round, or from a member outside the meeting SHALL be rejected with the
reason.

#### Scenario: A desk vote counts

- GIVEN an open, non-secret round on motion "Meer groen in de wijk" and seat 14 linked to A. Bakker
- WHEN a vote message "for" from seat 14 arrives
- THEN A. Bakker has a "for" vote in the round with `castVia: room-system`, and the chair sees it on the round before closing
- @e2e exclude listener path; covered by PHPUnit constructing the real OpenRegister ObjectCreatedEvent

#### Scenario: A secret ballot is not read from the desks

- GIVEN a secret round
- WHEN a vote message arrives for it
- THEN the message is `rejected` with "A secret ballot cannot be read from the desk units" and no vote is saved
- @e2e exclude listener path; covered by PHPUnit

#### Scenario: The same message twice counts once

- GIVEN a vote message with `externalId` "mvi-8812" already applied
- WHEN the room system sends "mvi-8812" again for the same meeting
- THEN no second vote is saved and the second message is `rejected` with "Already applied"
- @e2e exclude listener path; covered by PHPUnit

### Requirement: REQ-RCSL-006 A message that cannot be applied is kept and can be applied again

A message whose seat maps to no member or to more than one SHALL be marked
`rejected` with a plain reason and SHALL stay visible in the Room system widget
on `MeetingDetail`, rejected messages first. The chair or secretary SHALL be
able to apply it again through `POST /api/room-system-messages/{id}/apply`.

#### Scenario: The clerk fixes an unlinked seat

- GIVEN a vote message from seat 27 rejected with "Seat 27 is not linked to a member"
- WHEN the secretary links seat 27 to P. Jansen and presses "Apply again" in the Room system widget
- THEN P. Jansen has the vote in the round and the message is `applied`

#### Scenario: Only staff apply again

- GIVEN a council member who is neither chair nor secretary
- WHEN they call `POST /api/room-system-messages/{id}/apply`
- THEN the response is 403
- @e2e exclude authorization contract; covered by PHPUnit on the controller and a Newman IDOR request
