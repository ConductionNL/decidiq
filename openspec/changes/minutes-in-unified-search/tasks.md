# Tasks: minutes-in-unified-search

## Implementation tasks

### Task 1: Search minutes
- **spec_ref**: `openspec/changes/minutes-in-unified-search/specs/nextcloud-integration/spec.md#requirement-req-mus-001-minutes-appear-in-nextclouds-unified-search`
- **files**: `lib/Search/DecidiqSearchProvider.php`, `tests/Unit/Search/DecidiqSearchProviderTest.php`
- **acceptance_criteria**:
  - GIVEN approved minutes whose content mentions "woningbouw" WHEN a member searches "woningbouw" THEN a Minutes entry with the approval date links to `/minutes/{id}`
  - GIVEN the provider test WHEN it asserts the queried schemas THEN `minutes` is among them (red before the change)
  - GIVEN any entry WHEN rendered THEN its subline contains no em-dash
- [ ] Implement
- [ ] Test

### Task 2: Read rules hold
- **spec_ref**: `openspec/changes/minutes-in-unified-search/specs/nextcloud-integration/spec.md#requirement-req-mus-002-search-shows-only-minutes-the-searcher-may-read`
- **files**: `tests/e2e/unified-search.spec.ts`
- **acceptance_criteria**:
  - GIVEN minutes a member may not read WHEN he searches a word in them THEN they are not listed
- [ ] Implement
- [ ] Test (Playwright on the Nextcloud search bar)

## Verification

- `composer check:strict` and `npm run lint` once before push.
