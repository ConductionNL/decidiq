# ORI public persons

## Why

A resident's portal reads public votes from `/api/ori/v1/votes`, and each vote names its voter by person id. Anonymous `/api/ori/v1/persons` answered 0 of 7 people on the local instance (measured 1 Oct, lane 26 Newman run): the person schema has no authorization block, so OpenRegister's anonymous read returned nothing, and a portal could not turn a voter id into a name. Matrix row pub-14 stayed `building` on this defect.

## What changes

- `persons` is read through a publication rule, `OriPersonPublicationRule`, like `votes` and `voteevents` (Ruben, 2 Oct, DECISIONS row 49): in system context, limited to people who hold a public role, and only their name, image and biography leave decidiq.
- A public role is a membership with an office role (chair, vice-chair, secretary, treasurer, member) in a body with `publishVotingRecords: true`. That is the flag the vote rule reads, so every named voter resolves and nobody else is named. Past memberships count: a former member's published votes still name them.
- The person's email is no longer part of the ORI person (it was, through the generic serializer, to whoever OpenRegister let read it).

## Out of scope

- `memberships` keeps its current path (OpenRegister's own read).
- The live re-measure of pub-14 runs on the dev instance after this lands.
