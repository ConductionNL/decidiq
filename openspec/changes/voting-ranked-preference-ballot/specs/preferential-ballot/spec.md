# preferential-ballot Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [voting-ranked-preference-ballot](../../changes/voting-ranked-preference-ballot/)

## Purpose

Members rank a list of options or candidates, and a Borda count decides. The requirements below replace the May wording, which wrote into fields whose schema refuses the data, with fields that can hold it. None of REQ-PRF-001 to REQ-PRF-005 is built today, although the main spec says `status: done`.

Matrix row: vot-07 (decidiq `openspec/parity/capabilities.json`).

## MODIFIED Requirements

### Requirement: REQ-PRF-001 Chair can open a VotingRound with method "ranked-choice"

The open dialog of the voting round panel SHALL offer "Ranked preference (Borda count)", stored as `votingMethod: ranked-choice`. When it is picked, the dialog SHALL show an option editor. The round SHALL store its options in `VotingRound.options`, each with a unique `key`, a `label` and an optional `person` reference. The server SHALL refuse a ranked round with fewer than two or more than twenty options or with duplicate keys, SHALL refuse `options` on any other method, and SHALL refuse the tie-break rule `chair-decides` on a ranked round. Options SHALL NOT change after the round opens.

#### Scenario: Chair opens a ranked-choice round for a board election

- GIVEN `chair1` chairs the members' meeting of "Tennisvereniging De Lob" and motion "Election of the treasurer" is in lifecycle `deliberating`
- WHEN `chair1` picks "Ranked preference (Borda count)", adds the members Van der Meer, Hoekstra and De Vries as options by picking their Person records, and opens the round
- THEN the round is stored with `votingMethod: ranked-choice` and three options with distinct keys, each with its `person` reference

#### Scenario: One option is not a choice

- GIVEN the open dialog with "Ranked preference (Borda count)" picked
- WHEN `chair1` enters one option and opens the round
- THEN the server answers 400 and says a ranked round needs at least two options
- AND no round is created

### Requirement: REQ-PRF-002 Members rank candidates in order of preference when voting

On an open ranked round the panel SHALL show a ranking ballot instead of the for, against and abstain buttons. The ballot SHALL be fully operable by keyboard, with move up and move down controls on every option. The cast endpoint SHALL store the member's order in `Vote.ranking`, first preference first, with `Vote.value: ranked`, and SHALL refuse a ranking that leaves out an option or names one twice. Casting again SHALL replace the member's ballot.

#### Scenario: Member casts a ranked-choice vote

- GIVEN an open ranked round with options renoveren, nieuwbouw and huren
- WHEN `member1` moves "Build a new clubhouse" to the top with the keyboard, leaves "Renovate the clubhouse" second and "Rent a hall" third, and submits
- THEN a vote exists with `value: ranked` and `ranking: [nieuwbouw, renoveren, huren]`
- AND the panel says the vote has been recorded

#### Scenario: Member cannot submit a partial ranking

- GIVEN the same round
- WHEN a client posts a ranking with only two of the three keys
- THEN the cast endpoint answers 400 and says every option must be ranked
- AND no vote is stored

### Requirement: REQ-PRF-003 Borda count tallying determines the winner

Closing a ranked round SHALL count its ballots with a Borda count: with N options a first place earns N minus 1 points and a last place 0. The round SHALL store `rankingResult` (key, label, points and rank per option) and `winningOption`. `result` SHALL be `adopted` when one option has the highest score, `tied` when two or more share it, and `invalid` when there are no ballots. `votesFor`, `votesAgainst` and `votesAbstain` SHALL stay empty on a ranked round.

#### Scenario: Borda count tallying on close

- GIVEN ballots `[renoveren, nieuwbouw, huren]`, `[nieuwbouw, renoveren, huren]` and `[renoveren, huren, nieuwbouw]`
- WHEN `chair1` closes the round
- THEN renoveren has 5 points, nieuwbouw 3 and huren 1
- AND `winningOption` is `renoveren` and `result` is `adopted`
- AND the motion moves to lifecycle `decided` with outcome `adopted`

#### Scenario: Tie in Borda count is recorded as "tied"

- GIVEN ballots `[renoveren, nieuwbouw, huren]` and `[nieuwbouw, renoveren, huren]`
- WHEN the round closes
- THEN renoveren and nieuwbouw both have 3 points
- AND `result` is `tied`, `winningOption` is empty, and the motion stays in lifecycle `voting`

### Requirement: REQ-PRF-004 Ranked results are displayed as a ranking table

A closed ranked round SHALL show a table with the columns rank, option and points, ordered by rank. The winning option SHALL carry an "Elected" badge; on a tie every tied option SHALL carry a "Tied" badge.

#### Scenario: User views the results of a ranked-choice election

- GIVEN the closed round from the clear winner scenario
- WHEN any member of the association opens the motion page
- THEN the "Voting round" widget shows renoveren first with 5 points and the "Elected" badge, nieuwbouw second with 3, huren third with 1

### Requirement: REQ-PRF-005 Ranked-choice rounds inherit secret ballot rules

A ranked round with `isSecret: true` SHALL store its ballots without a participant relation, as every secret ballot is stored today, and the ranking table SHALL show the points per option only. No screen and no endpoint SHALL show which member ranked which option where on a secret ranked round.

#### Scenario: Secret ranked-choice result shows only point table

- GIVEN a closed ranked round with `isSecret: true`
- WHEN `chair1` opens the motion page or reads the round's votes through the API
- THEN the ranking table shows the points per option
- AND no vote of the round names a participant

## ADDED Requirements

### Requirement: REQ-RPB-001 A tie in a ranked round follows the round's tie-break rule

A ranked round SHALL accept the tie-break rules `rejected` and `revote`. Under `rejected` a tied round SHALL stay `tied`. Under `revote` the chair SHALL be able to open one revote, whose options are the tied options only, and a second tie SHALL stay `tied`.

#### Scenario: A revote between the two tied plans

- GIVEN a ranked round with `tieBreakRule: revote` that closed `tied` between renoveren and nieuwbouw
- WHEN `chair1` starts the revote from the widget
- THEN the new round has `revoteOfRound` set and exactly the options renoveren and nieuwbouw

### Requirement: REQ-RPB-002 Screens that list votes show a ranked ballot as its ranking

The motion "Votes" widget and the decision "Voting results" widget SHALL show a ranked vote as its ordered options, not as the text `ranked`.

#### Scenario: A clerk reads the ballots of an open ranked round

- GIVEN a closed ranked round that is not secret, with a ballot by Van Dijk ranking nieuwbouw, renoveren, huren
- WHEN the secretary opens the motion page and looks at the "Votes" widget
- THEN Van Dijk's row reads "1. Build a new clubhouse, 2. Renovate the clubhouse, 3. Rent a hall"
