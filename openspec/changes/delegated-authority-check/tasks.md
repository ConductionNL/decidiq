# Tasks: delegated-authority-check

- [x] 1. Add `delegateUser`, `delegateGroup` and `acts` to `bevoegdheidstoedeling`; bump schema and register versions
  - spec_ref: `specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-008-another-app-can-ask-whether-an-account-holds-an-authority-for-an-act`
  - files: `lib/Settings/register.d/54-delegatie-mandaatregister.json`, `lib/Settings/decidesk_register.json`
- [x] 2. `Service/DelegatedAuthorityCheck` with unit tests on a neutral fixture act, rows validated against the merged schema
  - files: `lib/Service/DelegatedAuthorityCheck.php`, `tests/Unit/Service/DelegatedAuthorityCheckTest.php`
  - Fixed 27 Nov 2026 (found by the delegatie-mandaatregister code review): the rename to `authority-delegation` (fragment 86) did not declare `delegateUser`, `delegateGroup` and `acts`, so the copy dropped them, and the check, `MandateDirectory` and `DecisionAuthority` still read the retired `bevoegdheidstoedeling`, which no longer follows a withdrawal recorded on the copy. Fragment `140-delegation-account-fields.json` declares the three fields on `authority-delegation`; the three services read through `AuthorityDelegationRows`, which answers from `authority-delegation` and finds a pre-rename id through the copy's `migratedFromObject`; the rename repair step now carries those fields onto a copy made before they were declared, never replacing a value the copy holds. Tests: `AuthorityDelegationRowsTest`, `MigrateTheLastTwoDutchNamesTest` (+3), `DelegatedAuthorityCheckTest` and `ParaferingRouteRuntimeTest` now on `authority-delegation`.
- [ ] 3. Mandate matrix CSV import through OpenRegister's import preview (C23), follow-up change
