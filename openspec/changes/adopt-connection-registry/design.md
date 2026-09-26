# Design: adopt-connection-registry

The contract is hydra `openspec/changes/connection-registry/design.md`. This file records how decidiq meets it and where it fits loosely.

## D1. Which connections are declared

Each candidate was checked against the code on `development`, not against its name.

| Key | Declared as | Why |
|---|---|---|
| `ori` | `requiredConfig: ["ori_endpoint"]`, settings link `#section-ori` | `OriPublicationService::publish()` posts to `ori_endpoint` and skips the post when it is empty. |
| `eidas` | `reportedOnly: true` | `DomainServiceRegistrar::registerEidasBindings()` picks the service at request time from whether integriq's `CallService` resolves. No config key decides it. |
| `translation` | `reportedOnly: true` | `LogTranslationAdapter` is always bound, and `MultilingualReconciliationService` calls it from `TranslationQueueJob` and its controller. It delegates only when an integriq translation service resolves. |

**Why `ori_bearer_secret` is not required.** `publish()` omits the `Authorization` header when the secret is empty, and an ORI endpoint without auth works. The admin page also has no field for the secret. Requiring it would keep a working, form-configured endpoint on Not configured.

**Why translation is not `available: false`.** Something calls it. `TranslationQueueJob` is registered in `appinfo/info.xml` and processes the queue on a schedule. A row that says Not available would hide a queue returning untranslated text.

**Why email voting is not declared.** `MailReplyHandler` reads signed `_mail` entries stored on a voting round object. It opens no mailbox and calls no mail server, and `Application::boot()` does not register it. Outgoing notices use Nextcloud's own `IMailer`. Nothing leaves through a connection decidiq configures.

## D2. What the report says

`ConnectionReportService::reportBindings()` sends one `ConnectionStatusReportedEvent` per row.

**eidas**, from the service the container binds to `IEIDASSignatureService`:

- `LogEIDASSignatureService` gives `simulated`: the request is logged and nothing is signed. Rule 4a keeps it against a newer green probe.
- `EIDASSignatureService` with no integriq source lookup gives `error`. The service asks for `Db\SourceMapper`, which integriq does not ship, so every signing request fails.
- With a lookup, `docudesk-signing` or `eidas-qes` present gives `configured`, and the message says decidiq does not test it. Neither present gives `unconfigured`, naming both slugs.
- Any other bound class gives `configured`, naming the class.
- A binding that throws gives `error`.

**translation**, from the adapter bound to `ITranslationAdapter`:

- `LogTranslationAdapter` with an integriq translation service that resolves gives `configured`.
- `LogTranslationAdapter` without one gives `simulated`: the original text comes back.
- Any other bound class gives `configured`, naming the class.
- A binding that throws gives `error`.

The service reuses `EIDASSignatureService::ESIGN_SOURCE_SLUG`, `DOCUDESK_SOURCE_SLUG` and `LogTranslationAdapter::OPENCONNECTOR_SERVICES`, so the report asks the same names the code asks.

## D3. When it reports

- `ConnectionReportJob`, a `TimedJob` once a day. A binding changes with an install or a deploy, not per request (ADR-076).
- `SettingsController::update()`, after the save. The report costs two container lookups and two events.
- The same save sends `ConnectionRefreshRequestedEvent('decidiq', 'ori')` when the payload names `ori_endpoint` or `ori_bearer_secret`. Integriq reads the saved value and decides.

Both events are named by string constant and built only when the class exists (ADR-041). Without integriq nothing is sent or logged. A listener that throws is caught and logged, and never reaches the save.

The controller takes the service as an optional last argument, so a missing service never breaks a settings save.

## D4. The page

- `src/manifest.d/connection-registry.json`: an `index` page `ConnectionRegistry` at `/settings/integrations`, `requiresApp` integriq, `permission: admin`, `showAdd: false`.
- Its menu entry `ConnectionRegistryMenu` sits in the settings gear, with `query: {app: decidiq}`, `permission: admin` and `visibleIf.appInstalled: integriq`. A settings entry does not count against ADR-097's budget.
- The id is not `Integrations`, so it never collides with the per-object integration pages (`MeetingIntegrations`, `MotionIntegrations` and the rest).
- Columns follow dossiq: connection, status, status message, last checked, settings.
- `src/utils/connectionRegistry.js` holds `connectionStatus`, `connectionSettingsLabel` and `openIntegriqConnections`.

**Formatters.** The installed `@conduction/nextcloud-vue` 2.39.0 ships no `connectionStatus` built-in, so decidiq carries a local copy with all six labels, `limited` included.

**Handler.** `CnIndexPage` resolves a header action's handler name against `customComponents` only, not against `registry`. `App.vue` therefore passes the one handler through `customComponents`. CnAppRoot logs a one-time deprecation warning for that prop beside a v2 manifest; no other route exists in 2.39.0.

## Risks

- **Integriq on `development` refuses `reportedOnly`.** Its schema is `additionalProperties: false` until the hydra#673 amendment ships there. Until then no row appears and the page is empty. No decidiq request fails.
- **A report before integriq's first sync is refused** with a warning in integriq. The next daily run lands.
- **The eIDAS row will read Error on most instances with integriq.** That is true today, not a flaw in the report.
