# written-resolution Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [voting-written-resolution](../../changes/voting-written-resolution/)

## Purpose

A body decides between meetings: its chair or secretary puts a decision to every entitled member in writing, each member answers from the decision page, and the decision is adopted only when the body's rule is met by the whole membership. Silence is not consent.

**Standards**: Burgerlijk Wetboek article 2:40 (associations) and article 2:238 (BV shareholders); Schema.org `VoteAction`.

Matrix row: vot-16 (decidiq `openspec/parity/capabilities.json`).

## ADDED Requirements

### Requirement: REQ-WRS-001 The chair or secretary of a body puts a decision to the body in writing

The system SHALL let a user in the body's `signatory` scope (chair, vice-chair or secretary) open a written round on a decision in lifecycle `proposed` or `deliberating` that has no meeting. The request MUST name the body and a deadline in the future and MUST NOT name a meeting. The round SHALL store `procedure: written`, the body, the deadline and a snapshot of the entitled members: every membership of the body that is active that day and whose role is not `observer` or `guest`. The system SHALL refuse the open when any entitled member has no Nextcloud account, and SHALL name them.

#### Scenario: The secretary sends a resolution to the supervisory board

- GIVEN Jan de Vries is secretary of "Raad van Commissarissen Waterschap Amstel, Gooi en Vecht", which has three active members with Nextcloud accounts
- AND decision "Appoint the external auditor for 2026" is in lifecycle `proposed` and has no meeting
- WHEN Jan opens the decision page, clicks "Decide in writing", picks the board and a deadline of Friday 17:00, and confirms
- THEN a voting round exists with `procedure: written`, that body, that deadline and an electorate of three
- AND the decision page shows "0 of 3 answered"

#### Scenario: Someone outside the body's presiding roles

- GIVEN Mark van den Berg is an ordinary member of that board
- WHEN Mark tries to decide the same decision in writing
- THEN the server answers 403 and no round is created

#### Scenario: A member who cannot answer

- GIVEN one of the three members has no Nextcloud account
- WHEN Jan opens the written round
- THEN the server answers 400 and names that member
- AND no round is created

### Requirement: REQ-WRS-002 Each entitled member answers from the decision page

An entitled member SHALL be able to answer an open written round with for, against, abstain, or "Discuss this in a meeting", which is stored as `objection`. The answer SHALL be stored as a vote related to the member's membership. A member SHALL be able to change their answer until the round closes. The server SHALL refuse an answer from anyone outside the round's electorate and MUST NOT fall back to any meeting check.

#### Scenario: A member agrees

- GIVEN an open written round on "Appoint the external auditor for 2026" with Janneke de Bruin in its electorate
- WHEN Janneke opens the decision from her notification and answers for
- THEN a vote `for` related to her membership is stored
- AND the page shows "1 of 3 answered"

#### Scenario: Someone outside the electorate

- GIVEN a Nextcloud user who is not a member of the board, or who became a member after the round opened
- WHEN they post an answer to the round
- THEN the server answers 403 and stores nothing

### Requirement: REQ-WRS-003 The result is counted against the whole electorate

A written round SHALL count its result against every entitled member, not against the answers received. Under the default rule `unanimous` it SHALL be `adopted` only when every entitled member answered for. Under the rule `threshold` it SHALL be `adopted` when the weight of the for answers meets the round's `voteThreshold` of the whole electorate's weight and nobody objected. An `objection` SHALL make the result `rejected` under either rule.

#### Scenario: Two of three agree and one abstains

- GIVEN a `unanimous` written round with three entitled members
- WHEN two answer for and one abstains
- THEN the result is `rejected`

#### Scenario: A majority rule a body chose for itself

- GIVEN a `threshold` written round with `voteThreshold: simple-majority` and five entitled members of weight 1
- WHEN three answer for and two do not answer before the deadline
- THEN the result is `adopted`, because three of five is a majority of the whole electorate

#### Scenario: One member wants a meeting

- GIVEN the same `threshold` round
- WHEN four answer for and one answers "Discuss this in a meeting"
- THEN the result is `rejected` and the close reason is `objection`

### Requirement: REQ-WRS-004 The round closes as soon as its outcome is known, and at the latest at its deadline

The system SHALL close a written round when every entitled member has answered, when the outcome can no longer change, when an objection arrives, or when the deadline passes, whichever comes first, and SHALL record which of these as `closedReason`. Silence at the deadline SHALL count as not agreeing. On close the decision SHALL move to lifecycle `decided` with the round's outcome; on `adopted` its `decisionDate` SHALL be the time of the last answer. When the decision has an active vote stage for the same body, that stage SHALL be decided with the same outcome.

#### Scenario: Everyone agrees before the deadline

- GIVEN a `unanimous` round with three members, two of whom answered for
- WHEN Mark answers for on Wednesday at 10:12
- THEN the round closes at once with result `adopted` and close reason `all-answered`
- AND the decision is `decided` with outcome `adopted` and decision date Wednesday 10:12

#### Scenario: The first refusal settles a unanimous round

- GIVEN a `unanimous` round with three members and no answers yet
- WHEN Mark answers against
- THEN the round closes at once with result `rejected` and close reason `outcome-settled`

#### Scenario: Silence at the deadline

- GIVEN a `unanimous` round where two members answered for and one did not answer
- WHEN the deadline passes and the deadline job runs
- THEN the round closes with result `rejected` and close reason `deadline`
- AND running the job again changes nothing

### Requirement: REQ-WRS-005 The decision keeps a readable record of the written procedure

The decision page SHALL show, for a closed written round, the body, the rule, the deadline, every entitled member with their answer and its time or "no answer", the result and the close reason. While the round is open and its deadline has passed, the page SHALL say "Deadline passed, waiting to close".

#### Scenario: The auditor reads the record

- GIVEN the closed round from "Everyone agrees before the deadline"
- WHEN a board member opens the decision page
- THEN the "Decided in writing" widget lists Janneke de Bruin for, Jan de Vries for and Mark van den Berg for, each with its time
- AND it states the rule `unanimous`, the result `adopted` and the reason "all answered"

### Requirement: REQ-WRS-006 Entitled members are told when a written round opens

When a written round opens, every entitled member SHALL receive a Nextcloud notification that links to the decision. Members who have not answered SHALL receive a reminder within the 24 hours before the deadline.

#### Scenario: The notification reaches the board

- GIVEN Jan opens a written round for the three board members
- WHEN the round is saved
- THEN Janneke, Jan and Mark each receive a notification "A decision is waiting for your answer: Appoint the external auditor for 2026" that opens the decision page
