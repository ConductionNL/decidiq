# vote-casting Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [voting-named-paper-vote-entry](../../changes/voting-named-paper-vote-entry/)

## Purpose

A roll call or paper vote happens in the room, and the clerk writes down each member's choice. These requirements let the chair or secretary record those choices by name on the round, so the result and every member's voting record come from the same rows as an electronic vote.

Matrix row: vot-21 (decidiq `openspec/parity/capabilities.json`).

## ADDED Requirements

### Requirement: REQ-NPV-001 A chair opens a roll call round whose votes are recorded by name

The system SHALL offer `roll-call` as a voting method when a round is opened. On an open `roll-call` round the voting round panel SHALL NOT show members the for, against and abstain buttons, and SHALL show the chair and secretary a "Record votes by name" control.

#### Scenario: The chair opens a roll call

- GIVEN `chair1` chairs meeting "Raad 12 maart" and motion "Parkeerbeleid binnenstad" is in lifecycle `deliberating`
- WHEN `chair1` opens a round on the motion page and picks "Roll call, recorded by name"
- THEN the round is stored with `votingMethod: roll-call`
- AND `chair1` sees "Record votes by name"
- AND `member1` sees that a roll call is in progress and no vote buttons

### Requirement: REQ-NPV-002 The chair or secretary records each member's choice

The system SHALL expose `POST /api/voting-rounds/{id}/recorded-votes` to the chair and secretary of the round's meeting. It SHALL store one vote per listed participant with the given value, `entryMode: recorded`, `recordedBy` set to the caller, and `castAt` set to the time the vote was held. Posting a corrected list SHALL replace the earlier recorded votes of the same members, never add to them. A participant left out of the list SHALL have no vote.

#### Scenario: The griffier enters a paper roll call

- GIVEN a closed paper vote in meeting M where Halsema and Bakker voted for and Yilmaz against
- AND `griffier1` is the secretary of M
- WHEN `griffier1` opens "Record votes by name" on the open `roll-call` round, sets the three choices and saves
- THEN three votes exist on the round, each naming its participant, with `entryMode: recorded` and `recordedBy: griffier1`
- AND the motion's "Votes" widget lists Halsema for, Bakker for and Yilmaz against

#### Scenario: A whole party at once

- GIVEN the entry sheet lists five members of party "GroenLinks" and four of party "VVD"
- WHEN `griffier1` sets party "GroenLinks" to for and saves
- THEN each of the five GroenLinks members has a vote `for`
- AND the VVD members have no vote until a choice is set for them

#### Scenario: A correction does not double count

- GIVEN Yilmaz was recorded as `against`
- WHEN `griffier1` posts the list again with Yilmaz as `abstain`
- THEN the round holds one vote for Yilmaz, with value `abstain`

### Requirement: REQ-NPV-003 The recorded list is refused as a whole when any entry is wrong

The endpoint SHALL validate the whole list before it writes anything. It SHALL refuse the request, write nothing, and name the offending entries when the round is not open, is not `roll-call`, or is secret; when a participant is not an active participant of the round's meeting or is listed twice; when a value is not `for`, `against` or `abstain`; or when an entry would replace a vote the member cast themselves (`entryMode: cast`).

#### Scenario: A person outside the meeting

- GIVEN a list with three members of M and one participant of another meeting
- WHEN `griffier1` posts it
- THEN the response is 400 and names the one unknown participant
- AND no vote was written for any of the four

#### Scenario: A member's own vote is not overwritten

- GIVEN Bakker cast a vote `for` from their own account before the round became a paper vote
- WHEN `griffier1` posts a list that records Bakker as `against`
- THEN the response is 409 and names Bakker
- AND Bakker's own vote is unchanged

#### Scenario: No names on a secret ballot

- GIVEN an open round with `isSecret: true`
- WHEN anyone posts recorded votes to it
- THEN the response is 400 and says a secret ballot cannot be recorded by name

#### Scenario: Only the round's own chair or secretary

- GIVEN `member1` is a member of M without a presiding role
- WHEN `member1` posts recorded votes to a round in M
- THEN the response is 403 and nothing is written

### Requirement: REQ-NPV-004 Closing a roll call round computes the result from the recorded votes

Closing a `roll-call` round SHALL compute `votesFor`, `votesAgainst`, `votesAbstain` and `result` from its recorded votes, with the round's threshold, abstention and tie-break rules, through the same tally that counts cast votes.

#### Scenario: The recorded votes decide the motion

- GIVEN a `roll-call` round with 2 recorded `for` and 1 recorded `against` under a simple majority
- WHEN `chair1` closes the round
- THEN the round shows 2 for, 1 against, 0 abstain and result `adopted`
- AND the motion moves to lifecycle `decided` with outcome `adopted`

### Requirement: REQ-NPV-005 Closing a show-of-hands round keeps the totals the chair entered

Closing a `show-of-hands` round SHALL keep the `votesFor`, `votesAgainst` and `votesAbstain` saved through the tally endpoint and SHALL compute the result from them. It MUST NOT replace them with a count of `vote` objects, which a show-of-hands round does not have.

#### Scenario: Totals survive the close

- GIVEN a show-of-hands round where `chair1` saved 14 for, 9 against and 2 abstain
- WHEN `chair1` closes the round
- THEN the round still shows 14 for, 9 against and 2 abstain
- AND its result is `adopted`, not `invalid`
