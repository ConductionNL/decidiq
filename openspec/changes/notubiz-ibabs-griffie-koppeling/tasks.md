# Tasks — NOTUBIZ en iBabs Griffie-Koppeling (Bidirectional Sync)

> Scope reminder: this change implements bidirectional synchronization between decidesk and NOTUBIZ/iBabs via openconnector adapters. See `proposal.md`, `design.md`, and `specs/*/spec.md` for context.
>
> Acceptance gates: every task's checkbox flips only when its acceptance criteria pass. Do not mark tasks done by inspection — run the listed commands.

## 1. Schema definitions and seed data

- [ ] 1.1 Create `openconnector/lib/Settings/decidesk_sync_register.json` with 4 new schemas:
  - `ExternalIdentifier` (polymorph sync binding)
  - `SyncJob` (execution log)
  - `SyncConflict` (conflict tracking)
  - `FractieRol` (fraction role data)
  Per ADR-001 (data-layer), MUST include 3–5 seed objects per schema with realistic Dutch values (gemeente names, dates, person names per KNAW naming conventions).
  **Acceptance:** `composer check:strict` is clean; schema validation passes; seed objects load via `ConfigurationService::importFromApp()` without duplicates on re-import.

- [ ] 1.2 Extend `decidesk/lib/Settings/decidesk_register.json` to add optional `externalIdentifier` FK relation to domain schemas:
  - `Meeting` → `ExternalIdentifier`
  - `AgendaItem` → `ExternalIdentifier`
  - `Person` → `ExternalIdentifier`
  - `Motion`, `Amendment`, `Vote`, `Decision`, `Vergaderstuk` — same pattern
  (Backwards-compatible; no data migration required for existing objects.)
  **Acceptance:** Schema validation passes; no existing decidesk objects are modified; FK relations resolve correctly in `ObjectService::findAll()`.

---

## 2. NOTUBIZ adapter

- [ ] 2.1 Create `openconnector/apps/notubiz-adapter/` with app structure:
  - `appinfo/info.xml` (app metadata)
  - `lib/NotubizAdapterApp.php` (app entry point)
  - `lib/Service/NotubizApiClient.php` (REST client, OAuth2 auth, rate-limit handling)
  - `lib/Service/NotubizFieldMapper.php` (vendor fields → decidesk schema mapping)
  - Unit tests at `tests/Unit/Service/NotubizApiClientTest.php` and `NotubizFieldMapperTest.php`
  **Acceptance:** `composer check:strict` is clean; unit tests pass; PHPStan level 8.

- [ ] 2.2 Implement `NotubizApiClient` with methods:
  - `getMeetings($modifiedSince, $limit, $offset)` → REST GET `/meetings?modified-since=...`
  - `getAgendaItems($meetingId)` → REST GET `/agenda-items?meeting_id=...`
  - `getDocuments($agendaItemId)` → REST GET `/documents?agenda_item_id=...`
  - `getVoting($meetingId)` → REST GET `/voting?meeting_id=...`
  - `getDecisions($meetingId)` → REST GET `/decisions?meeting_id=...`
  - `getMotions($meetingId)` → REST GET `/motions?meeting_id=...`
  - `getAmendments($motionId)` → REST GET `/amendments?motion_id=...`
  - `getAttendance($meetingId)` → REST GET `/meetings/{id}/attendance`
  Rate-limit: 60 requests/min per tenant (token-bucket backpressure in client).
  **Acceptance:** Unit tests mock HTTP responses; all methods handle 200/404/403/429 status codes correctly; rate-limit backpressure tested.

- [ ] 2.3 Implement `NotubizFieldMapper` mapping vendor fields to decidesk schemas:
  - NOTUBIZ `Meeting` → decidesk `Meeting` (map `id`, `title`, `date`, `location`, `status`)
  - NOTUBIZ `AgendaItem` → decidesk `AgendaItem` (map `id`, `title`, `order`, `duration`)
  - NOTUBIZ `Document` → decidesk `Vergaderstuk` (map `id`, `filename`, `size`, `confidentiality_level`)
  - NOTUBIZ `Vote` → decidesk `Vote` (map `id`, `person_id`, `value` ∈ {voor, tegen, onthouden}, `timestamp`)
  - etc. for Motion, Amendment, Decision, Attendance
  **Acceptance:** Mapper is stateless; unit tests verify each entity type maps correctly; no field is dropped without explicit comment explaining why.

- [ ] 2.4 Create NotubizAdapter background job runner:
  - `lib/Job/NotubizFullSyncJob.php` (full import for date range; triggered manually or on calendar-year change)
  - `lib/Job/NotubizIncrementalSyncJob.php` (cron-triggered every 15 min; delta-pull via `modified-since`)
  - `lib/Job/NotubizPushJob.php` (push local changes back to NOTUBIZ via PATCH/POST)
  Register in `appinfo/info.xml` as IJobList jobs.
  **Acceptance:** Jobs are enqueueable via OpenConnector's job runner; unit tests verify job execution flow.

---

## 3. iBabs adapter

- [ ] 3.1 Create `openconnector/apps/ibabs-adapter/` with app structure:
  - `appinfo/info.xml` (app metadata)
  - `lib/IbabsAdapterApp.php` (app entry point)
  - `lib/Service/IbabsApiClient.php` (SOAP + JSON-REST client, OAuth2 auth)
  - `lib/Service/IbabsFieldMapper.php` (vendor fields → decidesk schema mapping)
  - `lib/Controller/WebhookController.php` (receive `meeting.updated` webhooks)
  - Unit tests
  **Acceptance:** `composer check:strict` is clean; unit tests pass; PHPStan level 8.

- [ ] 3.2 Implement `IbabsApiClient` with methods:
  - `getMeetings($modifiedSince, ...)` → JSON-REST GET or SOAP call
  - `getAgendaItems($meetingId)` → JSON-REST
  - `getDocuments($agendaItemId)` → JSON-REST
  - `getVoting($meetingId)` → JSON-REST
  - `getDecisions($meetingId)` → JSON-REST
  - `getMotions($meetingId)` → JSON-REST
  - `getAmendments($motionId)` → JSON-REST
  - `getAttendance($meetingId)` → JSON-REST
  Webhook endpoints documented in iBabs API portal for Notifications module.
  **Acceptance:** Unit tests verify SOAP/REST dual support; error handling for both protocols.

- [ ] 3.3 Implement `IbabsFieldMapper` with same entity coverage as NOTUBIZ mapper (Meeting, AgendaItem, Document, Vote, Motion, Amendment, Decision, Attendance).
  Map iBabs field names → decidesk schema.
  **Acceptance:** Mapper is stateless; unit tests verify each entity type maps correctly.

- [ ] 3.4 Create `IbabsAdapterWebhookController` to receive `meeting.updated` webhooks:
  - `POST /apps/ibabs-adapter/webhook` handler
  - Verify webhook signature (HMAC-SHA256 against configured secret)
  - Extract meeting UUID; trigger incremental sync for that meeting
  - Respond 202 Accepted; async job processes the actual sync
  **Acceptance:** Unit tests verify signature validation; integration test ensures webhook → job trigger.

- [ ] 3.5 Create iBabs background job runner (similar to NOTUBIZ):
  - `lib/Job/IbabsFullSyncJob.php` (full import for date range)
  - `lib/Job/IbabsIncrementalSyncJob.php` (cron-triggered every 15 min; can also be triggered by webhook)
  - `lib/Job/IbasPushJob.php` (push changes back)
  **Acceptance:** Jobs are enqueueable; unit tests verify flow.

---

## 4. Sync orchestration engine

- [ ] 4.1 Create `openconnector/lib/Service/SyncService/SyncService.php`:
  - Orchestrates pull, push, conflict detection across all adapters.
  - Constructor injects: `ObjectService`, `AdapterRegistry` (discovery of NOTUBIZ + iBabs adapters), `CrdtService` (conflict engine), `RelationService`, `FileService`.
  - Public methods:
    - `pullIncremental(string $provider, string $organization): SyncJobResult` — delta-pull via adapter.
    - `pullFull(string $provider, string $organization, string $fromDate): SyncJobResult` — full import.
    - `push(string $provider, string $organization, array $changes): SyncJobResult` — push local changes.
    - `detectConflicts(string $provider): array` — find all open conflicts.
    - `resolveConflict(string $conflictId, string $resolution, string $userId): bool` — manual resolution.
  - Transactions: all-or-nothing per SyncJob (rollback on partial failure).
  **Acceptance:** `composer check:strict` is clean; unit tests for happy path + error cases; transaction boundaries verified.

- [ ] 4.2 Implement `SyncService::pullIncremental()`:
  - Call `adapter.getObjects($modifiedSince, $limit, $offset)` for each entity type (Meeting, AgendaItem, etc.).
  - For each object, check if ExternalIdentifier exists via `RelationService::findRelations()`.
  - If exists, merge (update) via `ObjectService::saveObject()` + create audit trail.
  - If not exists, create new object + create ExternalIdentifier.
  - Create SyncJob record with counts: `objectsProcessed`, `objectsCreated`, `objectsUpdated`, `objectsSkipped`, `status: success`.
  - On adapter error: catch, log to `SyncJob.errorLog`, set `status: failed`.
  **Acceptance:** Unit tests mock adapter; verify correct CRUD sequence; SyncJob counts are accurate.

- [ ] 4.3 Implement `SyncService::pullFull()`:
  - Similar to incremental, but no `modifiedSince` filter; paginate through entire history.
  - Deduplicate by `(Meeting.date + Meeting.name)` if provider supports it (NOTUBIZ does).
  - Stream documents via `FileService::saveFile()` (not in-memory load).
  - Create SyncJob with scope: `full`.
  **Acceptance:** Unit tests verify pagination; document streaming tested with mock file.

- [ ] 4.4 Implement `SyncService::push()`:
  - Accept array of local changes: `[{objectId, objectType, field, newValue, oldValue}, ...]`.
  - For each change:
    - Check if ExternalIdentifier exists; if not, skip (local-only object).
    - Check if field is writable in provider config (per `(org, provider, objecttype, field)` allowlist).
    - Call `adapter.updateObject(externalId, field, newValue)` via PATCH/POST.
  - Handle rejection: create SyncConflict with `resolution: provider-rejected`.
  - Create SyncJob with counts.
  **Acceptance:** Unit tests verify write check; rejection handling; non-writable field skip.

- [ ] 4.5 Implement `CrdtService::detectConflicts()`:
  - Scan all ExternalIdentifier records with `lastModifiedLocal ≠ lastModifiedExternal`.
  - For each, compare `localValue` vs `externalValue` (via `ObjectService::getObject()` + adapter `getObject(externalId)`).
  - If both changed since `lastSyncedAt`: create SyncConflict with both values, timestamps, who changed.
  - Return array of new conflicts.
  **Acceptance:** Unit tests verify conflict detection; timestamp comparison logic verified.

- [ ] 4.6 Implement `SyncService::resolveConflict()`:
  - Accept `conflictId`, `resolution` (enum: local, external, merged), `mergedValue` (if merged), `userId`.
  - Update SyncConflict: `status`, `resolvedBy`, `resolvedAt`, `resolution` (text).
  - If local/merged: update the decidesk object; push change back to provider if push is enabled.
  - If external: pull the external value into decidesk.
  - Update ExternalIdentifier `lastSyncedAt = now`.
  **Acceptance:** Unit tests verify all three resolution paths; audit trail is created.

---

## 5. Admin UI and observability

- [ ] 5.1 Create `openconnector/lib/Controller/SyncStatusController.php` with route:
  - `GET /apps/openconnector/sync-status` — admin panel page
  - Render last 24h SyncJob list (per-provider, status distribution, counts)
  - Show open SyncConflict queue (count, oldest conflict age, notification status)
  - Show sync latency (avg pull time, last push time, next cron trigger)
  - Show quota usage (NOTUBIZ: requests/min; iBabs: API calls/day)
  - Render overall health status: `ok`, `degraded`, `failed`
  **Acceptance:** Page loads without errors; SyncJob data is accurate; health status logic verified by unit tests.

- [ ] 5.2 Create conflict-resolution UI:
  - `GET /apps/openconnector/conflicts` — list open conflicts (sortable, paginated)
  - `GET /apps/openconnector/conflicts/{id}` — detail view with both values side-by-side
  - `PATCH /apps/openconnector/conflicts/{id}` — submit resolution (local/external/merged)
  - Display: field name, localValue, externalValue, who changed (local user + external user), when
  - Show audit trail of past resolutions on this object
  **Acceptance:** UI is accessible; resolution submission updates SyncConflict; form validation works.

- [ ] 5.3 Create alerting:
  - On 3 consecutive SyncJob failures: call PagerDuty/Slack/e-mail via configured webhook.
  - On >25 open SyncConflicts: emit event to set connector-status `degraded`.
  - On SyncConflict remaining open >7 days: send reminder notification to griffier.
  - All alerts logged in SyncJob + SyncConflict audit trails.
  **Acceptance:** Unit tests verify alert conditions; mock webhook endpoints receive expected payloads.

---

## 6. Per-organization configuration and secrets

- [ ] 6.1 Create admin settings page:
  - `GET /admin/settings/sync-configuration` — form to add/edit provider connection
  - Input fields: provider name (NOTUBIZ/iBabs), organization, API endpoint, API key, OAuth2 credentials (encrypted storage via `openregister` secret manager)
  - Checkbox: enable bidirectional sync (default: read-only)
  - Per-entity checkboxes: which object types to sync
  - Per-object-type field allowlist: which fields can be pushed back to provider
  - Cron schedule: sync frequency (default 15 min)
  - Webhook secret (for iBabs)
  - Test connection button: calls adapter `testConnection()` → returns success/error
  **Acceptance:** Form saves credentials encrypted; form loads existing config; test button works.

- [ ] 6.2 Implement `ConfigurationService` for provider config:
  - `getConfiguration(string $provider, string $organization): array` — load from encrypted storage
  - `saveConfiguration(string $provider, string $organization, array $config): void` — encrypt + persist
  - `testConnection(string $provider, string $organization): bool` — calls adapter health check
  - `getAdapterRegistry(): AdapterRegistry` — discover all registered adapters (NOTUBIZ, iBabs, future Companion)
  **Acceptance:** Unit tests verify encryption/decryption; integration test verifies adapter discovery.

---

## 7. Integration tests and contract tests

- [ ] 7.1 Create integration tests:
  - `tests/Integration/SyncService/SyncServiceTest.php` — end-to-end pull → update → conflict detection
  - Mock NOTUBIZ adapter responses; verify SyncJob, ExternalIdentifier, and conflict flow
  - Test full import, incremental pull, push, conflict resolution
  - Verify transaction boundaries (all-or-nothing per SyncJob)
  **Acceptance:** All integration tests pass; >80% code coverage for SyncService.

- [ ] 7.2 Create adapter contract tests:
  - `tests/Contract/NotubizApiContractTest.php` — hits real NOTUBIZ sandbox endpoint (or VCR cassette)
  - `tests/Contract/IbabsApiContractTest.php` — hits real iBabs sandbox endpoint (or VCR cassette)
  - Verify endpoint availability, response format, rate-limit headers
  - Run daily in CI; alert if test fails (indicates vendor API breakage)
  **Acceptance:** Contract tests run daily; VCR cassettes recorded against sandbox; CI integration working.

- [ ] 7.3 Create performance tests:
  - Simulate large gemeente: 45 raadsleden, 18 commissies/maand, 3–4 meetings/week, 100+ documents/meeting
  - Verify full import completes in <24h
  - Verify incremental pull completes in <5 min
  - Verify push completes in <30 sec per object
  - Measure memory usage (no >512MB spike)
  **Acceptance:** Benchmarks recorded; performance regressions detected by CI.

---

## 8. Documentation and deployment

- [ ] 8.1 Write operator-facing documentation:
  - `docs/operators/sync-setup.md` — how to configure NOTUBIZ/iBabs connection per gemeente
  - `docs/operators/sync-monitoring.md` — how to use the sync-status dashboard, respond to alerts
  - `docs/operators/sync-troubleshooting.md` — common issues (API changes, rate-limit, conflict explosion) and mitigations
  - `docs/operators/sync-conflict-resolution.md` — step-by-step conflict resolution workflow
  **Acceptance:** Docs are complete; links are correct; screenshots included for UI workflows.

- [ ] 8.2 Create repair step for schema + app initialization:
  - `openconnector/lib/Migration/Version001000000Date20260522000000.php` — create decidesk-sync register + schemas
  - `openconnector/lib/Settings/AppInfo/Application.php` — register apps in DI container
  - Idempotent: re-running the migration does not create duplicates
  **Acceptance:** `php occ db:add-missing-primary-keys` works; migrations are idempotent.

- [ ] 8.3 Deploy to dev/staging:
  - Enable openconnector; install NOTUBIZ and iBabs adapter apps
  - Configure 1–2 test municipalities with sandbox endpoints
  - Run full import; verify data integrity (count objects, spot-check field values)
  - Run incremental sync for 3 days; verify delta counts are accurate
  - Create test conflicts; resolve manually; verify resolution propagates
  **Acceptance:** Full pilot workflow completes; no errors in logs.

---

## 9. Deduplication check

- [ ] 9.1 Verify no overlap with existing openregister/openconnector services:
  - Check `ObjectService` (existing generic CRUD) — sync uses it; no duplicate.
  - Check `RelationService` (existing FK relations) — sync uses for ExternalIdentifier; no duplicate.
  - Check `AuditTrailService` (existing change tracking) — automatic; no custom audit code.
  - Check `FileService` (existing file storage) — document streaming uses it; no duplicate.
  - Check `WebhookService` (existing webhook dispatch) — iBabs webhooks routed via this; no duplicate.
  - NEW custom code needed: SyncService, CrdtService, adapters, conflict UI.
  Document findings in a comment in `lib/Service/SyncService/SyncService.php`.
  **Acceptance:** Finding document is complete and accurate; no code duplication.

---

## 10. Seed data generation

- [ ] 10.1 Add seed data for all 4 schemas to `openconnector/lib/Settings/decidesk_sync_register.json`:
  - 3–5 ExternalIdentifier objects (per provider, per object type)
  - 3–5 SyncJob objects (mix of success, failed, partial statuses)
  - 2–3 SyncConflict objects (mix of open, resolved-local, resolved-external)
  - 3–5 FractieRol objects (mix of roles, portefeuilles, active/inactive)
  Use realistic Dutch values: gemeente names (gemeente-Amsterdam, gemeente-Utrecht), person names (Jan de Vries, Maria García), role names, date ranges.
  **Acceptance:** Seed data loads via `ConfigurationService::importFromApp()`; no duplicates on re-import; all objects validate against schema.

---

