# admin-settings Specification Delta

**Status**: proposed
**Scope**: decidiq
**OpenSpec changes**:
- [adopt-connection-registry](../../)

## Purpose

Admins see decidiq's outside connections on one page, with a status the app can back.

## ADDED Requirements

### Requirement: REQ-ADM-CONN-001 Decidiq declares its outside connections in one static file

Decidiq SHALL declare its outside connections in `lib/Settings/connections.json` in the shape of hydra connection-registry design D2 (hydra REQ-CONN-001). The file SHALL declare `ori`, `eidas` and `translation`. `ori` SHALL require `ori_endpoint` and link to the ORI section of the admin page. `eidas` and `translation` SHALL be `reportedOnly`, because a DI binding decides what answers. Every `settingsUrl` SHALL point at a section id that exists on the admin page.

#### Scenario: The declaration names this app and passes integriq's schema
@e2e exclude A static file with no browser surface; tests/Unit/Settings/ConnectionsDeclarationTest.php checks the shape, the app id, unique keys and the anchors.

- **GIVEN** `lib/Settings/connections.json`
- **WHEN** it is validated against integriq's `connections.schema.json`
- **THEN** it SHALL validate
- **AND** its `app` SHALL equal the id in `appinfo/info.xml`
- **AND** every key SHALL be unique

#### Scenario: A saved ORI endpoint reads configured
@e2e tests/e2e/workflows/connection-registry.spec.ts

- **GIVEN** integriq has synced decidiq's declaration
- **WHEN** an admin saves an ORI endpoint on the admin page
- **THEN** decidiq SHALL send `ConnectionRefreshRequestedEvent` for `ori`
- **AND** the ORI row SHALL read Configured

### Requirement: REQ-ADM-CONN-002 Decidiq reports which signing and translation services answer

Decidiq SHALL report the service bound for `eidas` and `translation` with `ConnectionStatusReportedEvent`, once a day and after every settings save, never per request. A log-only fallback SHALL be reported `simulated`. A binding that cannot work SHALL be reported `error` with the reason. Without integriq, decidiq SHALL send nothing and log nothing.

#### Scenario: The log signing service reports simulated
@e2e exclude The binding depends on whether integriq is installed, which a browser run cannot switch; tests/Unit/Service/ConnectionReportServiceTest.php builds the real LogEIDASSignatureService and asserts the report.

- **GIVEN** the container binds `LogEIDASSignatureService`
- **WHEN** the daily report runs
- **THEN** the `eidas` report SHALL be `simulated`
- **AND** its message SHALL say that nothing is signed

#### Scenario: The log translation adapter without a provider reports simulated
@e2e exclude No browser flow can remove a translation provider; tests/Unit/Service/ConnectionReportServiceTest.php builds the real LogTranslationAdapter and asserts the report.

- **GIVEN** the container binds `LogTranslationAdapter` and no integriq translation service resolves
- **WHEN** the daily report runs
- **THEN** the `translation` report SHALL be `simulated`

#### Scenario: Without integriq nothing is sent
@e2e exclude The CI instance installs integriq; tests/Unit/Service/ConnectionReportServiceTest.php and SettingsControllerConnectionReportTest.php cover the absent class and the unchanged save.

- **GIVEN** integriq is not installed
- **WHEN** an admin saves the settings
- **THEN** no event SHALL be sent and nothing SHALL be logged
- **AND** the save SHALL answer as before

### Requirement: REQ-ADM-CONN-003 An admin reads decidiq's connections on an Integrations page

Decidiq SHALL render an `index` page at `/settings/integrations` over `integriq/app_connection`, reached from the settings gear and preset to `app` equal to `decidiq` through its menu entry's `query` (hydra REQ-CONN-006). The page and its menu entry SHALL be admin only. The page SHALL require Integriq, and the menu entry SHALL only render when integriq is installed. The page SHALL NOT offer a generic Add button. Its Add integration action SHALL open `/apps/integriq/connections?app=decidiq&link=1`.

#### Scenario: The page lists only decidiq's rows
@e2e tests/e2e/workflows/connection-registry.spec.ts

- **GIVEN** decidiq and integriq are installed and integriq has synced decidiq's declaration
- **WHEN** an admin opens the Integrations page from the settings gear
- **THEN** the page SHALL list ORI publication, eIDAS signatures and Translation
- **AND** every listed row SHALL have `app` equal to `decidiq`

#### Scenario: Add integration goes to integriq
@e2e tests/e2e/workflows/connection-registry.spec.ts

- **GIVEN** the Integrations page
- **WHEN** the admin chooses Add integration
- **THEN** the browser SHALL open integriq's Connections overview with `app=decidiq` and `link=1`

#### Scenario: A status renders as a word
@e2e exclude The formatter is a pure function and every value is asserted in tests/vitest/connectionRegistry.spec.js; a browser run adds nothing it can observe.

- **GIVEN** a row whose status is `limited`
- **WHEN** the page renders it
- **THEN** the cell SHALL read Limited
