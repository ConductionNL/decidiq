---
status: done
---

# voting-round-management Specification

## Purpose
Lets the chair or secretary open, configure, and close a voting round for a motion. Opening a round transitions the motion to voting, enforces a single open round per motion, and verifies quorum before it can start; the chair chooses the voting method and secret-ballot setting, and closing the round automatically tallies the votes and sets the outcome (adopted, rejected, or tied). An optional voting deadline creates a reminder calendar event for asynchronous or email voting.

## Requirements

### Requirement: REQ-VRM-001 Chair opens a VotingRound for a Motion in lifecycle voting
The app SHALL allow users with role `chair` or `secretary` to open a VotingRound for a Motion. Opening the round transitions the Motion to lifecycle `voting` and records `VotingRound.openedAt`.

#### Scenario: Chair opens a voting round
- **GIVEN** a Motion with `lifecycle: "debating"`
- **WHEN** the chair clicks "Stemronde openen" and selects `votingMethod: "for-against-abstain"`
- **THEN** a VotingRound is created with `openedAt` set to now, linked to the Motion via OpenRegister relation, AND the Motion `lifecycle` transitions to `voting`

#### Scenario: Only one open VotingRound per Motion at a time
- **GIVEN** a Motion with an open VotingRound (no `closedAt`)
- **WHEN** the chair attempts to open a second VotingRound for the same Motion
- **THEN** the system returns an error "Er is al een open stemronde voor deze motie" and no second round is created

---

### Requirement: REQ-VRM-002 Quorum is verified before a VotingRound can be opened
The app SHALL call `VotingService::checkQuorum()` before opening any VotingRound. If the number of active Participants present is below `Meeting.quorumRequired`, the round SHALL NOT be opened.

#### Scenario: Quorum is met — round opens successfully
- **GIVEN** a Meeting with `quorumRequired: 19` and 23 active Participants present
- **WHEN** the chair opens a VotingRound
- **THEN** `VotingService::checkQuorum()` returns `true`, the round is opened, and `VotingRound.quorumMet` is stored as `true`

#### Scenario: Quorum is not met — round is blocked
- **GIVEN** a Meeting with `quorumRequired: 19` and only 15 active Participants marked present
- **WHEN** the chair clicks "Stemronde openen"
- **THEN** `VotingService::checkQuorum()` returns `false`, the round is NOT created, and a `400 Bad Request` is returned with body `{ "message": "Quorum niet bereikt: 15 van de vereiste 19 leden aanwezig" }`

---

### Requirement: REQ-VRM-003 Chair configures the voting method per round
The app SHALL allow the chair to select one of the supported voting methods when opening a VotingRound: `for-against-abstain`, `ranked-choice`, `weighted`, or `show-of-hands`.

#### Scenario: Chair selects show-of-hands voting
- **GIVEN** the "Stemronde openen" dialog
- **WHEN** the chair selects `show-of-hands` from the voting method dropdown
- **THEN** the VotingRound is created with `votingMethod: "show-of-hands"` and the vote casting UI shows a show-of-hands recording interface (for/against/abstain counts entered by the chair)

#### Scenario: Secret ballot is toggled
- **GIVEN** the "Stemronde openen" dialog
- **WHEN** the chair toggles "Geheime stemming" to on
- **THEN** the VotingRound is created with `isSecret: true` and individual vote values are not revealed in the results UI until the round closes

---

### Requirement: REQ-VRM-004 Chair closes a VotingRound and the result is calculated automatically
The app SHALL allow the chair to close an open VotingRound. On close, `VotingService::tallyResults()` counts `votesFor`, `votesAgainst`, `votesAbstain`, sets `result` (adopted/rejected/tied/invalid), and records `closedAt`.

#### Scenario: Chair closes a round with clear majority
- **GIVEN** a VotingRound with 23 votes for, 8 against, 1 abstain
- **WHEN** the chair clicks "Stemronde sluiten" and confirms
- **THEN** `VotingService::tallyResults()` is called, `VotingRound.votesFor = 23`, `votesAgainst = 8`, `votesAbstain = 1`, `result = "adopted"`, `closedAt` = now — and the Motion `lifecycle` transitions to `adopted`

#### Scenario: Tied vote results in "tied" outcome
- **GIVEN** a VotingRound with equal for and against votes
- **WHEN** the round is closed
- **THEN** `VotingRound.result` is set to `"tied"` and the Motion lifecycle does NOT automatically advance — the chair must decide the tie-breaking procedure manually

---

### Requirement: REQ-VRM-005 Voting deadline creates a calendar event
The app SHALL allow the chair to set a `closedAt` timestamp when opening a VotingRound (for async or email voting). Setting this timestamp SHALL trigger `CalendarEventService` to create a calendar event titled "Stemdeadline: [Motion title]" on the configured Nextcloud calendar.

#### Scenario: Chair sets a voting deadline for remote participants
- **GIVEN** the "Stemronde openen" dialog
- **WHEN** the chair sets `closedAt` to a future date/time
- **THEN** `VotingService::openVotingRound()` calls `CalendarEventService.createEvent()` with the deadline timestamp, linked to the Meeting's calendar — AND a reminder is set 48 hours before the deadline

#### Scenario: No calendar event when no deadline is set
- **GIVEN** the chair opens a round WITHOUT setting a `closedAt` value
- **WHEN** the VotingRound is created
- **THEN** no calendar event is created and the round remains open until the chair manually closes it

### Requirement: REQ-VCR-001 The meeting's chair and secretary see the voting controls

The voting round panel SHALL show the open, close, full tally, tie-break and publish controls to the chair and the secretary of the round's meeting, whether or not they are Nextcloud admins. It SHALL hide them from every other member. The panel MUST take this decision from the server's answer (REQ-VCR-002) and MUST NOT derive it from `settingsStore.isAdmin` or from any other rule of its own.

#### Scenario: A chair who is not an admin closes a vote

- GIVEN user `chair1` is a participant with role `chair` in meeting "Raad 12 maart" and is not a Nextcloud admin
- AND motion "Groen dak op het stadhuis" has an open voting round in that meeting
- WHEN `chair1` opens the motion page `/motions/{id}` and looks at the "Voting round" widget
- THEN the "Close voting round" button is shown
- AND after `chair1` confirms the close, the widget shows the result and the round has a `closedAt`

#### Scenario: A member sees neither the open nor the close control

- GIVEN user `member1` is a participant with role `member` in the same meeting
- WHEN `member1` opens a motion in lifecycle `deliberating` with no round
- THEN no "Open voting round" button is shown
- AND on a motion with an open round, `member1` sees the vote buttons and the count of votes cast, but no close button and no split per option

#### Scenario: An admin keeps the controls when the round has no meeting

- GIVEN a Nextcloud admin and a voting round whose meeting cannot be resolved
- WHEN the admin opens the widget
- THEN the close control is shown, because the server's global fallback accepts the admin

### Requirement: REQ-VCR-002 The server says which voting controls a user may use in a meeting

The system SHALL expose `GET /api/meetings/{meetingId}/voting-permissions` to any signed-in user. It SHALL return `canOpen`, `canClose`, `canEnterTally` and `canCastChairVote` for the caller. Each value MUST be computed by the same `VotingRoundGuard` check that the matching endpoint enforces, so that a control the page shows is never refused by the server for lack of a role. For a meeting that does not exist the response SHALL be HTTP 200 with all four values `false`.

#### Scenario: The answer matches the endpoints

- GIVEN `chair1` is the chair and `member1` a member of meeting M
- WHEN each calls `GET /api/meetings/M/voting-permissions`
- THEN `chair1` receives `canClose: true` and `canCastChairVote: true`
- AND `member1` receives four `false` values
- AND `POST /api/voting-rounds/{id}/close` on a round in M returns 200 for `chair1` and 403 for `member1`

#### Scenario: A secretary may close but may not cast the chair's vote

- GIVEN user `griffier1` is the secretary of meeting M
- WHEN `griffier1` calls the permissions endpoint
- THEN `canClose` and `canEnterTally` are `true` and `canCastChairVote` is `false`

### Requirement: REQ-VCR-003 Tally entry and publication check the role in the round's own meeting

`POST /api/voting-rounds/{id}/tally` and `POST /api/voting-rounds/{id}/publish` SHALL resolve the meeting from the round and SHALL check the caller's chair or secretary role in that meeting, as `POST /api/voting-rounds/{id}/close` does. When no meeting resolves they SHALL fall back to the existing global check.

#### Scenario: A meeting secretary enters a show-of-hands result

- GIVEN `griffier1` is the secretary of meeting M and not a Nextcloud admin
- AND a show-of-hands round is open on a motion in M
- WHEN `griffier1` saves 14 for, 9 against and 2 abstentions in the widget
- THEN the endpoint returns 200 and the round shows those totals

#### Scenario: The secretary of another meeting is refused

- GIVEN `griffier2` is the secretary of meeting N and has no role in meeting M
- WHEN `griffier2` posts a tally to a round in M
- THEN the endpoint returns 403 and the round's totals are unchanged

### Requirement: REQ-VCR-004 A chair opens and closes a vote on an amendment from the amendment page

The amendment detail page `/amendments/:id` SHALL show a "Voting round" widget that mounts the voting round panel for that amendment. Opening a round there SHALL send `subjectType: amendment`, and the round's meeting SHALL be the parent motion's meeting. The server's existing amendment order rule SHALL apply unchanged, and its refusal message SHALL be shown in the widget.

#### Scenario: The chair votes on an amendment

- GIVEN motion "Groen dak op het stadhuis" in meeting M with amendment A1 (`votingOrder` 1) in lifecycle `deliberating`
- WHEN `chair1` opens `/amendments/A1`, clicks "Open voting round" and confirms
- THEN a voting round is created that relates to A1 under schema `amendment`
- AND A1's lifecycle becomes `voting`
- AND the widget shows the open round with the close control for `chair1`

#### Scenario: An amendment out of order is refused on the page

- GIVEN the same motion with amendment A1 (`votingOrder` 1) and A2 (`votingOrder` 2), both undecided
- WHEN `chair1` tries to open a round on A2 from `/amendments/A2`
- THEN no round is created
- AND the widget shows the server's message that A1 must be voted first

#### Scenario: An amendment whose motion has no meeting

- GIVEN an amendment whose parent motion has no linked meeting
- WHEN the chair opens its page
- THEN the "Open voting round" button is disabled and its title says that no meeting is linked

#### Scenario: The motion vote follows its amendments

- GIVEN both amendments of a motion have closed rounds
- WHEN `chair1` opens the motion page
- THEN the "Open voting round" button on the motion is enabled and opening a round succeeds
