# Tasks: portal-labels-in-the-readers-language

### Task 1: Labels through decidiq's catalogue
- **spec_ref**: `openspec/changes/portal-labels-in-the-readers-language/specs/portal-contribution/spec.md#requirement-req-plr-001-a-resident-reads-decidiqs-portal-labels-in-their-own-language`
- **files**: `lib/Portal/PortalLabelTranslator.php`, `lib/Portal/PortalContributionProvider.php`, `l10n/en.json`, `l10n/nl.json`, `l10n/en.js`, `l10n/nl.js`, `tests/Unit/Portal/PortalContributionProviderTest.php`
- **acceptance_criteria**:
  - GIVEN a Dutch reader WHEN portaliq asks for the citizen contribution THEN the heading reads "Raad en inspraak" and `citizenVotes` reads "Mijn stemmen"
  - GIVEN the translated manifest WHEN read THEN ids, schema slugs, field names and default filters are unchanged
  - GIVEN a label without an entry in `l10n/nl.json` WHEN the unit tests run THEN they fail
  - GIVEN no factory WHEN the provider is built with `new` THEN it answers in English as before
- [x] Implement
- [x] Test

## Verification

- `composer check:strict` once before push; `npm run test:l10n`, `npm run check:l10n-js`.
