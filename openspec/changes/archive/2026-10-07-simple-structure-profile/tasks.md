# Tasks: simple-structure-profile

## Implementation tasks

### Task 1: The profile mechanism
- **spec_ref**: `openspec/changes/simple-structure-profile/specs/app-navigation/spec.md#requirement-req-ssp-001-two-structures-are-built-from-one-manifest`
- **files**: `src/utils/structureProfile.js`, `src/main.js`, `package.json`, `package-lock.json`
- [x] Implement
- [x] Test

### Task 2: The simple menu
- **spec_ref**: `openspec/changes/simple-structure-profile/specs/app-navigation/spec.md#requirement-req-ssp-002-the-simple-menu-shows-eight-entries-under-three-captions`
- **files**: `src/menu-layout.simple.json`, `src/config/modeLabels.js`, `l10n/nl.json`
- [x] Implement
- [x] Test

### Task 3: Links for what left the menu, and the Registers page
- **spec_ref**: `openspec/changes/simple-structure-profile/specs/app-navigation/spec.md#requirement-req-ssp-003-nothing-the-full-menu-offers-is-lost`
- **files**: `src/menu-layout.simple.json`, `src/manifest.d/registers-hub.json`
- [x] Implement
- [x] Test

### Task 4: The setting
- **spec_ref**: `openspec/changes/simple-structure-profile/specs/app-navigation/spec.md#requirement-req-ssp-004-the-structure-is-an-app-setting-and-simple-is-the-default`
- **files**: `lib/Service/Settings/MenuStructure.php`, `lib/Service/SettingsService.php`, `lib/Controller/DashboardController.php`, `lib/Settings/AdminSettings.php`, `src/views/settings/tabs/MenuStructureTab.vue`, `src/services/menuStructureSetting.js`, `src/views/settings/AdminRoot.vue`, `l10n/en.json`, `l10n/nl.json`
- [x] Implement
- [x] Test

### Task 5: Tests in the scripts CI runs
- **files**: `tests/vitest/structureProfile.spec.js`, `tests/Unit/Service/Settings/MenuStructureTest.php`, `tests/Unit/Controller/DashboardControllerTest.php`, `tests/e2e/simple-structure-menu.spec.ts`, `tests/e2e/ci-seed.sh`
- [x] Implement
- [x] Test (the e2e spec is written and listed, not run: no throwaway instance in this lane)
