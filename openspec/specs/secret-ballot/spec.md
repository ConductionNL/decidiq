---
status: done
---

# secret-ballot Specification

## Purpose
Keeps individual votes anonymous when a voting round is marked secret. A backend guard masks each vote's value and strips the voter relation for all roles, including chair and secretary, while aggregate counts remain available; the UI hides the per-participant table, shows a lock icon and a "secret ballot" badge, and the audit trail records that a participant voted without revealing the direction. Internal recount logic can still read actual values directly, bypassing the masked API layer.

## Requirements

### Requirement: REQ-SBL-001 Secret VotingRound masks individual Vote data at the API level
The backend SHALL refuse to expose the `value` field and the voter Participant relation on any `Vote` object that belongs to a `VotingRound` with `isSecret: true`. A `SecretBallotGuard` injected in `VotingController` MUST replace `value` with `"anonymous"` and strip the Participant relation before serialisation. This masking applies to all roles, including chair and secretary.

#### Scenario: Secretary tries to read votes on a secret round
- **GIVEN** a `VotingRound` with `isSecret: true` that has at least one cast `Vote`
- **WHEN** the secretary calls `GET /api/votes?votingRound={id}`
- **THEN** each Vote in the response has `value: "anonymous"` and no Participant relation; only `castAt`, `weight`, `isProxy`, and aggregate counts on the `VotingRound` are returned

#### Scenario: Public user queries a secret round's aggregate counts
- **GIVEN** a closed `VotingRound` with `isSecret: true`
- **WHEN** any authenticated user calls `GET /api/voting-rounds/{id}`
- **THEN** `votesFor`, `votesAgainst`, `votesAbstain`, and `result` are returned normally; no per-voter breakdown is included in the response

#### Scenario: Recount service can still read actual vote values internally
- **GIVEN** a `VotingRound` with `isSecret: true` and a recount is triggered
- **WHEN** `VotingService::recount()` is called from within the application
- **THEN** it reads Vote objects directly via `ObjectService.findAll()` (bypassing the HTTP layer) and tallies actual `value` fields without exposure to the API caller

---

### Requirement: REQ-SBL-002 Secret ballot UI hides per-participant vote table
The `VotingRoundPanel` component SHALL display only aggregate counts and a neutral "Uw stem is anoniem geregistreerd" confirmation message when the active `VotingRound` has `isSecret: true`. The per-participant vote table that is shown for open rounds MUST NOT be rendered.

#### Scenario: Member casts a vote in a secret round
- **GIVEN** a `VotingRound` with `isSecret: true` that is open
- **WHEN** a member clicks "Voor" and confirms
- **THEN** the panel shows "Uw stem is anoniem geregistreerd" and displays aggregate "Uitgebracht: X / Y"; the per-participant list is not rendered

#### Scenario: Chair sees only totals during a secret round
- **GIVEN** a `VotingRound` with `isSecret: true` that is open
- **WHEN** the chair opens the `MotionDetail` page
- **THEN** `VotingRoundPanel` shows "Uitgebracht: X / Y — Voor: A, Tegen: B, Onthouding: C" but no names or individual vote values are rendered

---

### Requirement: REQ-SBL-003 Secret ballot is indicated with a lock icon in the UI
The `VotingRoundPanel` and the `VotingRound` list view SHALL display a lock icon (`mdi-lock`) alongside the round title when `isSecret: true`, so that all participants are clearly informed of the secret nature before casting.

#### Scenario: User opens a secret VotingRound
- **GIVEN** a `VotingRound` with `isSecret: true`
- **WHEN** the user views the `MotionDetail` page or the VotingRound list
- **THEN** a lock icon is displayed next to the round title and a "Geheime stemming" badge is shown using `CnStatusBadge`

---

### Requirement: REQ-SBL-004 Audit trail records vote was cast without revealing direction
The audit trail entry for a vote cast in a secret round SHALL record that `Participant X cast a vote in VotingRound Y` without including the `value` field. The `ActivityService` call in `VotingService::castVote()` MUST omit the vote value when `VotingRound.isSecret` is `true`.

#### Scenario: Audit trail entry for a secret vote
- **GIVEN** a `VotingRound` with `isSecret: true`
- **WHEN** a Participant casts a vote
- **THEN** the Activity log contains an entry like "J. van der Berg heeft gestemd in stemronde Bestuursverkiezing 2025" with no vote direction; the `value` is absent from the activity payload
