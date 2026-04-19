## ADDED Requirements

### Requirement: REQ-VRR-001 Chair or secretary can request a recount after VotingRound closes
The app SHALL expose a `POST /api/voting-rounds/{id}/recount` endpoint accessible to users with the `chair` or `secretary` role. Calling it triggers `VotingService::recount()` which re-tallies all `Vote` objects for the round. A recount may only be requested once; subsequent requests on the same round return `409 Conflict`.

#### Scenario: Secretary requests a recount after a close result
- **GIVEN** a closed `VotingRound` with `result: "adopted"` and `votesFor: 16`, `votesAgainst: 15`
- **WHEN** the secretary calls `POST /api/voting-rounds/{id}/recount`
- **THEN** `VotingService::recount()` re-tallies all Vote objects; if counts match, a note "Hertelverzoek: geen afwijking gevonden" is added and the round status remains unchanged

#### Scenario: Observer cannot request a recount
- **GIVEN** a closed `VotingRound`
- **WHEN** a user with role `observer` calls `POST /api/voting-rounds/{id}/recount`
- **THEN** the backend returns `403 Forbidden`

#### Scenario: Recount is blocked on an open VotingRound
- **GIVEN** a `VotingRound` that is still open (`closedAt` is null)
- **WHEN** any user calls `POST /api/voting-rounds/{id}/recount`
- **THEN** the backend returns `400 Bad Request` with message "Recount can only be requested after the voting round is closed"

---

### Requirement: REQ-VRR-002 Discrepancy sets VotingRound result to "disputed" and creates a comparison note
If `VotingService::recount()` finds that the re-tallied counts differ from the stored `votesFor`, `votesAgainst`, or `votesAbstain`, the service SHALL set `VotingRound.result` to `"disputed"` and create a structured note on the VotingRound with `title: "Hertelverzoek"` and a JSON body containing the original and recount values.

#### Scenario: Recount reveals a discrepancy
- **GIVEN** a closed `VotingRound` with stored `votesFor: 16`, `votesAgainst: 16`, `result: "tied"`
- **WHEN** `VotingService::recount()` tallies 17 For and 15 Against
- **THEN** `VotingRound.result` is set to `"disputed"`; a note is added with body `{"originalFor":16,"recountFor":17,"originalAgainst":16,"recountAgainst":15}` and the chair/secretary see a "Stemming betwist" warning on the MotionDetail page

---

### Requirement: REQ-VRR-003 Chair or secretary can resolve a disputed result
A `POST /api/voting-rounds/{id}/recount-resolve` endpoint accessible to `chair` or `secretary` SHALL accept a `finalResult` body and set `VotingRound.result` to the provided value, removing the `"disputed"` state. The resolution is logged to the Activity stream.

#### Scenario: Chair resolves a disputed result
- **GIVEN** a `VotingRound` with `result: "disputed"`
- **WHEN** the chair calls `POST /api/voting-rounds/{id}/recount-resolve` with `{ "finalResult": "adopted", "votesFor": 17, "votesAgainst": 15 }`
- **THEN** `VotingRound.result` is updated to `"adopted"`, `votesFor` and `votesAgainst` are corrected, and an Activity entry is logged: "Stemronde [title] hertel-resultaat vastgesteld door [chair]"

---

### Requirement: REQ-VRR-004 Auditor with read-only access can view Vote records on non-secret rounds
Users with the OpenRegister built-in read-only role (auditor access per Story 4) SHALL be able to access `GET /api/votes?votingRound={id}` for non-secret VotingRounds and see individual vote values and voter identifiers. For secret rounds, the `SecretBallotGuard` applies and values remain masked.

#### Scenario: Auditor reviews individual votes on an open-ballot round
- **GIVEN** a closed `VotingRound` with `isSecret: false` and the requesting user has the auditor (read-only) role
- **WHEN** the auditor calls `GET /api/votes?votingRound={id}`
- **THEN** all Vote objects are returned with `value` fields and Participant relations intact

#### Scenario: Auditor views a disputed VotingRound's recount note
- **GIVEN** a `VotingRound` with `result: "disputed"` and a "Hertelverzoek" note
- **WHEN** the auditor opens the VotingRound detail page
- **THEN** the "Hertelverzoek" note is visible with the original and recount comparison data

---

### Requirement: REQ-VRR-005 Disputed VotingRound shows a warning banner on MotionDetail
The `MotionDetail` page and `VotingRoundPanel` SHALL display an orange warning banner "Stemming betwist — hertelverzoek in behandeling" when the related `VotingRound.result` equals `"disputed"`, so that all participants are aware the result is under review.

#### Scenario: Member opens MotionDetail with a disputed result
- **GIVEN** a `Motion` whose related `VotingRound` has `result: "disputed"`
- **WHEN** any user opens the `MotionDetail` page
- **THEN** an orange `NcNoteCard` with the warning message is rendered at the top of the `VotingRoundPanel`
