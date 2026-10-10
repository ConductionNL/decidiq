# Tasks: portal-voting-record-and-confidential-papers

Build after `agenda-item-confidential-papers` (it adds `AgendaItem.public`, `authorisedReaders` and `DigitalDocument.public`). Portaliq's half is `portaliq/site-member-voting-record-and-confidential-papers`; its `publicRecords` key and the `documents.opened` hook must land before task 1 and task 4 can be tested live.

## Implementation tasks

### Task 1: The public voting record
- **spec_ref**: `openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-001-decidiq-offers-each-public-members-voting-record-to-the-portal-without-a-session`
- **files**: `lib/Portal/PortalContributionProvider.php` (`publicRecords`, `publicMembers()`, `publicVotingRecord()`), `tests/Unit/Portal/PortalContributionProviderTest.php`
- **acceptance_criteria**:
  - GIVEN the citizen contribution WHEN read THEN it declares `publicRecords` with `memberVotingRecords`, `listProvider: publicMembers`, `recordProvider: publicVotingRecord`
  - GIVEN the seeded council WHEN `publicMembers()` runs THEN it returns exactly the people `OriPersonPublicationRule` names
  - GIVEN Sanne Mulder WHEN `publicVotingRecord()` runs THEN its rows equal `OriVotePublicationRule::votes(voter)` and the summary counts match the rows
  - GIVEN a secret round, an open round and an unpublished decision WHEN the record is built THEN none of their votes appear
  - GIVEN a person `publicMembers()` does not name WHEN `publicVotingRecord()` runs THEN it returns null
  - GIVEN decidiq's services cannot be resolved WHEN either method runs THEN it returns an empty list or null, never throws
- [ ] Implement
- [ ] Test (real rule classes over seeded objects, not mocked rules)

### Task 2: Fields for named readers
- **spec_ref**: `openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item`
- **files**: `lib/Settings/register.d/126-portal-confidential-readers.json`, `lib/Settings/profiles/municipality.json`
- **acceptance_criteria**:
  - GIVEN the register WHEN imported THEN `agenda-item` carries `authorisedPersons` (array of person ids) and `person` carries `portalAccountRef`, with a slug on every fragment schema
  - GIVEN the import log WHEN read THEN it has no `PARTIAL IMPORT` line
  - GIVEN the municipality example set WHEN loaded THEN "Grondaankoop Lindelaan" is non-public with two papers and Pieter Bos as authorised person
- [ ] Implement
- [ ] Test (register test)

### Task 3: Add and remove a reader from the item page
- **spec_ref**: `openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-002-the-griffie-names-a-reader-without-a-nextcloud-account-on-a-confidential-item`
- **files**: `lib/Controller/ConfidentialReaderController.php`, `appinfo/routes.php`, `src/manifest.json` (`AgendaItemDetail`, Lezers toevoegen), `tests/Unit/Controller/ConfidentialReaderControllerTest.php`
- **acceptance_criteria**:
  - GIVEN the griffier and a non-public item WHEN she adds Pieter Bos with a valid BSN THEN the provision and claim events are dispatched, `portalAccountRef` and `authorisedPersons` are set, and no BSN is in any decidiq object or log
  - GIVEN an invalid BSN (elfproef) or KvK number WHEN submitted THEN 422 with the reason
  - GIVEN a public item WHEN a reader is added THEN 409 "Make the item confidential first"
  - GIVEN a caller without the secretariat group or a chair or secretary role WHEN she adds a reader THEN 403
  - GIVEN no listener answers the events WHEN a reader is added THEN 409 naming portaliq and nothing is written
  - GIVEN the route gates WHEN run THEN route-auth, route-reachability, no-admin-idor and semantic-auth pass
- [ ] Implement
- [ ] Test (the real portaliq event classes when present, a stub dispatcher answering the result slot otherwise)

### Task 4: Offer the papers at the declared trust level, with the audit entry
- **spec_ref**: `openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-003-confidential-papers-reach-only-a-named-reader-at-the-declared-trust-level`, `openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-004-every-portal-opening-of-a-confidential-paper-is-in-decidiqs-audit-log-first`
- **files**: `lib/Portal/PortalContributionProvider.php` (`confidentialAgendaItems`, `confidentialPapers()`, `confidentialPaperOpened()`), `lib/Settings/AdminSettings.php` and its settings section (`portal_confidential_min_trust`), `tests/Unit/Portal/PortalContributionProviderTest.php`, `tests/newman/portal-voting-record-and-confidential-papers.json`
- **acceptance_criteria**:
  - GIVEN the setting unset WHEN the contribution is read THEN `confidentialAgendaItems` has `minTrust: substantial`, `scopeClaim: decidiq.personId`, `scopeField: authorisedPersons`, `defaultFilters: {public: false}`
  - GIVEN the setting `high` or an unknown value WHEN read THEN `minTrust` is `high`
  - GIVEN a non-public item WHEN `confidentialPapers()` runs THEN it lists its papers with a file reference; GIVEN the item public again THEN it returns an empty list
  - GIVEN a working audit log WHEN `confidentialPaperOpened()` runs THEN one `material-access` entry with channel portal and the trust level is appended, `verify()` passes and it returns true
  - GIVEN the append fails WHEN it runs THEN it returns false
  - GIVEN portaliq with a stub DigiD envelope at substantial WHEN Pieter Bos opens a paper THEN he receives it and the entry exists; at trust low THEN the collection is absent
  - GIVEN the provider docblock WHEN read THEN it no longer calls DigiD and eHerkenning deferred
- [ ] Implement
- [ ] Test (Newman with portaliq and a stub envelope; the live DigiD run waits on decision D1, integriq#1495)

## Verification

- `composer check:strict` and `npm run lint` once before push.
