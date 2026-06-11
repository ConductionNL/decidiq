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
- [~] 2.5 Add methods to existing `ObjectService` integration:
  - `loadBoardWithMembers($boardId)`: Fetch Board + all BoardMembers in one call
  - `saveVote($voteData, $anonymized)`: Create Vote, optionally encrypt board-member-koppeling via HMAC if anonymized
  - `computeResolutionAdoption($resolutionId)`: Query votes and compute adoption status vs. threshold

## 3. eIDAS Integration & Minutes Signing

- [~] 3.1 Create `lib/Service/eIDASSignatureService.php` with methods:
  - `initializeSigningRequest($minutesId, $signatories)`: Call openconnector-e-sign API to create QES request; return signing URL and request-id
  - `verifySignature($requestId, $signature)`: Verify signature against EU Trusted List via docudesk-eidas; return {valid: boolean, certificate-thumbprint, timestamp}
  - `finalizeMinutes($minutesId, $signatureList)`: Generate signed PDF via docudesk-eidas, store with reference, compute SHA-256 hash, update Minutes record; return pdf-archive-reference
  - `validateCertificateChain($certificateThumbprint)`: Query EU eIDAS Trusted List; return validity status and issuer info
- [~] 3.2 Create `lib/Service/MinutesReconciliationService.php` with methods:
  - `reconcile($meetingId)`: Load Dutch and English Minutes; extract structured elements (resolutions, action items); compare counts; return {discrepancies: array, severity: warning|error}
  - `extractStructure($minutesContent)`: Parse rich-text content (via HTML/Markdown parser) to identify sections and entities; return {resolutionCount, sectionList}
  - `reportDiscrepancy($minutesId, $discrepancy)`: Append note to reconciliation-notes field; notify secretary
- [~] 3.3 Integrate openconnector-e-sign in controller:
  - Create `lib/Controller/MinutesSigningController.php` with endpoints:
    - `POST /api/minutes/{id}/initiate-signing`: Call eIDASSignatureService::initializeSigningRequest
    - `POST /api/minutes/{id}/verify-signature`: Receive signature blob, verify, store
    - `POST /api/minutes/{id}/finalize`: Trigger finalizeMinutes, update status to "signed"
- [~] 3.4 Add webhook listener for openconnector-e-sign signature callbacks (signature event = webhook → update Vote record if written-resolution)

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
- [~] 4.5 Implement access-control middleware in all controllers:
  - Check board-member role and access-level; enforce 403 for unauthorized access
  - All reads of board-specific data (materials, votes, conflicts, minutes) check access-level enum

## 5. Board Portal Backend: Special Procedures

- [~] 5.1 Create `lib/Controller/ProxyVotingController.php` with endpoints:
  - `POST /api/proxies`: Register proxy (grantor requests, secretary approves); validate expiration and scope
  - `GET /api/proxies/{meetingId}`: Return active proxies for meeting (with suspension status if member joins remotely)
  - `PUT /api/proxies/{id}/suspend`: Suspend proxy (when grantor joins remotely); log to audit trail
  - `DELETE /api/proxies/{id}`: Revoke proxy (automatic at meeting-close or manual by secretary); log to audit trail
- [~] 5.2 Create `lib/Service/WrittenResolutionService.php` with methods:
  - `initiate($resolutionData, $requiredSignatories, $responseDeadline)`: Create Resolution type="written-resolution", send signature requests via openconnector-e-sign
  - `collectSignature($resolutionId, $signatureBlob)`: Record vote (via eIDASSignatureService::verifySignature), update vote-timestamp
  - `finalize($resolutionId)`: Check unanimity (all required signatories signed), update status, generate Minutes, return adoption-status
- [~] 5.3 Create `lib/Service/GovernanceReportingService.php` with methods:
  - `generateAnnualReport($year, $format)`: Query BoardMeetings, Resolutions, Votes, BoardMembers for year; compute statistics; check compliance flags; return report object
  - `exportReport($reportId, $format)`: Serialize to PDF, Excel, or JSON per format parameter; return binary/file-stream
  - `complianceFlagCheck($data)`: Run checks (independence-ratio, meeting-frequency, attendance-rate, conflict-trends); return {passed: boolean, flags: array}
- [~] 5.4 Create `lib/Controller/GovernanceReportingController.php` with endpoints:
  - `POST /api/governance-reports`: Generate annual report (secretary/admin only); store in register; return report-id
  - `GET /api/governance-reports/{id}`: Return report detail + optional {format: pdf|excel|json}
  - `GET /api/governance-reports/{id}/export/{format}`: Download report in specified format
  - `GET /api/governance-reports`: List historical reports

## 6. Regulator Access & Multi-Language Support

- [~] 6.1 Create `lib/Service/RegulatorAccessService.php` with methods:
  - `grantAccess($recipientEmail, $scope, $duration)`: Generate time-bound JWT token, email access link, log grant
  - `validateToken($token)`: Verify JWT signature, check expiration, verify not-revoked; return {valid: boolean, scope, recipient}
  - `revokeToken($tokenId)`: Set status=revoked, log revocation
  - `filterByScope($scope, $data)`: Return filtered data per scope (audit-committee-only, all-resolutions, all-records)
- [~] 6.2 Create `lib/Controller/RegulatorAccessController.php` with endpoints (secretary/admin only):
  - `POST /api/auditor-access`: Create access grant
  - `GET /api/auditor-access`: List active grants
  - `DELETE /api/auditor-access/{id}`: Revoke access
  - Token validation middleware for `/api/auditor/*` routes: check token, log view, apply scope filtering
- [~] 6.3 Add i18n support for multilingual minutes:
  - Extend Minutes schema: language field (enum: nl, en, both, etc.)
  - Create `lib/Service/MultilingualMinutesService.php` with methods:
    - `createLinkedMinutes($meetingId, $languages)`: Create Minutes records for each language, link via relation
    - `syncTranslation($dutchMinutesId, $englishMinutesId)`: Bidirectional sync (partial to full translation workflow)
  - Minutes-signing applies to all linked language-versions together

## 7. CalDAV Integration (ADR-002)

- [~] 7.1 Implement `lib/Service/CalDavService.php` (or extend if exists) with methods:
  - `createBoardMeetingVEVENT($boardMeetingData)`: Create VEVENT in Nextcloud Calendar with X-DECIDESK-* properties
  - `updateBoardMeetingVEVENT($caldavUid, $updates)`: Modify VEVENT and sync OpenRegister wrapper
  - `readBoardMeetingData($caldavUid)`: Parse VEVENT ICS blob, extract X-DECIDESK properties
  - `getBoardMeetingsBetween($boardId, $startDate, $endDate)`: Query CalDAV for VEVENTs, return as BoardMeeting objects
- [~] 7.2 Add X-DECIDESK-* property registry to CalDAV storage (documented in design.md):
  - X-DECIDESK-BOARD-UID: Board reference
  - X-DECIDESK-LIFECYCLE: Board meeting lifecycle state
  - X-DECIDESK-QUORUM-REQUIRED: Integer
  - X-DECIDESK-NOTICE-DEADLINE-DAYS: Integer

## 8. Frontend Views (Portal, Admin, Reporting) — Deferred to T2/T3

- [~] 8.1 Board member portal views (T2):
  - Materials list with access-level filtering
  - Material detail with watermark preview
  - Voting interface (resolution detail, cast-vote, running tally for chairman)
  - Conflict-of-interest declaration form
  - Minutes view with language toggle
  - Offline download with encryption/watermarking
  - Proxy grant request form

- [~] 8.2 Admin views (T2):
  - Board configuration (type, governance-model, statuten, quorum-rule)
  - BoardMember management (add, edit, term-dates, role assignment)
  - Material access control (per-material access-level assignment)
  - Audit log viewer (query, filter, verify hash-chain, export)
  - Governance reporting dashboard
  - Regulator access grant/revoke management

- [~] 8.3 Secretary views (T2):
  - Meeting notice scheduling & distribution
  - Minutes preparation (draft, review, reconciliation check)
  - Minutes signing workflow (initiate, track signature collection, finalize)
  - Resolution proposal & voting control (open/close vote, running tally)
  - Proxy management (register, approve, suspend, revoke)
  - Written resolution workflow (initiate, track signatures, finalize)

- [~] 8.4 Dashboard/Reporting (T3):
  - KPI cards (meetings this quarter, resolutions, attendance, conflicts)
  - Independence ratio trend chart
  - Attendance trend per member
  - Conflict heat map

## 9. Testing & Verification

- [~] 9.1 Unit tests for all services (AuditLogService, ConflictOfInterestService, eIDASSignatureService, etc.)
- [~] 9.2 Integration tests for OpenRegister CRUD (Board, BoardMember, Resolution, Vote, Minutes, etc.)
- [~] 9.3 API endpoint tests for all controllers (authentication, authorization, edge cases)
- [~] 9.4 Audit trail integrity tests (hash-chain verification, tampering detection)
- [~] 9.5 CalDAV integration tests (VEVENT creation/read, X-property preservation)
- [~] 9.6 eIDAS signature verification tests (certificate validation, QES-level enforcement)
- [~] 9.7 Quorum computation tests (in-person, remote, proxy, threshold calculations)
- [~] 9.8 Written resolution workflow tests (signature collection, unanimity check, minutegeneration)
- [~] 9.9 Multilingual reconciliation tests (language-pair discrepancies, signature linking)
- [~] 9.10 Regulator access tests (token generation, scope filtering, view logging)
- [~] 9.11 Install/upgrade tests (RepairStep runs idempotently, seed data loads, no duplicates on re-run)

## 10. Documentation & Regulatory Compliance

- [~] 10.1 Document data model (Board, BoardMember, BoardMeeting, Resolution, Vote, Minutes, ConflictOfInterest, BoardMaterial, AuditLogEntry) in ARCHITECTURE.md
- [~] 10.2 Document eIDAS integration and QES flow in ARCHITECTURE.md
- [~] 10.3 Document audit trail immutability guarantee and hash-chain algorithm in ARCHITECTURE.md
- [~] 10.4 Document access-level enforcement and least-privilege model in ARCHITECTURE.md
- [~] 10.5 Create admin guide: Board setup, member registration, quorum configuration, notice deadlines, language preferences
- [~] 10.6 Create user guide: Board member portal, voting, conflict-of-interest declaration, material download
- [~] 10.7 Create compliance guide: MCCG alignment, eIDAS compliance, audit-trail export for regulators, minutes signature process
- [~] 10.8 Create migration guide: Migrating from legacy board portals (Diligent, Boardvantage, SharePoint-based systems)
- [~] 10.9 Create API documentation (OpenAPI 3.0 spec) for all board-resolution endpoints and admin endpoints
- [~] 10.10 Audit review: Independent security audit of audit-trail immutability, access-control enforcement, eIDAS QES integration
