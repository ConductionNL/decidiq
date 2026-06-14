# Tasks: ia-six-item-nav

<!-- Config-first: restructure the manifest menu, then the label-map scaffold,
     then wire the shell, then the backend setting, then the admin UI.
     Column-0 `- [ ]` count is capped at 20 by the supervisor; acceptance
     criteria are plain text bullets. -->

## Implementation Tasks

### Task 1: Restructure the manifest menu to the six-item IA
- **spec_ref**: `openspec/changes/ia-six-item-nav/specs/app-navigation/spec.md#requirement-req-nav-002-mainmenu-lists-primary-entity-routes`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN the menu is rewritten THEN the main section lists exactly Dashboard (landing) + Meetings + Decisions + Action items + Motions + Bodies
  - GIVEN the menu WHEN inspected THEN Minutes, Workspaces, and Engagement no longer appear as top-level items and Documentation/Settings/Features-roadmap remain in footer/settings
  - GIVEN the edited file WHEN parsed THEN it is valid JSON
- [x] Implement
- [x] Test

### Task 2: Promote GovernanceBodies into the menu as Bodies
- **spec_ref**: `openspec/changes/ia-six-item-nav/specs/app-navigation/spec.md#requirement-req-nav-002-mainmenu-lists-primary-entity-routes`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN the Bodies item is added THEN it routes to `GovernanceBodies` (`/governance-bodies`) with the GovernanceBodies icon and a canonical label "Bodies"
  - GIVEN the nav renders WHEN a user clicks Bodies THEN the GovernanceBodies page opens
- [x] Implement
- [x] Test

### Task 3: Keep demoted routes registered
- **spec_ref**: `openspec/changes/ia-six-item-nav/specs/app-navigation/spec.md#requirement-req-nav-003-router-uses-history-mode-with-flat-named-routes`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest pages WHEN inspected THEN Minutes (`/minutes`), Workspaces (`/workspaces`), and Engagement (`/engagement`) pages/routes still exist
  - GIVEN a direct navigation to `/minutes`, `/workspaces`, or `/engagement` THEN the page renders despite the item not being in the top menu
- [x] Implement
- [x] Test

### Task 4: Add the declarative mode label map
- **spec_ref**: `openspec/changes/ia-six-item-nav/specs/app-navigation/spec.md#requirement-req-nav-006-mode-aware-label-resolution-at-the-translate-chokepoint`
- **files**: `src/config/modeLabels.js`
- **acceptance_criteria**:
  - GIVEN the module WHEN imported THEN it exports `MODE_LABELS` keyed by gov/corp/assoc/ops/citizen and `DEFAULT_MODE = 'gov'`
  - GIVEN `MODE_LABELS` WHEN read THEN the Bodies entry maps to "Fracties & Organen" (gov), "Board" (corp), "Teams" (ops), with the other modes/items scaffolded
- [x] Implement
- [x] Test

### Task 5: Wire mode-aware label resolution in the app shell
- **spec_ref**: `openspec/changes/ia-six-item-nav/specs/app-navigation/spec.md#requirement-req-nav-006-mode-aware-label-resolution-at-the-translate-chokepoint`
- **files**: `src/App.vue`, `src/store/` (settings store)
- **acceptance_criteria**:
  - GIVEN `translateForApp(key)` WHEN called THEN it resolves the canonical label through `MODE_LABELS` for the active `organisatie_modus` (from the settings store) before calling `t('decidesk', …)`, falling back to the canonical label when unmapped
  - GIVEN `organisatie_modus = corp` WHEN the nav renders THEN the Bodies label shows "Board"; GIVEN no mode set THEN gov labels show
- [x] Implement
- [x] Test

### Task 6: Add organisatie_modus setting (backend + admin UI)
- **spec_ref**: `openspec/changes/ia-six-item-nav/specs/admin-settings/spec.md#requirement-req-adm-mode-001-organisatie-modus-tenant-setting`
- **files**: `lib/Service/SettingsService.php`, `src/views/Settings.vue` (admin settings UI)
- **acceptance_criteria**:
  - GIVEN `SettingsService` WHEN `getSettings()` runs THEN `organisatie_modus` is returned defaulting to `gov`, and `updateSettings()` persists it via `IAppConfig`
  - GIVEN the admin settings UI WHEN opened THEN a mode selector (`NcSelect` with `inputLabel`) offers gov/corp/assoc/ops/citizen and saving updates the setting
- [x] Implement
- [x] Test

## Verification

- [x] All tasks checked off and `openspec validate ia-six-item-nav --strict` passes
- [ ] Manual: nav shows six working items + Dashboard, no Minutes/Workspaces/Engagement rows, Bodies present
- [ ] Manual: switching `organisatie_modus` to corp relabels Bodies to "Board"; demoted deep links still resolve

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/` — `SettingsService` organisatie_modus default + persist)
- Frontend label-resolution covered by a Vitest unit test (`translateForApp` / `modeLabels`)
- UI changes covered by Playwright browser tests (nav six items, Bodies relabel, mode selector)
- All tests pass (`composer test`, vitest)
- Feature documentation updated in `docs/` if user-facing (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) strings added for the new mode labels and the mode-selector UI (ADR-005); i18n keys are the English source strings
- `openspec validate` passes
