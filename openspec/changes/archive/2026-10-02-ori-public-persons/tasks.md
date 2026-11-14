# Tasks

- [x] 1. Red test first: anonymous persons are exactly the public role holders, with id, name, image and biography; anyone else is 404 by id (tests/Unit/Controller/OriVotePublicationTest.php, fixtures Opis-valid against the merged register).
- [x] 2. `lib/Service/OriPersonPublicationRule.php`, routed from `OriController` for `persons` (index and show); `persons` leaves the ungated list.
- [x] 3. Spec: REQ-ORI-007 in `openspec/specs/ori-api/spec.md`; the email scenario of REQ-ORI-006 replaced.
