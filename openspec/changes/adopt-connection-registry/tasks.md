# adopt-connection-registry tasks

## 1. Declare

- [ ] 1.1 Write `lib/Settings/connections.json` with `ori`, `eidas` and `translation`.
- [ ] 1.2 Add `id="section-ori"` and `id="section-email-voting"` to `src/views/settings/Settings.vue`.
- [ ] 1.3 Guard the file in `tests/Unit/Settings/ConnectionsDeclarationTest.php`.

## 2. Page

- [ ] 2.1 Add `src/manifest.d/connection-registry.json` with the page and its settings-gear menu entry.
- [ ] 2.2 Add `src/utils/connectionRegistry.js` with the two formatters and the Add integration handler.
- [ ] 2.3 Wire the formatters and the handler in `src/App.vue`.
- [ ] 2.4 Add the strings to `l10n/en` and `l10n/nl`.
- [ ] 2.5 Cover it in `tests/vitest/connectionRegistry.spec.js`.

## 3. Reports

- [ ] 3.1 Add `lib/Service/ConnectionReportService.php`.
- [ ] 3.2 Add `lib/BackgroundJob/ConnectionReportJob.php` and register it in `appinfo/info.xml`.
- [ ] 3.3 Report and refresh from `SettingsController::update()`.
- [ ] 3.4 Add the integriq event stubs to `tests/Stubs`, `tests/bootstrap-unit.php` and `psalm.xml`.
- [ ] 3.5 Cover it in `ConnectionReportServiceTest`, `ConnectionReportJobTest` and `SettingsControllerWriteTest`.

## 4. End to end

- [ ] 4.1 Write `tests/e2e/workflows/connection-registry.spec.ts`.
- [ ] 4.2 Install integriq in the CI `additional-apps`.
