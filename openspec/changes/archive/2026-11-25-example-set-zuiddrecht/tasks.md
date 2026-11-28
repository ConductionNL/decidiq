## 1. Fragment

- [x] 1.1 Write `lib/Settings/example-sets/zuiddrecht.json`: bodies, people, seats, memberships, griffier post, meetings, agenda items, decisions, rounds, stages, votes, groups
- [x] 1.2 Link every vote to its participant and round, every round to its decision through a decisive stage, every decision to its meeting and agenda item, so `VotingRecordService` finds a member's record
- [x] 1.3 Use only `decidiq-secretariat` and `decidiq-administrators`, the group names the code checks

## 2. Verification

- [x] 2.1 `tests/example-sets/validate_example_set.py` passes: every placeholder resolves, every object satisfies its merged schema, no undeclared key, no mail outside `@zuiddrecht.example`
- [x] 2.2 `occ openregister:example-set:install zuiddrecht --check` on an instance with openregister#4603 exits 0
  - 25 Nov, sandbox Nextcloud 34.0.6 on PostgreSQL 16 with OpenRegister main: with decidiq alone, `--check` refused decidiq's `decision-woo-lindelaan-concept` (its `case` lookup needs dossiq). The set is cross-app (`after: dossiq, portaliq`), so dossiq, portaliq and opencatalogi (development) were installed with `occ portaliq:example-site:install zuiddrecht` and `occ dossiq:package:install --all`. After that, decidiq's part checked clean. The install then showed that `agenda-published-raad-1` lost `agendaPublishedAt`: a second change on the same meeting (`agenda-override-raad-1`) was applied to the object as created and wrote it back. The two are now one change, and after a reinstall every decidiq field is kept. `--check` exits 0. The remaining "written but not kept" lines belong to dossiq's fragment (case deadlines and case-type versions), not to decidiq.
- [x] 2.3 After install, a council member's profile shows three votes, and `/bestuur/hoe-stemden-de-raadsleden` lists them once decidiq exposes the `memberVotingRecords` public-records list
  - 25 Nov, the same instance, as a visitor who is not signed in, in Dutch: `/bestuur/hoe-stemden-de-raadsleden` is headed "Hoe stemden de raadsleden" and lists 27 members (`GET /api/public-records/decidiq/memberVotingRecords`). Bart Reijnders' profile lists three votes: 19 november 2026 Verordening afvalstoffenheffing 2027 Voor, Aangenomen; 5 november 2026 Motie lantaarnpalen op zonne-energie Tegen, Verworpen; 5 november 2026 Krediet verlichting fietspad Lindelaan Voor, Aangenomen.
