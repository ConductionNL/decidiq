## 1. Schema Registration & Data Model

> ADR-037: NEW schemas + seed objects live in `lib/Settings/register.d/40-board-meeting-resolutions.json` (fragment), NOT the `decidesk_register.json` monolith. The existing `SettingsService::loadConfiguration()` + `deepMergeConfig()` already union fragment `components.schemas` additively and re-import on change (fragment signature folded into the version), so a NEW RepairStep is unnecessary — `lib/Repair/InitializeSettings` already loads the fragment on install/upgrade. The `Vote` schema name collides with the existing council `Vote`, so the board ballot schema is named `BoardVote`; the audit entry is `BoardAuditLogEntry`; proxy is `BoardProxy`. `BoardMember` links to the existing `participant` schema (the NC-synced person entity) rather than inventing a person schema (guardrail).

- [x] 1.1 Add `Board` schema (fragment) — type/governance-model/statuten/quorum/notice/retention.
- [x] 1.2 Add `BoardMember` schema — links `participant` + `board`; rol/term/independence/nevenfuncties.
- [x] 1.3 Add `BoardMeeting` schema — lifecycle states + CalDAV wrapper (caldavUid/caldavIcsBlob).
- [x] 1.4 Add `Resolution` schema — resolution-number/type/vote-type/threshold/status + voteOpen.
- [x] 1.5 Add `BoardVote` schema (renamed from `Vote` to avoid council-Vote collision) — anonymized + voterToken HMAC, proxyHolderToken.
- [x] 1.6 Add `BoardMinutes` schema — language/version/signedBy/eidas-level/hash/reconciliation/linkedMinutes.
- [x] 1.7 Add `ConflictOfInterest` schema — declaration-type/severity/action-taken.
- [x] 1.8 Add `BoardMaterial` schema — access-level compartment + watermark.
- [x] 1.9 Add `BoardAuditLogEntry` schema — actor/action/object-uids/previous-hash/current-hash/immutable-blob.
- [x] 1.10 Add seed data: 3 boards, 10 board members, 5 board meetings, 10 resolutions, 25 board votes, 5 minutes, 8 conflict declarations, 1 proxy.
- [x] 1.11 RepairStep — satisfied by existing `lib/Repair/InitializeSettings` which loads `register.d/*.json` fragments (ADR-037); no new repair step needed.
- [x] 1.12 Repair registration — already present in `appinfo/info.xml` `<repair-steps>`.
- [DEFER] 1.13 Verify schemas created + seed loads — requires a live OpenRegister instance (`occ upgrade`). Fragment validity + additive merge are covered by `BoardRegisterFragmentTest`.

## 2. Service Layer: Audit Trail & Conflict Management

- [x] 2.1 `BoardAuditLogService` — append (SHA-256 chained), verify (tamper detection), export (json/csv), query (filters). Uses real ObjectService API (find/findAll/saveObject).
- [x] 2.2 `ConflictOfInterestService` — requireDeclaration / declare (notifies chair on material) / recordAction / getActiveConflict / isBarredFromVoting.
- [x] 2.3 `BoardMaterialAuthorizationService` — canViewMaterial / filterMaterialsByRole / logMaterialAccess (role→compartment matrix, least-privilege).
- [x] 2.4 `QuorumVerificationService` — computeQuorum / getAttendanceReport / verifyAttendance / requiredVotesFor (qualified majority vs total seats, REQ-008).
- [x] 2.5 `BoardVotingService` — saveVote (HMAC anonymisation, idempotent), computeResolutionAdoption, closeVote, voterToken.

## 3. eIDAS Integration & Minutes Signing

- [x] 3.1 `EidasSignatureService` — initializeSigningRequest / verifySignature / finalizeMinutes / isSigningProviderAvailable (openconnector-e-sign + docudesk-eidas abstraction).
- [x] 3.2 `MinutesReconciliationService` — reconcile / extractStructure / reconcileContents (language-agnostic structural fingerprint).
- [x] 3.3 `BoardMinutesSigningController` — initiate-signing / finalize (secretary-gated).
- [DEFER] 3.4 Live openconnector-e-sign webhook callback wiring — requires a configured QES provider + live openconnector instance. The verify/finalize flow and audit trail are implemented and unit-tested; the provider round-trip is abstracted behind `EidasSignatureService`.

## 4. Board Portal Backend: Materials & Access Control

- [x] 4.1 `BoardMaterialController` — index (role-filtered) / show (access-level enforced + audit-logged). IDOR-safe (scoped to caller, 403 on disallowed compartment).
- [x] 4.2 `BoardVotingController` — cast-vote / close-vote (chair-only) / running-tally (chair-only).
- [x] 4.3 `BoardConflictController` — declare / action.
- [x] 4.4 `BoardAuditLogController` — query/export (secretary-only) / verify hash chain.
- [x] 4.5 Access-control enforced in every controller: `#[NoAdminRequired]` + per-method role/compartment guards (ADR-005), 401 anon / 403 unauthorised.

## 5. Board Portal Backend: Special Procedures

- [x] 5.1 `BoardProxyService` + `BoardProxyController` — register / suspend / revoke / revokeAllForMeeting / isActiveForResolution (per-agenda-item scope, auto-revoke at close).
- [x] 5.2 `WrittenResolutionService` — initiate / collectSignature (via eIDAS verify) / isUnanimous / finalize (rondvraag-besluit, REQ-011).
- [x] 5.3 `GovernanceReportingService` — generateAnnualReport / independenceRatio / complianceFlagCheck (Code statistics + flags).
- [x] 5.4 `BoardGovernanceController` — generate annual report (secretary-only).

## 6. Regulator Access & Multi-Language Support

- [x] 6.1 `RegulatorAccessService` — grantAccess (HMAC-signed time-bound bearer) / validateToken / filterByScope (REQ-009).
- [x] 6.2 `BoardRegulatorController` — grant (secretary-only) + `auditorRecords` token-gated `#[PublicPage]`+`#[NoCSRFRequired]` (validates signed bearer, logs every view — NOT an open endpoint, ADR-005).
- [x] 6.3 `MultilingualMinutesService` — createLinkedMinutes (parallel-language linking). BoardMinutes already has `language`/`linkedMinutes`/`reconciliationNotes`.

## 7. CalDAV Integration (ADR-002)

- [x] 7.1 `BoardCalDavService` — createBoardMeetingVevent / readBoardMeetingData / syncMeetingVevent (ICS build/parse round-trip, unit-tested).
- [x] 7.2 X-DECIDESK-* property registry (BOARD-UID / LIFECYCLE / QUORUM-REQUIRED / NOTICE-DEADLINE-DAYS) emitted + parsed.
- [DEFER] 7.x Writing the VEVENT into a live Nextcloud calendar backend requires a running CalDAV server — deferred; the ICS blob is persisted on the BoardMeeting record.

## 8. Frontend Views (Portal, Admin, Reporting) — Deferred to T2/T3

- [x] 8.0 Declarative manifest-v2 pages fragment `src/manifest.d/40-board-meeting-resolutions.json` — Boards / BoardMeetings / Resolutions index + detail pages + menu entries (no router/MainMenu, ADR-037).
- [DEFER] 8.1 Bespoke board-member portal Vue views (watermark preview, offline encrypted download, voting interface widgets) — T2, custom components beyond the declarative index/detail shell.
- [DEFER] 8.2 Bespoke admin views (custom access-control matrix editor, audit hash-chain viewer widget) — T2.
- [DEFER] 8.3 Bespoke secretary views (signing workflow tracker, proxy management board) — T2.
- [DEFER] 8.4 Dashboard KPI/trend charts (independence ratio, attendance, conflict heat map) — T3.

## 9. Testing & Verification

- [x] 9.1 Unit tests for deterministic service logic (audit hash chain, quorum threshold math, governance ratios/flags, reconciliation, material matrix, proxy scope, regulator token, eIDAS verify, written-resolution unanimity, CalDav ICS).
- [DEFER] 9.2 Integration tests for OpenRegister CRUD — blocked by decidesk#90 (real ObjectService loads over the stub in an OR-installed env); existing service tests are `markTestSkipped` for the same reason. The board services use the identical real ObjectService API as the council services.
- [x] 9.3 API endpoint authorization is enforced + exercised via the controller guards (401/403/400 branches).
- [x] 9.4 Audit trail integrity tests (hash-chain + tamper detection) — `BoardAuditLogServiceTest`.
- [x] 9.5 CalDAV integration tests (VEVENT build/parse, X-property preservation) — `BoardCalDavServiceTest`.
- [x] 9.6 eIDAS signature verification tests — `EidasSignatureServiceTest`.
- [x] 9.7 Quorum computation tests — `QuorumVerificationServiceTest`.
- [x] 9.8 Written resolution workflow tests (unanimity) — `WrittenResolutionServiceTest`.
- [x] 9.9 Multilingual reconciliation tests — `MinutesReconciliationServiceTest`.
- [x] 9.10 Regulator access tests (token sign/validate/scope) — `RegulatorAccessServiceTest`.
- [DEFER] 9.11 Install/upgrade idempotency test — requires a live NC+OR instance to run the repair step.

## 10. Documentation & Regulatory Compliance

- [DEFER] 10.1–10.9 ARCHITECTURE.md data-model / eIDAS flow / audit-immutability / least-privilege docs, admin/user/compliance/migration guides, OpenAPI 3.0 — deferred to a docs follow-up (the OpenAPI surface is the register fragment + routes; guides are non-code documentation).
- [DEFER] 10.10 Independent external security audit of audit-trail immutability + eIDAS QES — external engagement, out of build scope.
