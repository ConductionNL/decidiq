## 1. Fragment

- [x] 1.1 Write `lib/Settings/example-sets/zuiddrecht.json`: bodies, people, seats, memberships, griffier post, meetings, agenda items, decisions, rounds, stages, votes, groups
- [x] 1.2 Link every vote to its participant and round, every round to its decision through a decisive stage, every decision to its meeting and agenda item, so `VotingRecordService` finds a member's record
- [x] 1.3 Use only `decidiq-secretariat` and `decidiq-administrators`, the group names the code checks

## 2. Verification

- [x] 2.1 `tests/example-sets/validate_example_set.py` passes: every placeholder resolves, every object satisfies its merged schema, no undeclared key, no mail outside `@zuiddrecht.example`
- [ ] 2.2 `occ openregister:example-set:install zuiddrecht --check` on an instance with openregister#4603 exits 0
- [ ] 2.3 After install, a council member's profile shows three votes, and `/bestuur/hoe-stemden-de-raadsleden` lists them once decidiq exposes the `memberVotingRecords` public-records list
