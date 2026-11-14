# Tasks: publication-papers-and-search

## Implementation tasks

### Task 1: Papers and filter fields in the publication
- **spec_ref**: `openspec/changes/publication-papers-and-search/specs/agenda-publication/spec.md#requirement-req-pps-001-public-papers-are-published-with-the-agenda`
- **files**: `lib/Service/PublicationPayloadService.php`, `lib/Service/OpenCatalogiPublisher.php`
- **acceptance_criteria**:
  - GIVEN a public agenda with three items with papers, one confidential WHEN it is published THEN the publication has the papers of the two public items and the body, date and type
- [x] Implement
- [x] Test (red first): tests/Unit/Service/PublicationConfidentialityTest.php (3, red on development 5ab7aeef), tests/Unit/Service/PublicationPapersTest.php (7)

## Verification

- Each task has a unit or vitest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real register schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `test:l10n`, `check:l10n-js`, `check:schema-l10n` and the hydra gates `--scope-to-diff` once before push.
- A Playwright spec under `tests/e2e/` tagged with each scenario.
