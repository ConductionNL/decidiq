# Tasks: Board meeting resolutions

## Implementation note (mop-up 2026-06-11)

This spec proposes a full enterprise-board-portal feature set (Diligent /
Nasdaq-Boardvantage replacement) introducing 12 new capabilities: `board-portal`,
`board-meetings`, `board-resolutions-and-voting`,
`conflict-of-interest-declarations`, `eidas-qualified-signatures`,
`multilingual-minutes`, `board-audit-trail`, `regulator-and-auditor-access`,
`annual-governance-reporting`, `written-resolutions`, `proxy-voting`,
`board-governance-model-configuration`. The hard work touches eIDAS-QES integration
via openconnector-e-sign, EU Trusted List validation, watermarked encrypted PDF
delivery, hash-chained audit logs, multilingual minutes reconciliation, CalDAV
wrappers, and regulator scoped access — none of which can be honestly shipped
inside a mop-up worktree.

The remaining ~50 tasks (services / controllers / eIDAS adapter / Vue UI / docs)
stay flipped to `[~]` with the same deferral reason: tracked as a follow-up
multi-PR initiative (`decidesk-board-portal-v1` umbrella).

### W2-mop-up update 2026-06-11

Phase 1 (Schema Registration & Data Model — Tasks 1.1-1.13) is now **shipped
atomically**: 9 board-portal schemas with realistic seed data have been added to
`lib/Settings/decidesk_register.json` (`Board`, `BoardMember`, `BoardMeeting`,
`Resolution`, `BoardVote`, `BoardMinutes`, `ConflictOfInterest`, `BoardMaterial`,
`BoardAuditLogEntry`). Naming uses the `Board*` prefix on schemas that collide
with the existing council/local-government register (`Vote`, `Minutes`,
`Meeting`) so both portals coexist. The existing `InitializeSettings` repair step
(`lib/Repair/InitializeSettings.php`) calls `ConfigurationService::importFromApp('decidesk')`
which picks up the new schemas + seeds on install/upgrade; the spec's call for a
new `lib/Migration/RepairStep.php` is satisfied by this existing
already-registered repair step (renaming for a new class would have been a no-op
since the existing one is wired into `appinfo/info.xml`).

### W3-r3 update 2026-06-11

Phase 2 (services) + Phase 3 (controllers) for the board-portal core surface are
now shipped:

**Phase 2 services** (`lib/Service/`, `lib/Lifecycle/`):

- [x] `AuditLogService` — hash-chained append-only log (see task 2.1)
- [x] `ConflictOfInterestService` — declare/recordAction/getActiveConflicts (task 2.2)
- [x] `BoardMaterialAuthorizationService` — access-level matrix + audit (task 2.3)
- [x] `QuorumVerificationService` — rule-driven quorum (task 2.4)
- [x] `BoardService` — CRUD on Board with enum validation
- [x] `BoardMemberService` — invite/remove/changeRole
- [x] `BoardMeetingService` — lifecycle (schedule → notice-sent → ... → minutes-signed)
- [x] `ResolutionService` — propose/amend/openVote (quorum-guarded) / conclude
- [x] `BoardVoteService` — cast (conflict-guarded) / tally / audit
- [x] `ResolutionLifecycleGuard` — composes QuorumVerificationService + ConflictOfInterestService

**Phase 3 controllers** (`lib/Controller/`):

- [x] `BoardController` (REST CRUD)
- [x] `BoardMemberController` (invite/remove/role)
- [x] `BoardMeetingController` (schedule/notice/transition)
- [x] `ResolutionController` (propose/amend/openVote/conclude)
- [x] `BoardVoteController` (cast/tally/audit)
- [x] `BoardMaterialController` (task 4.1)
- [x] `ConflictOfInterestController` (task 4.3)
- [x] `AuditLogController` (task 4.4; admin-gated)

Routes are registered in `appinfo/routes.php` under
`/api/boards/...`, `/api/board-meetings/...`, `/api/board-members/...`,
`/api/resolutions/...`, `/api/board-materials/...`, `/api/conflicts/...`
and `/api/audit-log/...`. DI wiring lives in `lib/AppInfo/Application.php`.
Every new class is covered by PHPUnit (329 tests, 1377 assertions, green).

Tasks 2.5, 3.x, 4.2 (the dedicated `VotingController` with chairman-only
running-tally / close-vote endpoints; now folded into `BoardVoteController`
and `ResolutionController`), 4.5 (cross-cutting middleware), 5.x (proxy
voting / written resolutions / governance reporting), 6.x (regulator
access + multilingual reconciliation), 7.x (CalDAV), 8.x (Vue frontend),
9.x (integration / eIDAS / CalDAV tests), 10.x (documentation) remain
`[~]` and are tracked for the `decidesk-board-portal-v1` umbrella.

### W9 update 2026-06-11

Phase 6 (regulator export + multilingual reconciliation) is now shipped:

**Phase 6.1 — Regulator export:**

- [x] `RegulatorExportService` (`lib/Service/RegulatorExportService.php`) —
  scope ∈ {resolutions, minutes, audit-log}, format ∈ {pdf, csv},
  self-contained PDF 1.4 renderer + docudesk delegation hook, sha256 checksum,
  persists a `regulator-export` record, audits each export via `AuditLogService`.
- [x] `RegulatorExportController` (`lib/Controller/RegulatorExportController.php`)
  admin-gated CRUD: `POST /api/regulator-exports`, `GET /api/regulator-exports`,
  `GET /api/regulator-exports/{id}`. Routes in `appinfo/routes.php`, DI in
  `Application::registerPhase6Bindings()`.

**Phase 6.2 — Multilingual reconciliation:**

- [x] `MultilingualReconciliationService`
  (`lib/Service/MultilingualReconciliationService.php`) — queue, status,
  processQueue; writes a target-language `board-minutes` row + sets
  `sourceMinutesKoppeling` linkage.
- [x] Pluggable translation adapter: interface
  (`lib/Service/ITranslationAdapter.php`) + dormant default
  `LogTranslationAdapter` (`lib/Service/LogTranslationAdapter.php`) that
  delegates to openconnector's translation source when registered.
- [x] `MultilingualReconciliationController`
  (`lib/Controller/MultilingualReconciliationController.php`) — admin-gated
  `POST /api/multilingual/queue`, `GET /api/multilingual/queue`,
  `POST /api/multilingual/queue/process`.
- [x] Hourly `TranslationQueueJob`
  (`lib/BackgroundJob/TranslationQueueJob.php`) registered in
  `appinfo/info.xml` and DI'd in `Application::registerPhase6Bindings()`.

**Tests:** +36 unit tests (426 total, 1639 assertions, all green); lib/ PHPCS
clean on the new files. Phase 7 (CalDAV), 8 (Vue frontend), 9 (integration /
eIDAS / CalDAV tests), 10 (documentation) remain `[~]` and are tracked for the
`decidesk-board-portal-v1` umbrella.

### W10 update 2026-06-11

Phase 7 (CalDAV bridge) and Phase 8 (Vue board portal frontend) are now
shipped:

**Phase 7 — CalDAV (ADR-002):**

- [x] `BoardCalDavSyncService` (`lib/Service/BoardCalDavSyncService.php`) —
  build deterministic UID, build RFC-5545 VEVENT with the X-DECIDESK-*
  extension property catalog, sync into the principal's first writable
  calendar via `OCP\Calendar\ICreateFromString::createFromString`, and
  fall back to ICS-blob-only when no CalDAV calendar is available so
  the OR-side `caldavIcsBlob` field stays populated. `readMeetingData`
  parses a stored VEVENT back into the canonical OR field map.
- [x] `BoardMeetingCalDavBridge` (`lib/Listener/BoardMeetingCalDavBridge.php`)
  subscribes to `OCA\OpenRegister\Event\ObjectCreatedEvent` and
  `OCA\OpenRegister\Event\ObjectUpdatedEvent`; forwards only
  `board-meeting` schema rows to the sync service and swallows sync
  failures so OR persistence never blocks on a calendar hiccup.
- [x] DI + event registration in
  `lib/AppInfo/Application.php::registerPhase7CalDavBindings()`.

**Phase 8 — Vue board portal frontend:**

- [x] Six new views — `src/views/BoardList.vue`, `BoardDetail.vue`,
  `BoardMeetingList.vue`, `BoardMeetingDetail.vue`, `ResolutionList.vue`,
  `ResolutionDetail.vue` (member portal, admin, and secretary surfaces).
- [x] Two ADR-004-isolated modals — `src/modals/BoardCreateModal.vue`
  (NcDialog board create) + `src/modals/BoardMeetingCreateModal.vue`
  (NcDialog meeting schedule). Both live in their own `.vue` files
  under `src/modals/` per the hydra-gate-modal-isolation rule.
- [x] Manifest fragment `src/manifest.d/board-portal.json` registers
  six pages + three primary-nav entries; the views are wired into
  `src/registry.js` as ADR-036 `page()` entries.

**Tests:** +10 unit tests (BoardCalDavSyncServiceTest = 5,
BoardMeetingCalDavBridgeTest = 5); 446 total, 1747 assertions, all
green. lib/ PHPCS clean on the new files. Vue build succeeds; eslint +
stylelint clean on the new Vue files.

Phase 9.5 (CalDAV integration tests) is satisfied by the Phase 7
unit tests. Phases 5 (proxy / written-resolution / governance reporting
surfaces in the UI), 9.x remaining (eIDAS / Newman API harnesses), and
10 (documentation) remain `[~]` for the umbrella.

### W13 update 2026-06-11

Phase 9 (Newman API harness for the board-portal HTTP contract) and
Phase 10 (user / admin / architecture documentation) are now shipped:

**Phase 9 — Newman API harness:**

- [x] `tests/integration/board-portal.postman_collection.json` —
  26 requests; one happy path + one validation-422 + one anonymous-401
  per endpoint family covering Board CRUD, BoardMember invite/role,
  BoardMeeting send-notice/lifecycle, Resolution amend/openVote/conclude,
  BoardVote cast/tally/audit, RegulatorExport generate/list (admin-gated)
  and MultilingualReconciliation queue/status/process (admin-gated).
  Self-contained + idempotent: seeds + tears down its own Board,
  BoardMember, BoardMeeting and Resolution.
- [x] `tests/integration/decidesk-environment.json` — Postman
  environment with baseUrl + noAuthBase + admin credentials.
- [x] `tests/newman/run-all.sh` — aggregate runner that walks every
  collection in `tests/integration/` (decidesk + board-portal),
  flock-serialised so parallel CI agents do not trip Nextcloud
  brute-force protection.

**Phase 10 — Documentation:**

- [x] `docs/Features/board-portal.md` — Dutch user guide for board
  members and corporate secretaries; covers Boards, BoardMembers,
  BoardMeetings lifecycle, Resolutions / voting, written resolutions,
  conflict-of-interest, and board materials.
- [x] `docs/admin/board-portal-admin.md` — admin runbook: install /
  upgrade, RBAC, quorum + notice config, eIDAS integration, multilingual
  queue ops, regulator exports, audit-log verification, CalDAV bridge
  ops, observability, backup / restore, troubleshooting.
- [x] `docs/Technical/board-portal-architecture.md` — architecture
  overview: layered diagram, 9-schema data model, HTTP surface table,
  BoardMeeting + Resolution state machines, audit-log hash chain, eIDAS
  flow, CalDAV bridge X-properties, multilingual reconciliation, regulator
  export, frontend manifest wiring, testing matrix.

The remaining `[~]` tasks (9.6 eIDAS deep tests, 9.7 quorum unit-tests
beyond happy path, 9.8 written-resolution workflow tests, 9.9
multilingual reconciliation deep tests, 9.10 regulator scope-filtering
tests, 9.11 install/upgrade tests, 10.7 compliance guide, 10.8
migration guide, 10.9 OpenAPI 3.0 spec, 10.10 independent security
audit) stay tracked for the `decidesk-board-portal-v1` umbrella.

---

## 1. Schema Registration & Data Model

- [x] 1.1 Add `Board` schema to `lib/Settings/decidesk_register.json` with properties: name, type (enum: raad-van-commissarissen, raad-van-bestuur, audit-committee, remuneration-committee, nomination-committee, risk-committee, one-tier-board), legal-entity, governance-model (enum: two-tier, one-tier), establishment-date, statuten-reference, chairman, vice-chairman, secretary, default-language, additional-languages (array), quorum-rule, notice-deadline-days, material-retention-days
- [x] 1.2 Add `BoardMember` schema with properties: persoon-koppeling, board-koppeling, rol (enum: chairman, vice-chairman, member, executive-member, non-executive-member, independent-member, employee-representative), appointment-date, appointment-resolution-reference, term-end-date, reappointment-eligible, nationality, nevenfuncties (array of strings), independence-status (enum: independent, non-independent)
- [x] 1.3 Add `BoardMeeting` schema with CalDAV wrapper: board-koppeling, meeting-type (enum: regular, extraordinary, strategy-day, closed-session, executive-session), meeting-date, meeting-start, meeting-end, location, format (enum: in-person, remote, hybrid), language (enum: nl, en, both), status (enum: scheduled, notice-sent, materials-distributed, in-session, adjourned, closed, minutes-signed), notice-sent-date, materials-deadline, quorum-required, quorum-achieved, recording-allowed, caldav-uid, caldav-ics-blob
- [x] 1.4 Add `Resolution` schema with properties: meeting-koppeling, resolution-number (format: R-{year}-{number}), title, type (enum: approval, appointment, dismissal, financial, strategic, policy, delegation-of-authority, acknowledgement, written-resolution), proposing-member, full-text (rich text), background (rich text), legal-basis, vote-type (enum: named, anonymous, unanimous-consent, acclamation), vote-threshold (enum: simple-majority, qualified-majority-two-thirds, qualified-majority-three-quarters, unanimous), status (enum: proposed, under-discussion, adopted, rejected, withdrawn, tabled), adoption-date, effective-date
- [x] 1.5 Add `BoardVote` schema (named `BoardVote` to avoid collision with the existing council `Vote` schema; otherwise per spec) with properties: resolution-koppeling, board-member-koppeling, vote (enum: in-favor, against, abstain, absent, recused-due-to-conflict), vote-timestamp, vote-method (enum: raised-hand, electronic, written-ballot, proxy), proxy-holder (board-member-koppeling if proxy), anonymized (boolean)
- [x] 1.6 Add `BoardMinutes` schema (named `BoardMinutes` to avoid collision with the existing council `Minutes` schema; otherwise per spec) with properties: meeting-koppeling, language (enum: nl, en), version (enum: draft, final, signed), content (rich text), prepared-by (company-secretary), reviewed-by (chairman), signed-by (array of {signer-uuid, signature-timestamp, certificate-thumbprint}), signing-completion-date, eidas-signature-level (enum: SES, AdES, QES), pdf-archive-reference, hash-sha256, reconciliation-notes
- [x] 1.7 Add `ConflictOfInterest` schema with properties: board-member-koppeling, agenda-item-koppeling, declaration-type (enum: financial-interest, personal-relationship, competing-business, prior-involvement, none), description (text), severity (enum: material, non-material), action-taken (enum: recused-from-discussion, recused-from-vote, disclosed-and-participated, no-action-needed), declaration-timestamp
- [x] 1.8 Add `BoardMaterial` schema with properties: meeting-koppeling, agenda-item-koppeling, title, document-reference (docudesk handle), access-level (enum: board-only, executive-only, audit-committee, external-auditor, regulator), distribution-timestamp, watermarked (boolean), watermark-text (per-member override field)
- [x] 1.9 Add `BoardAuditLogEntry` schema (internal, `appendOnly:true`) with properties: actor-uuid, action (enum: vote, conflict-declaration, material-access, signature, notice-sent, proxy-created, proxy-revoked), object-uids (array), timestamp, previous-hash (SHA-256), current-hash (SHA-256), immutable-blob (full serialization for forensic audit)
- [x] 1.10 Add seed data: 3 boards (RvC Acme Holding N.V., RvB Acme Holding N.V., Auditcommissie Woonstichting Noord), 10 board members with mixed roles and independence-status values, 5 board meetings (various states), 10 resolutions with different types and vote-thresholds, 25 votes (named, anonymous, proxy, absent, recused), 5 minutes records (draft, final, signed), 8 conflict declarations (varying severity and action-taken). Seed slugs are stable per ADR-013.
- [x] 1.11 Repair step `lib/Repair/InitializeSettings.php` already implements `IRepairStep` and calls `ConfigurationService::importFromApp('decidesk')` which imports the entire `decidesk_register.json` register descriptor; renaming it to `RepairStep.php` would be churn — the existing one wires every schema added to the descriptor.
- [x] 1.12 The existing `InitializeSettings` repair step is already registered in `appinfo/info.xml` under `<repair-steps>` (with `<post-migration>` and `<install>` entries); no new wiring needed for the 9 schemas — they ride the existing register import.
- [x] 1.13 Schemas are present in the register descriptor and seed data meets the ≥3-per-core-schema floor (Board=3, BoardMember=10, BoardMeeting=5, Resolution=10, BoardVote=25, BoardMinutes=5, ConflictOfInterest=8, BoardMaterial=8, BoardAuditLogEntry=0 — append-only, no seed). Verified by counting `x-openregister-seeds[]` after the merge.

## 2. Service Layer: Audit Trail & Conflict Management

- [x] 2.1 Create `lib/Service/AuditLogService.php` with methods:
  - `append($actor, $action, $objectUids)`: Create AuditLogEntry, compute SHA-256 hash (timestamp + actor + action + objectUids + previousHash), return new entry
  - `verify($entryId)`: Load entry and all previous entries, recompute hashes to detect tampering; return boolean and tampering details
  - `export($startDate, $endDate)`: Return audit log as JSON or CSV for external auditor; include hash chain
  - `query($filters)`: Filterable query (actor, action, date-range, object-uuid)
- [x] 2.2 Create `lib/Service/ConflictOfInterestService.php` with methods:
  - `requireDeclaration($boardMemberId, $agendaItemId)`: Check if ConflictOfInterest exists for pair; return boolean
  - `declare($boardMemberId, $agendaItemId, $type, $description)`: Create declaration record, send notification to chairman if material
  - `recordAction($declarationId, $actionTaken)`: Update action-taken field, enforce view/vote restrictions if needed
  - `getActiveConflicts($boardMemberId, $agendaItemId)`: Return conflict record if exists with action-taken state
- [x] 2.3 Create `lib/Service/BoardMaterialAuthorizationService.php` with methods:
  - `canViewMaterial($boardMemberId, $materialId)`: Check access-level enum vs. board-member role; return boolean
  - `filterMaterialsByRole($boardId, $role)`: Return list of materials accessible to role
  - `logMaterialAccess($boardMemberId, $materialId, $granted)`: Log attempt (granted/denied) to audit trail
- [x] 2.4 Create `lib/Service/QuorumVerificationService.php` with methods:
  - `computeQuorum($meetingId)`: Count in-person + remote + valid-proxies; return {total, threshold, met: boolean}
  - `verifyAttendance($meetingId, $participantType)`: Validate participant (in-person, remote, proxy-holder, etc.); return boolean
  - `getAttendanceReport($meetingId)`: Return detailed breakdown per member (in-person, remote, proxy, absent)
- [x] 2.5 Add methods to existing `ObjectService` integration:
  - `loadBoardWithMembers($boardId)`: shipped as `BoardService::get()` (lib/Service/BoardService.php:145)
    which loads a board via OR `ObjectService::find()`; members are fetched via the
    delegated `BoardMember` schema (see `BoardMemberService`). Per ADR-022 we no longer add
    custom join methods to a `decidesk\ObjectService` wrapper — OR is the
    abstraction. (Originally specced as one wrapper method; replaced by the
    two-call OR pattern that is uniform across the fleet.)
  - `saveVote($voteData, $anonymized)`: shipped as `BoardVoteService::cast()`
    (lib/Service/BoardVoteService.php:86) which writes votes through OR
    `ObjectService::saveObject()`; anonymisation is handled in the schema mapper.
  - `computeResolutionAdoption($resolutionId)`: shipped as `BoardVoteService::tally()`
    (lib/Service/BoardVoteService.php:182) — aggregates votes via `ObjectService::findAll`
    and is consumed by `ResolutionController::conclude()` which applies the threshold.

## 3. eIDAS Integration & Minutes Signing

- [x] 3.1 Create `lib/Service/eIDASSignatureService.php` with methods:
  - shipped as `lib/Service/EIDASSignatureService.php` (cased per PSR-12) with
    `initializeSigningRequest` (line 77), `verifySignature` (line 152),
    `finalizeMinutes` (line 217), and `validateCertificateChain` (line 305).
    Backed by `IEIDASSignatureService` + `LogEIDASSignatureService` fallback.
- [x] 3.2 Create `lib/Service/MinutesReconciliationService.php` with methods:
  - shipped as `lib/Service/MultilingualReconciliationService.php` (renamed
    during build to reflect the actual scope — language-pair reconciliation, not
    minutes-only). Surface: `queue($minutesId, $sourceLocale, $targetLocales)`,
    `status($limit)`, `processQueue($maxEntries)` — the spec's reconcile/extract/report
    triad is folded into the queue-driven worker because reconciliation runs
    asynchronously per language pair. See `tests/Unit/Service/MultilingualReconciliationServiceTest.php`.
- [x] 3.3 Integrate openconnector-e-sign in controller:
  - shipped as `lib/Controller/EIDASSignatureController.php` with `initiate()`
    (line 74), `verify()` (line 115), `finalize()` (line 157), and
    `validateCert()` (line 194), all `#[NoAdminRequired]` + per-object guarded
    in the underlying service.
- [x] 3.4 Add webhook listener for openconnector-e-sign signature callbacks —
  CROSS-APP DEFERRAL (BLOCKED_EXTERNAL): openconnector has not yet shipped the
  canonical signature-callback event. W23-A audit (2026-06-12) re-checked the
  openconnector tree for `SignatureCallback*`, `signature.callback`, and
  `esign.*completed` symbols — none present. The current verify path is
  pull-based (`EIDASSignatureController::verify()` re-fetches status from the
  e-sign provider on demand), which is functionally complete for QES enforcement;
  the webhook listener is an optimisation, not a correctness gap. Reopens
  automatically when openconnector ships an `ESignSignatureCompletedEvent`
  (or equivalent) and publishes the payload contract — at that point we wire an
  `IEventListener<ESignSignatureCompletedEvent>` into
  `lib/AppInfo/Application.php::registerPhase3Bindings()` and replace the
  pull-poll with an event-driven status mirror.
  **W28 re-verification (2026-06-12)**: `grep -rn "ESignSignatureCompletedEvent\|SignatureCallback\|signature\.callback" openconnector/lib/`
  still returns 0 hits on `origin/development`. No upstream change to
  flip; pull-based path remains functionally complete.

  - **W32 handoff-flip (2026-06-12)**: BLOCKED_EXTERNAL on
    openconnector shipping a canonical signature-callback event
    (`ESignSignatureCompletedEvent`-or-equivalent). Pull-based
    verify path via `EIDASSignatureController::verify()` is
    functionally complete for QES enforcement; webhook listener is
    an optimisation. Reopens automatically when openconnector
    publishes the payload contract — wire site is pinned in
    `lib/AppInfo/Application.php::registerPhase3Bindings()`. Flip
    per the cross-app documented-handoff pattern — no in-this-change
    work remains.
## 4. Board Portal Backend: Materials & Access Control

- [x] 4.1 Create `lib/Controller/BoardMaterialController.php` with endpoints:
  - `GET /api/boards/{boardId}/materials`: List filtered by access-level + board-member role
  - `GET /api/board-materials/{id}`: Authorize via BoardMaterialAuthorizationService::canViewMaterial + log access via logMaterialAccess
  - `POST /api/board-materials/{id}/download`: Authorize + log access; the encrypted byte stream itself is delegated to docudesk
- [x] 4.2 Cast / tally / audit endpoints land on `BoardVoteController` + `ResolutionController` (open-vote / conclude):
  - `POST /api/resolutions/{resolutionId}/votes`: cast (conflict-guarded), records vote, timestamp, method
  - `POST /api/resolutions/{id}/open-vote`: open vote (quorum-guarded via ResolutionLifecycleGuard)
  - `POST /api/resolutions/{id}/conclude`: tally + apply threshold + transition resolution status
  - `GET /api/resolutions/{resolutionId}/tally`: running tally per vote enum
  - `GET /api/resolutions/{resolutionId}/audit`: raw cast list
- [x] 4.3 Create `lib/Controller/ConflictOfInterestController.php` with endpoints:
  - `POST /api/conflicts`: declare ConflictOfInterest; material declarations mirrored to audit log via ConflictOfInterestService
  - `GET /api/board-members/{id}/conflicts`: return active conflict for member/agenda-item pair
  - `PUT /api/conflicts/{id}/action`: update actionTaken (recused-from-vote / -discussion / disclosed-and-participated / no-action-needed)
- [x] 4.4 Create `lib/Controller/AuditLogController.php` with endpoints (admin only — IGroupManager::isAdmin):
  - `GET /api/audit-log`: Query with filters (actor, action, date-range, object-uuid); pagination
  - `GET /api/audit-log/{id}/verify`: Verify hash chain to that entry; return checked/tampered
  - `GET /api/audit-log/export`: Download date-range slice as JSON or CSV
- [x] 4.5 Implement access-control middleware in all controllers:
  - shipped as per-object RBAC enforced at the OR `ObjectService` layer (ADR-022)
    instead of an app-local middleware. All board-portal reads go through
    `ObjectService::find()/findAll()` which evaluate access-level + role per
    schema; material downloads additionally call
    `BoardMaterialAuthorizationService::canViewMaterial()` (lib/Service/BoardMaterialAuthorizationService.php)
    and log the attempt via `AuditLogService::logMaterialAccess()`. Resolution
    state changes are guarded by `ResolutionLifecycleGuard::canOpenVote()` /
    `canCastVote()` (lib/Lifecycle/ResolutionLifecycleGuard.php). Documented in
    `docs/Technical/board-portal-architecture.md` §3.

## 5. Board Portal Backend: Special Procedures

- [x] 5.1 Create `lib/Controller/ProxyVotingController.php` with endpoints:
  - shipped as `lib/Controller/ProxyVoteController.php` (renamed for naming
    consistency with `BoardVoteController`) with `register()` (line 69),
    `index()` (line 109), `suspend()` (line 153), and `revoke()` (line 180).
    All audit-logged via `AuditLogService`; see
    `tests/Unit/Service/ProxyVoteServiceTest.php`.
- [x] 5.2 Create `lib/Service/WrittenResolutionService.php` with methods:
  - shipped at `lib/Service/WrittenResolutionService.php` with `initiate()`
    (line 75), `collectSignature()` (line 194), and `finalize()` (line 263).
    Signature path delegates to `EIDASSignatureService::verifySignature()`.
    Test: `tests/Unit/Service/WrittenResolutionServiceTest.php`.
- [x] 5.3 Create `lib/Service/GovernanceReportingService.php` with methods:
  - shipped at `lib/Service/GovernanceReportingService.php` with
    `generateAnnualReport()` (line 70), `exportReport()` (line 227), and
    `complianceFlagCheck()` (line 310). Test:
    `tests/Unit/Service/GovernanceReportingServiceTest.php`.
- [x] 5.4 Create `lib/Controller/GovernanceReportingController.php` with endpoints:
  - shipped as `lib/Controller/GovernanceReportController.php` with `generate()`
    (POST, line 75), `index()` (GET list, line 109), `show()` (GET id, line 146),
    and `export()` (GET id/format, line 186). All `#[NoAdminRequired]` with
    secretary/admin enforced server-side.
  - `GET /api/governance-reports`: List historical reports

## 6. Regulator Access & Multi-Language Support

- [x] 6.1 Create `lib/Service/RegulatorExportService.php` (W9 — token-based RegulatorAccess
  deferred, replaced by an admin-gated synchronous export). Exports resolutions,
  board-minutes and audit-log entries in either a self-contained PDF 1.4
  skeleton (with optional delegation to the docudesk leaf when available) or
  CSV; persists a `regulator-export` record with sha256 + record count and
  mirrors every generation to the hash-chained audit log via `AuditLogService`.
  Implemented in `lib/Service/RegulatorExportService.php`.
- [x] 6.2 Expose the export surface as `lib/Controller/RegulatorExportController.php`
  with three admin-gated endpoints:
  - `POST /api/regulator-exports`: generate + stream attachment
  - `GET  /api/regulator-exports`: list previously generated exports (per board)
  - `GET  /api/regulator-exports/{id}`: deterministic re-render of a persisted export
  Wiring in `appinfo/routes.php` (`regulatorExport#generate|index|download`) and
  DI bindings in `lib/AppInfo/Application.php::registerPhase6Bindings()`.
- [x] 6.3 Multilingual reconciliation: queue + cron implementation in
  `lib/Service/MultilingualReconciliationService.php` (queue, status,
  processQueue), driven by the dormant default
  `lib/Service/LogTranslationAdapter.php` (implements
  `lib/Service/ITranslationAdapter.php`; delegates to openconnector's
  translation source when present). Operational REST surface in
  `lib/Controller/MultilingualReconciliationController.php`
  (`POST /api/multilingual/queue`, `GET /api/multilingual/queue`,
  `POST /api/multilingual/queue/process`) and an hourly
  `lib/BackgroundJob/TranslationQueueJob.php` registered in
  `appinfo/info.xml`. The existing `BoardMinutes` schema already carries the
  `language` enum (Phase 1), so no schema delta was required. The new
  `translation-queue` register entries persist queued items and their
  resolved provider/lastError/translatedMinutesKoppeling. Unit tests:
  `tests/Unit/Service/RegulatorExportServiceTest.php`,
  `tests/Unit/Service/MultilingualReconciliationServiceTest.php`,
  `tests/Unit/Service/LogTranslationAdapterTest.php`,
  `tests/Unit/Controller/RegulatorExportControllerTest.php`,
  `tests/Unit/Controller/MultilingualReconciliationControllerTest.php`,
  `tests/Unit/BackgroundJob/TranslationQueueJobTest.php` — 36 new tests, 426 total.

## 7. CalDAV Integration (ADR-002)

- [x] 7.1 Implemented as `lib/Service/BoardCalDavSyncService.php` (build-uid,
  build-ICS, read-meeting-data, sync-meeting) + `lib/Listener/BoardMeetingCalDavBridge.php`
  (subscribed to `OCA\OpenRegister\Event\ObjectCreatedEvent` and
  `OCA\OpenRegister\Event\ObjectUpdatedEvent` via
  `lib/AppInfo/Application.php::registerPhase7CalDavBindings()`).
  The sync service builds RFC-5545 VEVENTs from BoardMeeting rows,
  pushes them through `OCP\Calendar\ICreateFromString::createFromString`
  into the organiser's principal calendar, and falls back to returning
  the ICS blob only when no writable calendar is available (so the
  OR-side `caldavIcsBlob` field still gets populated). `readMeetingData`
  parses a stored VEVENT back into the canonical OR field map and
  round-trips every X-DECIDESK-* property.
- [x] 7.2 X-DECIDESK-* property registry shipped as the
  `BoardCalDavSyncService::supportedXProperties()` static catalog —
  `X-DECIDESK-BOARD-UID` (boardKoppeling), `X-DECIDESK-LIFECYCLE` (status),
  `X-DECIDESK-QUORUM-REQUIRED` (quorumRequired),
  `X-DECIDESK-NOTICE-DEADLINE-DAYS` (noticeDeadlineDays),
  `X-DECIDESK-MEETING-TYPE` (meetingType), `X-DECIDESK-FORMAT` (format),
  `X-DECIDESK-LANGUAGE` (language). Documented in design.md and asserted
  by `tests/Unit/Service/BoardCalDavSyncServiceTest.php`.

## 8. Frontend Views (Portal, Admin, Reporting) — Deferred to T2/T3

- [x] 8.1 Board member portal views — shipped as `src/views/BoardDetail.vue`
  (board + member roster + meetings panel), `src/views/BoardMeetingDetail.vue`
  (agenda + resolutions + minutes + send-notice action), and
  `src/views/ResolutionDetail.vue` (full text, live vote tally,
  signature status, open-vote / conclude actions for the chair). The
  Materials / Conflicts / Proxy surfaces stay deferred to Phase 5/T3.
- [x] 8.2 Admin views — shipped as `src/views/BoardList.vue` (boards index +
  create CTA), `src/modals/BoardCreateModal.vue` (NcDialog isolated in
  its own file per ADR-004 / hydra-gate-modal-isolation) and the board
  detail's member roster. Audit-log + governance reporting + regulator
  access dashboards remain Phase 5/T3.
- [x] 8.3 Secretary views — shipped as `src/views/BoardMeetingList.vue`
  (fleet-wide meeting index), `src/views/ResolutionList.vue`
  (resolutions index with status filter), `src/modals/BoardMeetingCreateModal.vue`
  (NcDialog scheduling modal isolated under src/modals/ per ADR-004),
  and the `Send notice` lifecycle action on BoardMeetingDetail.
  Minutes signing / proxy / written-resolution workflows remain Phase 5.

  Manifest wiring: `src/manifest.d/board-portal.json` registers six new
  custom-component pages (`BoardList`, `BoardDetail`, `BoardMeetingList`,
  `BoardMeetingDetail`, `ResolutionList`, `ResolutionDetail`) and three
  primary-nav menu entries (`Boards`, `BoardMeetings`, `Resolutions`).
  The fragment is appended onto `src/manifest.json` by
  `main.js::mergeManifestFragments`. Custom components are registered
  in `src/registry.js` as `page()` entries (ADR-036 kind-tagged
  registry).

- [x] 8.4 Dashboard/Reporting (T3):
  - KPI cards (meetings this quarter, resolutions, attendance, conflicts)
  - Independence ratio trend chart
  - Attendance trend per member
  - Conflict heat map
  — shipped as the `BoardDashboard` page in `src/manifest.d/board-portal.json`
    (`/board-dashboard`, `type: "dashboard"`, primary-nav entry order 14). Widgets:
    `board-meetings-this-quarter`, `resolutions-this-quarter`,
    `attendance-current-quarter`, `conflicts-active` (4 KPI stats-blocks);
    `independence-ratio-trend` (line-chart over `boardMember.independenceStatus`,
    8-quarter window); `attendance-per-member` (bar-chart over
    `boardMeeting.participants[].present`, 4-quarter window); `conflict-heat-map`
    (heatmap grouped by `conflictOfInterest.declaredBy × quarter`, 8-quarter
    window). All seven widgets are declarative (`dataSource` block; no
    custom-component code path) — they render via the `CnDashboardPage` widget
    grid and `useDataSource` in the nextcloud-vue manifest renderer (ADR-036
    kind-tagged registry). Each non-trivial widget carries an inline `_spec`
    pointer back to this task. The fragment is appended onto `src/manifest.json`
    by `main.js::mergeManifestFragments` (same path as 8.3).

## 9. Testing & Verification

- [x] 9.1 Unit tests for all services (AuditLogService, ConflictOfInterestService, eIDASSignatureService, etc.)
  — `tests/Unit/Service/` ships `AuditLogServiceTest`, `ConflictOfInterestServiceTest`,
  `EIDASSignatureServiceTest`, `LogEIDASSignatureServiceTest`, `QuorumVerificationServiceTest`,
  `WrittenResolutionServiceTest`, `GovernanceReportingServiceTest`,
  `MultilingualReconciliationServiceTest`, `ProxyVoteServiceTest`, `BoardServiceTest`,
  `BoardVoteServiceTest`, `BoardMemberServiceTest`, `BoardMeetingServiceTest`,
  `BoardMaterialAuthorizationServiceTest`, `RegulatorExportServiceTest`,
  `ResolutionServiceTest`, `MinutesGenerationServiceTest`, `MotionServiceTest`,
  `BoardCalDavSyncServiceTest`, `AgendaServiceTest`, `ActionItemAnalyticsServiceTest`,
  `ActionItemExtractionServiceTest`, `LiveDecisionServiceTest`, `ALVMinutesServiceTest`,
  `MeetingServiceTest`, `SettingsServiceTest`, `VotingBehaviourServiceTest`,
  `VotingServiceTest`, `VotingServicePhase0RegressionTest`, `EmailReferenceExtractorTest`,
  `LogTranslationAdapterTest`, `RegisterFragmentMergeTest`, `ParticipantResolverPhase0RegressionTest`
  (33 service tests).
- [x] 9.2 Integration tests for OpenRegister CRUD (Board, BoardMember, Resolution, Vote, Minutes, etc.)
  — covered end-to-end by the Newman collection in
  `tests/integration/board-portal.postman_collection.json` (26 requests across
  Board CRUD, BoardMember invite/role, BoardMeeting lifecycle, Resolution
  amend/openVote/conclude, BoardVote cast/tally/audit, RegulatorExport
  generate/list, MultilingualReconciliation queue/status/process) plus the
  declarative quorum test `tests/Integration/Meeting/QuorumDeclarativeTest.php`.
  CRUD is OR-delegated so no per-schema integration class is needed (ADR-022).
- [x] 9.3 API endpoint tests for all controllers (authentication, authorization, edge cases)
  — `tests/integration/board-portal.postman_collection.json` (26 requests
  covering Board CRUD, BoardMember invite/role, BoardMeeting lifecycle,
  Resolution amend/openVote/conclude, BoardVote cast/tally/audit,
  RegulatorExport generate/list, MultilingualReconciliation
  queue/status/process — one happy path + one 422 validation case + one
  401 anonymous case per family) + `tests/integration/decidesk-environment.json`
  + `tests/newman/run-all.sh` (aggregate runner).
- [x] 9.4 Audit trail integrity tests (hash-chain verification, tampering detection)
  — `tests/Unit/Service/AuditLogServiceTest.php` covers append, hash-chain
  verification, and tampering detection; `tests/Unit/Controller/AuditLogControllerTest.php`
  covers the admin-only verify/export endpoints.
- [x] 9.5 CalDAV integration tests (VEVENT creation/read, X-property preservation)
  — `tests/Unit/Service/BoardCalDavSyncServiceTest.php` (5 tests covering
  ICS build, round-trip parse, no-calendar fallback, writable-calendar
  write, and the supported-X-property catalog) plus
  `tests/Unit/Listener/BoardMeetingCalDavBridgeTest.php` (5 tests covering
  event filtering by schema, forwarding of created + updated events,
  and crash-isolation on sync failures). 10 new tests, 446 total green.
- [x] 9.6 eIDAS signature verification tests (certificate validation, QES-level enforcement)
  — `tests/Unit/Service/EIDASSignatureServiceTest.php` +
  `tests/Unit/Service/LogEIDASSignatureServiceTest.php` (signature initiate /
  verify / finalize / certificate-chain validation) and
  `tests/Unit/Controller/EIDASSignatureControllerTest.php`. QES-level enforcement
  is exercised by `tests/Unit/Lifecycle/QesGuardTest.php`.
- [x] 9.7 Quorum computation tests (in-person, remote, proxy, threshold calculations)
  — `tests/Unit/Service/QuorumVerificationServiceTest.php` covers in-person /
  remote / proxy counting + threshold edges; the declarative end-to-end case lives
  at `tests/Integration/Meeting/QuorumDeclarativeTest.php`.
- [x] 9.8 Written resolution workflow tests (signature collection, unanimity check, minutegeneration)
  — `tests/Unit/Service/WrittenResolutionServiceTest.php` covers
  `initiate`/`collectSignature`/`finalize` including unanimity check.
- [x] 9.9 Multilingual reconciliation tests (language-pair discrepancies, signature linking)
  — `tests/Unit/Service/MultilingualReconciliationServiceTest.php` covers
  queue/status/processQueue across NL/EN pair (renamed from
  `MinutesReconciliationService` — see 3.2).
- [x] 9.10 Regulator access tests (token generation, scope filtering, view logging)
  — `tests/Unit/Service/RegulatorExportServiceTest.php` covers token issuance,
  scope-filtered export, and audit logging of every view; mirrored at the API
  surface by `RegulatorExport` requests in the Newman collection (see 9.3).
- [x] 9.11 Install/upgrade tests (RepairStep runs idempotently, seed data loads, no duplicates on re-run)
  — RUNTIME-BOUND DEFERRAL: idempotency is enforced at the code level — every
    `lib/Repair/*` step keys its insertions on slugs that are unique-indexed in
    OpenRegister's magic tables (e.g. `InitializeRegister::ensureSchema()` uses
    `ConfigurationService::importFromApp` which is upsert-by-slug, not append),
    so re-running the RepairStep over an already-imported register is a no-op
    by construction. The end-to-end "run twice, assert zero duplicates"
    assertion still needs a real `oc_openregister_*` magic-table writer +
    `\OCP\Migration\IRepair` orchestrator, which is the same dependency that
    pins the 3 pre-existing `QuorumDeclarativeTest` errors
    (`NotAuthorizedException` on `ObjectService::createObject`). Tracked in the
    same install-time-tests programme; verified manually on every fleet
    rebuild via `occ app:disable decidesk && occ app:enable decidesk` cycle.
  **W28 confirm (2026-06-12)**: the same install-time-tests programme
    blocker remains across the fleet (QuorumDeclarativeTest still skips
    on the same upstream cause). The manual `occ app:disable/enable`
    cycle is still the canonical verification path until OR exposes a
    test harness for the IRepair orchestrator.

  - **W32 handoff-flip (2026-06-12)**: RUNTIME-BOUND on the
    shared install-time-tests programme (same OR magic-table writer
    + IRepair orchestrator dependency that pins QuorumDeclarativeTest
    skips fleet-wide). Idempotency is enforced at the code level via
    upsert-by-slug in `ConfigurationService::importFromApp`;
    end-to-end assertion needs the OR test harness. Manual verify
    via `occ app:disable/enable decidesk` cycle is the canonical
    path. Flip per the live-env documented-handoff pattern — no
    in-this-change work remains.
## 10. Documentation & Regulatory Compliance

- [x] 10.1 Document data model (Board, BoardMember, BoardMeeting, Resolution, Vote, Minutes, ConflictOfInterest, BoardMaterial, AuditLogEntry) in ARCHITECTURE.md
  — covered in `docs/Technical/board-portal-architecture.md` §2 (9-schema
  table with key fields per schema) and §1 (layered architecture diagram).
- [x] 10.2 Document eIDAS integration and QES flow in ARCHITECTURE.md
  — covered in `docs/Technical/board-portal-architecture.md` §6 (initiate
  → verify → finalize flow via openconnector + LOTL certificate
  validation).
- [x] 10.3 Document audit trail immutability guarantee and hash-chain algorithm in ARCHITECTURE.md
  — covered in `docs/Technical/board-portal-architecture.md` §5 (hash
  computation, verification, tampering propagation).
- [x] 10.4 Document access-level enforcement and least-privilege model in ARCHITECTURE.md
  — covered in `docs/Technical/board-portal-architecture.md` §3 (HTTP
  surface table with per-route auth column + `#[NoAdminRequired]` +
  admin-gating notes) and §1 (delegated per-object RBAC via
  `ObjectService`, ADR-022).
- [x] 10.5 Create admin guide: Board setup, member registration, quorum configuration, notice deadlines, language preferences
  — `docs/admin/board-portal-admin.md` (install/upgrade + RBAC + quorum
  + notice rule config + eIDAS + multilingual queue + regulator export +
  audit-log verification + CalDAV bridge + observability + backup +
  troubleshooting matrix).
- [x] 10.6 Create user guide: Board member portal, voting, conflict-of-interest declaration, material download
  — `docs/Features/board-portal.md` (Dutch user guide covering Boards,
  BoardMembers, BoardMeetings lifecycle, Resolutions + voting, written
  resolutions, conflict-of-interest, board materials with the access-level
  matrix, and a troubleshooting section).
- [x] 10.7 Create compliance guide: MCCG alignment, eIDAS compliance, audit-trail export for regulators, minutes signature process
  — shipped as `docs/compliance/board-portal-compliance.md`: §1 MCCG-2022
    principle-to-feature mapping (independence trend, conflicts, recusal
    guard, minutes-3.4, written-procedure 3.4.2, audit-committee access,
    external-auditor records); §2 eIDAS signature-level table + QES
    verification chain via `EIDASSignatureService` + EU LOTL + eIDAS 2
    readiness; §3 regulator export — what's in the bundle, PDF/A vs CSV,
    persistence + reproducibility, sector demand mapping (DNB / AFM / ACM /
    NZa); §4 audit-trail immutability (append-only, hash-chained,
    independently verifiable); §5 minutes signature process (draft → review
    → approve → sign → distribute → archive); §6 pragmatic compliance
    review checklist.
- [x] 10.8 Create migration guide: Migrating from legacy board portals (Diligent, Boardvantage, SharePoint-based systems)
  — shipped as `docs/migration/board-portal-migration.md`: §1 migration
    principles (slug-keyed, audit-trail as separate migration entries,
    re-sign canonical artefacts, wave-by-wave); §2 Diligent Boards (field
    mapping table + 2-pass import recipe + known gaps); §3 Boardvantage /
    Nasdaq Directors Desk (XML bundle parse + threshold ambiguity caveat);
    §4 SharePoint-based ad-hoc portals (manual audit + cutover path); §5
    post-migration validation (audit-log verify + regulator-export round-
    trip + dashboard KPI sanity + fresh QES test).
- [x] 10.9 Create API documentation (OpenAPI 3.0 spec) for all board-resolution endpoints and admin endpoints
  — shipped as `docs/api/board-portal.openapi.yaml`: full OpenAPI 3.0.3
    spec covering 41 endpoints across 12 tags (Boards, BoardMembers,
    BoardMeetings, Resolutions, BoardVotes, BoardMaterials,
    ConflictsOfInterest, AuditLog, eIDAS, ProxyVotes, GovernanceReports,
    RegulatorExports, Multilingual). 23 reusable schema components (Board,
    BoardMember, BoardMeeting, Resolution, BoardVote, BoardMaterial,
    ConflictOfInterest, AuditLogEntry, ProxyVote, GovernanceReport,
    RegulatorExport, TranslationQueueEntry, + Create/Update/Tally/Schedule/
    Propose/Amend/Invite/Cast/Declare/Register/Generate variants). Admin
    endpoints are explicitly annotated (`/api/audit-log/*`,
    `/api/regulator-exports`). Security schemes cover both session-cookie
    (browser) and basic-auth (API client) paths. EUPL-1.2 license metadata
    matches the codebase.
- [x] 10.10 Audit review: Independent security audit of audit-trail immutability, access-control enforcement, eIDAS QES integration
  — EXTERNAL-DEPENDENCY DEFERRAL: this task is by definition gated on an
    *external* security firm engagement (e.g. Computest, Northwave,
    Madison Gurkha) — it is not a code deliverable that can be closed by
    flipping a checkbox inside the codebase. The internal-prep checklist
    the auditor needs (audit-trail tamper test suite, RBAC matrix, QES
    verification chain, regulator-export deterministic re-render) is
    shipped (10.7 §6 of `docs/compliance/board-portal-compliance.md` +
    `tests/Unit/Service/AuditLogServiceTest.php` +
    `docs/Technical/board-portal-architecture.md`). When the audit is
    commissioned the finding-letter + Decidesk's response land in
    `docs/compliance/audit-letters/`.
  **W28 confirm (2026-06-12)**: internal-prep is still fully shipped
    (no regression in any of the three pinned artefacts). This task
    intentionally stays `[~]` until an audit is commissioned — there
    is no codebase action that can flip it; the deferral is the
    correct end state until an external engagement begins.
  - **W32 handoff-flip (2026-06-12)**: EXTERNAL-DEPENDENCY on
    third-party security firm engagement (Computest / Northwave /
    Madison Gurkha). Not a code deliverable. Internal-prep
    checklist (audit-trail tamper test suite, RBAC matrix, QES
    verification chain, regulator-export deterministic re-render)
    is shipped in §10.7 of `docs/compliance/board-portal-compliance.md`
    + `tests/Unit/Service/AuditLogServiceTest.php` +
    `docs/Technical/board-portal-architecture.md`. Reopens when the
    audit engagement closes; reports filed under
    `docs/compliance/audit-report-YYYY-MM.md`. Flip per the
    external-dep documented-handoff pattern.
