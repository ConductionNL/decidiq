# Tasks: portal-public-council-records

### Task 1: Published meetings and decisions as public record lists
- **spec_ref**: `openspec/changes/portal-public-council-records/specs/portal-contribution/spec.md#requirement-req-pcr-001-a-visitor-finds-the-published-meetings-with-their-agenda`, `openspec/changes/portal-public-council-records/specs/portal-contribution/spec.md#requirement-req-pcr-002-a-visitor-reads-the-published-decisions-with-their-outcome`
- **files**: `lib/Service/PublicMeetingRecords.php`, `lib/Portal/PortalContributionProvider.php`, `tests/Unit/Portal/PortalPublicRecordsTest.php`
- **acceptance_criteria**:
  - GIVEN a published, a scheduled and a withdrawn meeting payload WHEN the list is read THEN only the published one is listed
  - GIVEN a meeting payload with agenda items WHEN its record is read THEN the items are rows in agenda order
  - GIVEN a decision payload with vote totals WHEN read THEN the summary shows the outcome and the totals; GIVEN all-zero totals THEN no totals are shown
  - GIVEN an id the list does not hold WHEN its record is asked THEN null
- [x] Implement
- [x] Test

### Task 2: The public voting record (task 1 of portal-voting-record-and-confidential-papers)
- **spec_ref**: `openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-001-decidiq-offers-each-public-members-voting-record-to-the-portal-without-a-session`
- **files**: `lib/Service/PublicVotingRecords.php`, `lib/Portal/PortalContributionProvider.php`, `tests/Unit/Portal/PortalPublicRecordsTest.php`
- [x] Implement
- [x] Test (real rule classes over seeded objects)

### Task 3: Example set publications
- **files**: `lib/Settings/example-sets/zuiddrecht.json`
- **acceptance_criteria**:
  - GIVEN the Zuiddrecht set WHEN validated with `tests/example-sets/validate_example_set.py` THEN it is clean, with 9 publication payloads
- [x] Implement
- [x] Test

## Verification

- `composer check:strict` once before push; `npm run test:l10n`, `npm run check:l10n-js`.
