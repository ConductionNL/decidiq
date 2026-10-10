# citizen-participation Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [participation-citizen-advisory-vote-on-motions](../../) (this delta)

## Purpose

Lets residents give an advisory vote on a motion through the portal, as archived `2026-05-11-p3-citizen-participation` specified and never built. Closes decidiq matrix row par-08.

**Standards**: Schema.org `VoteAction`, ADR-046 (portal contribution contract), eIDAS trust levels (substantial).

## ADDED Requirements

### Requirement: REQ-CAV-001 The griffie opens and closes an advisory vote on a motion

A motion SHALL carry `citizenVotingStatus` (not open, open, closed). The secretariat, or the chair or secretary of the motion's meeting, SHALL be able to open it when the motion is published, allows citizen voting and uses the simple method, and SHALL be able to close it. Any other caller or state SHALL be refused with a reason.

#### Scenario: The griffier opens the advice round
- GIVEN the published motion "Motie meer groen in de wijk" allows citizen voting
- WHEN the griffier chooses Open advisory vote on the motion page
- THEN the motion's advisory vote is open

#### Scenario: A motion that residents cannot read
- GIVEN a motion that is not published
- WHEN the griffier tries to open an advisory vote
- THEN she is told the motion must be published first

### Requirement: REQ-CAV-002 A verified resident gives one advisory vote while it is open

decidiq's portal contribution SHALL offer residents signed in at trust level substantial the action Give your advice on this motion, with the choices voor, tegen and onthoud, only while the motion's advisory vote is open. decidiq SHALL refuse a second vote by the same resident on the same motion, and SHALL refuse any vote on a motion whose advisory vote is not open, whatever path the vote arrives by.

#### Scenario: A resident advises for
- GIVEN the advisory vote on the motion is open
- WHEN a resident signed in with DigiD chooses voor on the portal
- THEN her vote is recorded and the portal lists it under My votes

#### Scenario: Voting twice
- GIVEN the same resident already voted on this motion
- WHEN she tries to vote again
- THEN the vote is refused with "You have already given your advice on this motion"

#### Scenario: Too late
- GIVEN the griffier closed the advisory vote
- WHEN a vote for that motion reaches OpenRegister
- THEN it is refused

### Requirement: REQ-CAV-003 The advisory result shows apart from the council's vote

The motion page SHALL show the counts of voor, tegen and onthoud under the caption "Advisory vote by residents, not binding", separate from the council's voting round, whose tally SHALL never count citizen votes. After closing, residents SHALL read the counts through the portal.

#### Scenario: The council sees the residents' advice
- GIVEN the advisory vote closed with 312 voor, 104 tegen and 20 onthoud
- WHEN a council member opens the motion before the council votes
- THEN he sees those three counts with the not-binding caption beside the voting round
