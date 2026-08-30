# Tasks — admin-settings-v1

## 1. Schema (root-cause fix, additive)

- [x] 1.1 ADR-037 fragment `lib/Settings/register.d/42-admin-settings-v1.json`:
      `Participant.properties.governanceBody`, `Meeting.properties.governanceBody`,
      `GovernanceBody.properties.processTemplate`,
      `GovernanceBody.properties.additionalTemplates`.

## 2. Backend

- [x] 2.1 `lib/Service/MemberImportService.php` — `listGroups()`,
      `getGroupMembers(string $groupId)`, `matchEmails(array $emails)`
      (validate + 500-row cap).
- [x] 2.2 `lib/Controller/MemberImportController.php` — `groups()`, `groupMembers()`,
      `match()`, all `#[AuthorizedAdminSetting(AdminSettings::class)]`; routes in
      `appinfo/routes.php`.
- [x] 2.3 `lib/Service/SettingsService.php` — organization CONFIG_KEYS
      (`organisation_name`, `organisation_logo`, `organisation_timezone`,
      `organisation_locale`, `organisation_currency`).

## 3. Frontend

- [x] 3.1 Extract the inline add-member dialog from `GovernanceBodyMembersTab.vue` to
      `src/modals/MemberAddDialog.vue` (pre-existing modal-isolation fix).
- [x] 3.2 `src/modals/MemberRoleDialog.vue` — change-role NcSelect (inputLabel) +
      row action in the members tab.
- [x] 3.3 `src/utils/memberImport.js` — CSV parse + row validation + duplicate
      detection + role defaulting (client 500-row cap).
- [x] 3.4 `src/modals/MemberGroupImportDialog.vue` — group picker, member preview,
      duplicate skip, batch create via OR object store.
- [x] 3.5 `src/modals/MemberCsvImportDialog.vue` — file input, validation preview,
      NC-account match via `/api/member-import/match`, batch create.
- [x] 3.6 `src/components/tabs/processTemplates.js` + `GovernanceBodyTemplateTab.vue`
      — default + specialized template assignment; register in `src/registry.js` and
      `src/manifest.json` sidebarTabs.
- [x] 3.7 `src/views/settings/Settings.vue` — Organization defaults section.
- [x] 3.8 i18n keys (English source) in `l10n/en.json`, `en_US.json`, `nl.json`.

## 4. Tests

- [x] 4.1 PHPUnit `tests/unit/Service/MemberImportServiceTest.php` — mapping,
      validation, cap.
- [x] 4.2 PHPUnit `tests/unit/Service/SettingsServiceOrganisationTest.php` — org
      config round-trip.
- [x] 4.3 vitest `tests/vitest/memberImport.spec.js` — CSV parse/preview matrix.
- [x] 4.4 Playwright `tests/e2e/spec-coverage/admin-settings.spec.ts` — @e2e
      annotations for the five newly-covered scenarios, defensive skips.
- [x] 4.5 Newman `tests/integration/decidesk-admin-settings.postman_collection.json`
      + wire into `tests/newman/run-all.sh` (admin 200 / unauth 401 / non-admin 403 /
      validation 422/413).

## 5. Verification

- [x] 5.1 `npm run build` green; PHPUnit + vitest suites green.
- [x] 5.2 All 24 hydra gates green.
- [x] 5.3 Spec delta synced; main spec frontmatter updated honestly.
