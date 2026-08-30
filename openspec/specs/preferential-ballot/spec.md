---
status: done
---

# preferential-ballot Specification

## Purpose
Provides ranked-choice (Borda count) voting for elections, letting a chair open a voting round with a candidate list and members rank all candidates in order of preference. Votes are tallied server-side using Borda scoring to determine a winner or recorded tie, results are presented in a ranking table highlighting the elected candidate, and secret ballots mask per-voter rankings while still showing final point totals.

## Requirements

### Requirement: REQ-PRF-001 Chair can open a VotingRound with method "ranked-choice"
The `votingMethod` field on `VotingRound` SHALL accept the value `"ranked-choice"`. The "Stemronde openen" dialog in `VotingRoundPanel` SHALL include "Voorkeursstemming (Borda)" as a selectable option in the voting method dropdown. When selected, the dialog shows a candidate entry field where the chair enters the list of candidates (names or identifiers, comma-separated).

#### Scenario: Chair opens a ranked-choice round for a board election
- **GIVEN** a Motion in `lifecycle: "debating"` related to a board election
- **WHEN** the chair selects "Voorkeursstemming (Borda)" in the "Stemronde openen" dialog and enters candidates "Van der Meer, Hoekstra, De Vries"
- **THEN** a `VotingRound` is created with `votingMethod: "ranked-choice"`, and the candidate list is stored as a structured note on the VotingRound

---

### Requirement: REQ-PRF-002 Members rank candidates in order of preference when voting
When an open `VotingRound` has `votingMethod: "ranked-choice"`, the vote casting UI in `VotingRoundPanel` SHALL display a ranked list input (`RankInput.vue`) showing all candidates. Participants drag or click to assign a rank order (1 = first preference). On submit, `Vote.value` is set to a JSON-encoded ordered array of candidate identifiers.

#### Scenario: Member casts a ranked-choice vote
- **GIVEN** an open `VotingRound` with `votingMethod: "ranked-choice"` and candidates ["Van der Meer", "Hoekstra", "De Vries"]
- **WHEN** a member drags "Hoekstra" to rank 1, "Van der Meer" to rank 2, "De Vries" to rank 3, and clicks "Stem uitbrengen"
- **THEN** a `Vote` is saved with `value: "[\"Hoekstra\",\"Van der Meer\",\"De Vries\"]"` and `castAt` set to the current timestamp

#### Scenario: Member cannot submit a partial ranking
- **GIVEN** an open `VotingRound` with `votingMethod: "ranked-choice"` and 3 candidates
- **WHEN** the member has ranked only 2 of 3 candidates and clicks "Stem uitbrengen"
- **THEN** the form shows a validation error "Rangschik alle kandidaten voordat u uw stem uitbrengt" and the vote is not submitted

---

### Requirement: REQ-PRF-003 Borda count tallying determines the winner
`VotingService::tallyResults()` SHALL detect `VotingRound.votingMethod: "ranked-choice"`, deserialise each `Vote.value` JSON array, and apply Borda count: with N candidates, a first-place vote earns N−1 points, second place N−2, ..., last place 0 points. The candidate with the highest total points wins. The winner identifier is written to `VotingRound.result`. A structured note with the full point table is added to the VotingRound.

#### Scenario: Borda count tallying on close
- **GIVEN** a `VotingRound` with `votingMethod: "ranked-choice"`, 3 candidates (A, B, C), 10 votes: 6 rank A→B→C, 4 rank B→A→C
- **WHEN** `VotingService::closeVotingRound()` is called
- **THEN** Borda scores: A = 6×2 + 4×1 = 16, B = 6×1 + 4×2 = 14, C = 0×all = 0; `VotingRound.result` is set to "A" (winner); a note records the full table

#### Scenario: Tie in Borda count is recorded as "tied"
- **GIVEN** a `VotingRound` with `votingMethod: "ranked-choice"` and a Borda count that results in equal top scores for two candidates
- **WHEN** `VotingService::closeVotingRound()` is called
- **THEN** `VotingRound.result` is set to `"tied"` and the note lists the tied candidates by name

---

### Requirement: REQ-PRF-004 Ranked results are displayed as a ranking table
After a ranked-choice `VotingRound` closes, the `VotingRoundPanel` SHALL display a `RankedResultsCard.vue` component showing a table with columns: Rank, Candidate, Points. The winner is highlighted with a `CnStatusBadge` "Verkozen".

#### Scenario: User views the results of a ranked-choice election
- **GIVEN** a closed `VotingRound` with `votingMethod: "ranked-choice"` and result "Van der Meer"
- **WHEN** any user opens the `MotionDetail` page
- **THEN** `RankedResultsCard` displays a ranking table: Rank 1 — Van der Meer — 42 punten (badge "Verkozen"); Rank 2 — Hoekstra — 38 punten; Rank 3 — De Vries — 20 punten

---

### Requirement: REQ-PRF-005 Ranked-choice rounds inherit secret ballot rules
If a `VotingRound` has both `votingMethod: "ranked-choice"` and `isSecret: true`, the `SecretBallotGuard` SHALL mask individual `Vote.value` fields (replacing with `"anonymous"`) and the ranked results table SHALL show only the final Borda point totals and winner — not per-voter rankings.

#### Scenario: Secret ranked-choice result shows only point table
- **GIVEN** a closed `VotingRound` with `votingMethod: "ranked-choice"` and `isSecret: true`
- **WHEN** any user views the `RankedResultsCard`
- **THEN** the table shows candidate names and point totals only; no per-voter ranking breakdown is visible
