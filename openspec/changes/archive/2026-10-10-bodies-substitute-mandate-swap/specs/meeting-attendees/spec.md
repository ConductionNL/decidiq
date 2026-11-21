# meeting-attendees Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [bodies-substitute-mandate-swap](../../changes/bodies-substitute-mandate-swap/)

## Purpose

A member who leaves a meeting is replaced in their seat by a substitute. The substitute votes for the seat, the seat counts once towards the quorum, and the record says who sat where and when.

Matrix row: bod-18 (decidiq `openspec/parity/capabilities.json`).

## ADDED Requirements

### Requirement: REQ-MSW-001 The chair or secretary swaps a member for a substitute during a meeting

The live meeting page SHALL show a "Seats" panel listing the meeting's seats with seat number, name and party. For the chair and the secretary of the meeting each voting member SHALL have a "Swap with substitute" action. Starting a swap SHALL create a mandate substitution for that meeting that copies the outgoing member's seat number, party, role and voting weight, and records the time, the reason and who recorded it. The panel SHALL then show the substitute in the seat with "substitute for" the member's name.

#### Scenario: A committee member leaves and her substitute takes the seat

- GIVEN meeting "Auditcommissie 4 maart" where Mr Bos (VVD) holds seat 3 and Mrs De Wit (VVD) attends as an observer
- AND `griffier1` is secretary of the meeting
- WHEN `griffier1` opens `/meetings/{id}/live`, clicks "Swap with substitute" on seat 3, picks Mrs De Wit, enters "Mr Bos left for another appointment" and confirms
- THEN a mandate substitution exists for the meeting with outgoing Mr Bos, incoming Mrs De Wit, seat 3, party VVD and `recordedBy: griffier1`
- AND the Seats panel shows Mrs De Wit in seat 3, "substitute for Mr Bos"

#### Scenario: A member without a presiding role

- GIVEN `member1` is a member in the same meeting
- WHEN `member1` opens the live meeting page
- THEN the Seats panel shows no swap actions
- AND a direct POST to `/api/meetings/{id}/substitutions` by `member1` answers 403

### Requirement: REQ-MSW-002 While a substitution is active, the substitute votes for the seat

While a substitution is active in a meeting, the system SHALL refuse votes from the outgoing member in that meeting's rounds and SHALL accept votes from the substitute. The quorum check SHALL count the seat once, through the substitute. A voting group preset that lists the outgoing member SHALL resolve to the substitute for rounds opened in that meeting.

#### Scenario: The substitute votes, the member who left cannot

- GIVEN the active substitution from REQ-MSW-001
- WHEN the chair opens a round on a motion in the meeting
- THEN Mrs De Wit sees the vote buttons and her vote is stored
- AND a vote posted by Mr Bos in that round is refused with a message that his seat is held by his substitute

#### Scenario: The quorum does not drop when a seat is filled

- GIVEN a meeting with quorum 3 and three members present, one of whom is substituted out
- WHEN the chair opens a round
- THEN the quorum check passes, because the substituted seat is counted through its substitute

#### Scenario: A voting group follows the swap

- GIVEN a voting group preset "Commissie audit" that lists Mr Bos
- WHEN the chair opens a round with that preset during the substitution
- THEN Mrs De Wit is an eligible voter of the round and Mr Bos is not

### Requirement: REQ-MSW-003 A swap is refused when it would change a vote in progress or break the seat plan

The system SHALL refuse to start or end a substitution while a voting round of the meeting is open. It SHALL refuse to start one when the outgoing participant is not a `member` or is already substituted out, when the incoming participant is not a participant of the meeting's body, has a voting role, or is already an active substitute, or when both are the same participant.

#### Scenario: Not during a vote

- GIVEN an open voting round in the meeting
- WHEN `griffier1` tries to swap Mrs Kaya for a substitute
- THEN the server answers 409 and says a vote is in progress
- AND no substitution is created

#### Scenario: The chair is not swapped

- GIVEN the chair of the meeting
- WHEN `griffier1` tries to swap the chair for a substitute
- THEN the server answers 400 and says only members can be substituted

### Requirement: REQ-MSW-004 Ending a substitution returns the seat to the member

The chair or secretary SHALL be able to end an active substitution. Ending it SHALL set its end time and SHALL restore the member's right to vote in the meeting. The ended substitution SHALL stay on record and SHALL be listed on the meeting.

#### Scenario: Mr Bos returns

- GIVEN the active substitution of seat 3
- WHEN `griffier1` clicks "End substitution" on seat 3 at 21:40
- THEN the substitution has `endedAt` 21:40
- AND in the next round Mr Bos can vote and Mrs De Wit cannot
- AND the meeting still lists the substitution from 20:15 to 21:40
