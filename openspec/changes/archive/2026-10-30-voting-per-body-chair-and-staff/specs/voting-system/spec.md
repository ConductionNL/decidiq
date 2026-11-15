# voting-system Specification (delta)

**Status**: implemented
**Scope**: decidiq
**OpenSpec changes**:
- [voting-per-body-chair-and-staff](../../) (this delta)

## Purpose

Who casts a vote is decided per body, so the public voting record names only the people who actually vote.

## ADDED Requirements

### Requirement: REQ-VPB-001 A body declares whether its chair votes, and staff never vote

A governance body SHALL carry `chairHasVote` (boolean, default true). A membership role SHALL carry a vote in a body when it is `member`, `vice-chair` or `treasurer`, or when it is `chair` and the body does not set `chairHasVote` to false. The roles `secretary`, `observer` and `guest`, and any unknown role, SHALL never carry a vote. The public voting record list (`memberVotingRecords`) SHALL name only people who hold a voting role in a body that publishes its voting records, and SHALL return no record for anyone else.

#### Scenario: The mayor chairs the council without a vote
- GIVEN Gemeenteraad Voorbeeld publishes its voting records and sets `chairHasVote` to false
- AND Anna Burgemeester is its chair and Bram Griffier its secretary
- WHEN portaliq reads the member list
- THEN neither Anna Burgemeester nor Bram Griffier is listed, and neither has a record

#### Scenario: A committee chair votes
- GIVEN Commissie ruimte publishes its voting records and does not set `chairHasVote`
- WHEN portaliq reads the member list
- THEN its chair is listed with the subtitle "Chair, Commissie ruimte", and its secretary is not

#### Scenario: Every councillor of the example set shows named votes
- GIVEN the shipped example set is installed
- WHEN portaliq reads the record of each voting councillor
- THEN each record holds one named vote per public round, and the named votes add up to each round's totals

### Requirement: REQ-VPB-002 A dangling reference skips one object, not the whole list

When an object a public vote list walks past refers to an object that does not exist (a decision named by slug, or deleted), decidiq SHALL treat that reference as absent and skip only the object that depends on it.

#### Scenario: A stage names a decision that does not exist
- GIVEN a closed open round whose stage names the decision `besluit-by-slug`, which does not exist, and Marie voted in it
- WHEN portaliq reads Marie's record
- THEN that round is not in her rows, and her other public votes are
