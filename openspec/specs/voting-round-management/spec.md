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
