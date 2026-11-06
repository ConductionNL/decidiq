---
kind: code
depends_on:
  - portal-public-council-records
---

# Proposal: voting-per-body-chair-and-staff

## Summary

Who votes is decided per body. A body declares whether its chair has a vote; staff roles never vote. The public "how did the members vote" page then lists only the people who vote, and it shows their named votes again.

## Why

Found in the live test of the demo portal on 14 October 2026:

- The page listed the mayor (who chairs the council without a vote) and the griffier (staff, never a voter) as voters.
- Every member page said the record did not exist, and decision pages showed totals only. The cause: `VoteContextReader::object()` expected a missing object to read as null, but OpenRegister throws `DoesNotExistException`. One decision stage elsewhere in the register that names its decision by slug, or a deleted decision, aborted every vote listing (the ORI `votes` resource answered 500, and the member record failed with it).

Decision (Ruben, 14 October 2026): voting is per body. A council's chair has no vote; a committee's or a board's chair often does. Staff roles (griffier, secretary) never vote.

## What changes

1. Register fragment `124-chair-has-vote.json`: `GovernanceBody.chairHasVote` (boolean, default true, so committees and boards keep today's behaviour).
2. `VoterEligibility::mayVote(role, body)`: member, vice-chair and treasurer vote; the chair votes unless the body sets `chairHasVote` to false; secretary, observer, guest and unknown roles never vote.
3. `PublicVotingRecords` lists and builds records only for people with a voting office in a body that publishes its voting records.
4. `VoteContextReader::object()` reads a missing object as null, so a dangling reference skips one round, not the whole list.
5. The council in the municipality profile and in the Zuiddrecht example set declares `chairHasVote: false`; the college keeps its chair's vote.

## Out of scope

- Live voting (round opening, presets, quorum) still takes its voters from `ParticipantResolver`. Applying `VoterEligibility` there touches attendance, minutes and notifications and is a separate change.
- The ORI `persons` resource still names every public office holder, the chair and the griffier included: being a public person is not the same as being a voter.
