# Tasks: delegated-authority-check

- [x] 1. Add `delegateUser`, `delegateGroup` and `acts` to `bevoegdheidstoedeling`; bump schema and register versions
  - spec_ref: `specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-008-another-app-can-ask-whether-an-account-holds-an-authority-for-an-act`
  - files: `lib/Settings/register.d/54-delegatie-mandaatregister.json`, `lib/Settings/decidesk_register.json`
- [x] 2. `Service/DelegatedAuthorityCheck` with unit tests on a neutral fixture act, rows validated against the merged schema
  - files: `lib/Service/DelegatedAuthorityCheck.php`, `tests/Unit/Service/DelegatedAuthorityCheckTest.php`
- [ ] 3. Mandate matrix CSV import through OpenRegister's import preview (C23), follow-up change
