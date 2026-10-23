# Tasks: agenda-item-confidential-papers

## Implementation tasks

### Task 1: Fields and read rules
- **spec_ref**: `openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-001-an-agenda-item-and-each-paper-say-whether-they-are-public`, `#requirement-req-acp-002-only-authorised-readers-can-read-a-non-public-item-and-its-papers`
- **files**: `lib/Settings/register.d/124-agenda-item-confidential-papers.json` (new: `public`, `authorisedReaders`, `authorization.read` on AgendaItem and DigitalDocument), register version move and lock-file test (decision 70)
- **acceptance_criteria**:
  - the read rules are evaluated by OpenRegister's real RBAC evaluator in the test: body member yes, other body no, named reader yes
  - if the dialect cannot express "user in `authorisedReaders`", stop and file an OpenRegister ask; do not filter in decidiq PHP
- [ ] Implement
- [ ] Test (red first): register fragment test with the real evaluator

### Task 2: Item page and Stukken badges (board DcAgendapunt)
- **spec_ref**: `openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-001-an-agenda-item-and-each-paper-say-whether-they-are-public`
- **files**: `src/manifest.json` (AgendaItemDetail Kerngegevens field, Stukken badge, Lezers toevoegen for the secretariat), `l10n/` ("Openbaar", "Besloten", "Besloten punt", "Lezers toevoegen")
- **acceptance_criteria**:
  - matches the board's Kerngegevens and Stukken
- [ ] Implement
- [ ] Test (red first): vitest on the page config

### Task 3: Inheritance and restriction listener
- **spec_ref**: `openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-003-a-paper-of-a-non-public-item-is-not-public`, `#requirement-req-acp-004-an-imposed-confidentiality-restriction-closes-the-item`
- **files**: `lib/Listener/AgendaItemConfidentialityListener.php` (new), `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`
- **acceptance_criteria**:
  - the test constructs the real OpenRegister object events, not fakes
- [ ] Implement
- [ ] Test (red first): `tests/Unit/Listener/AgendaItemConfidentialityListenerTest.php`

### Task 4: Pack and publication leave the item out
- **spec_ref**: `openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-005-a-non-public-item-stays-out-of-the-pack-and-the-public-site`
- **files**: `lib/Service/MeetingPackageService.php`, `lib/Service/PublicationService.php`
- [ ] Implement
- [ ] Test (red first): `tests/Unit/Service/MeetingPackageServiceTest.php`, `PublicationServiceTest.php`

### Task 5: View log
- **spec_ref**: `openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-006-every-read-of-a-closed-paper-is-logged`
- **files**: the paper download path (`lib/Controller/` that serves meeting papers), `lib/Service/AuditLogService.php`
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a unit or vitest test that is red before the code and green after.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- Playwright `tests/e2e/confidential-agenda-item.spec.ts` tagged with each scenario.
