# ori-api Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [bodies-member-profile-and-voting-record](../../changes/bodies-member-profile-and-voting-record/)

## Purpose

Residents see how their representatives voted. decidiq publishes each public vote through its public ORI API with the voter named, so a portal or Open Raadsinformatie can show a member's voting record. A body decides whether its members' votes are public.

Matrix row: pub-14 (decidiq `openspec/parity/capabilities.json`).

## ADDED Requirements

### Requirement: REQ-MPR-005 A body decides whether its members' votes are public

A governance body SHALL carry `publishVotingRecords`, default `false`. Only votes in rounds of a body with `publishVotingRecords: true` SHALL be published by name.

#### Scenario: A council publishes, a supervisory board does not

- GIVEN Gemeenteraad Amsterdam with `publishVotingRecords: true` and Raad van Commissarissen ACME B.V. with the default
- WHEN both hold an open, adopted and published vote
- THEN the public API returns the council members' votes by name
- AND returns no vote of the supervisory board members

### Requirement: REQ-MPR-006 The public ORI API returns public votes with their voter

`GET /api/ori/v1/votes` SHALL return, to anonymous callers, every vote whose round is closed and not secret, whose subject decision has `isPublished: public`, whose value is set, and whose body publishes voting records. Each vote SHALL name its voter as an ORI person, its option (`yes`, `no`, `abstain`), its vote event and the voter's party. The endpoint SHALL accept `?voter={personId}`. `GET /api/ori/v1/votes/{id}` SHALL return exactly the votes the collection returns and refuse every other id. `GET /api/ori/v1/voteevents` SHALL return closed rounds whose subject decision is published, with their totals and without per-member values.

#### Scenario: A resident's portal reads a member's record

- GIVEN Marie Janssen voted for the published motion "Groen dak op het stadhuis" in a closed, open round of Gemeenteraad Amsterdam
- WHEN an anonymous caller requests `/api/ori/v1/votes?voter={Marie's person id}`
- THEN the response contains that vote with `option: yes`, the vote event, and group "D66"

#### Scenario: A secret or unpublished vote stays private

- GIVEN a secret round in the same council, and an open round on a decision that is not published
- WHEN an anonymous caller requests `/api/ori/v1/votes` or either vote by id
- THEN neither vote is returned, and the request by id answers 404

#### Scenario: Vote events publish totals only

- GIVEN the closed round on the published motion, with 23 for, 8 against and 1 abstain
- WHEN an anonymous caller requests `/api/ori/v1/voteevents`
- THEN the round is returned with those totals and its result
- AND no per-member value is part of the vote event
