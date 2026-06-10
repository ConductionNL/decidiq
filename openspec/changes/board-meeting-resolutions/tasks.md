## 1. Schema Registration & Data Model

- [~] 1.1 Add `Board` schema to `lib/Settings/decidesk_register.json` with properties: name, type (enum: raad-van-commissarissen, raad-van-bestuur, audit-committee, remuneration-committee, nomination-committee, risk-committee, one-tier-board), legal-entity, governance-model (enum: two-tier, one-tier), establishment-date, statuten-reference, chairman, vice-chairman, secretary, default-language, additional-languages (array), quorum-rule, notice-deadline-days, material-retention-days — deferred to downstream cycle (handoff)
- [~] 1.2 Add `BoardMember` schema with properties: persoon-koppeling, board-koppeling, rol (enum: chairman, vice-chairman, member, executive-member, non-executive-member, independent-member, employee-representative), appointment-date, appointment-resolution-reference, term-end-date, reappointment-eligible, nationality, nevenfuncties (array of strings), independence-status (enum: independent, non-independent) — deferred to downstream cycle (handoff)
- [~] 1.3 Add `BoardMeeting` schema with CalDAV wrapper: board-koppeling, meeting-type (enum: regular, extraordinary, strategy-day, closed-session, executive-session), meeting-date, meeting-start, meeting-end, location, format (enum: in-person, remote, hybrid), language (enum: nl, en, both), status (enum: scheduled, notice-sent, materials-distributed, in-session, adjourned, closed, minutes-signed), notice-sent-date, materials-deadline, quorum-required, quorum-achieved, recording-allowed, caldav-uid, caldav-ics-blob — deferred to downstream cycle (handoff)
- [~] 1.4 Add `Resolution` schema with properties: meeting-koppeling, resolution-number (format: R-{year}-{number}), title, type (enum: approval, appointment, dismissal, financial, strategic, policy, delegation-of-authority, acknowledgement, written-resolution), proposing-member, full-text (rich text), background (rich text), legal-basis, vote-type (enum: named, anonymous, unanimous-consent, acclamation), vote-threshold (enum: simple-majority, qualified-majority-two-thirds, qualified-majority-three-quarters, unanimous), status (enum: proposed, under-discussion, adopted, rejected, withdrawn, tabled), adoption-date, effective-date — deferred to downstream cycle (handoff)
- [~] 1.5 Add `Vote` schema with properties: resolution-koppeling, board-member-koppeling, vote (enum: in-favor, against, abstain, absent, recused-due-to-conflict), vote-timestamp, vote-method (enum: raised-hand, electronic, written-ballot, proxy), proxy-holder (board-member-koppeling if proxy), anonymized (boolean) — deferred to downstream cycle (handoff)
- [~] 1.6 Add `Minutes` schema with properties: meeting-koppeling, language (enum: nl, en), version (enum: draft, final, signed), content (rich text), prepared-by (company-secretary), reviewed-by (chairman), signed-by (array of {signer-uuid, signature-timestamp, certificate-thumbprint}), signing-completion-date, eidas-signature-level (enum: SES, AdES, QES), pdf-archive-reference, hash-sha256, reconciliation-notes — deferred to downstream cycle (handoff)
- [~] 1.7 Add `ConflictOfInterest` schema with properties: board-member-koppeling, agenda-item-koppeling, declaration-type (enum: financial-interest, personal-relationship, competing-business, prior-involvement, none), description (text), severity (enum: material, non-material), action-taken (enum: recused-from-discussion, recused-from-vote, disclosed-and-participated, no-action-needed), declaration-timestamp — deferred to downstream cycle (handoff)
- [~] 1.8 Add `BoardMaterial` schema with properties: meeting-koppeling, agenda-item-koppeling, title, document-reference (docudesk handle), access-level (enum: board-only, executive-only, audit-committee, external-auditor, regulator), distribution-timestamp, watermarked (boolean), watermark-text (per-member override field) — deferred to downstream cycle (handoff)
- [~] 1.9 Add `AuditLogEntry` schema (internal) with properties: actor-uuid, action (enum: vote, conflict-declaration, material-access, signature, notice-sent, proxy-created, proxy-revoked), object-uids (array), timestamp, previous-hash (SHA-256), current-hash (SHA-256), immutable-blob (full serialization for forensic audit) — deferred to downstream cycle (handoff)
- [~] 1.10 Add seed data: 3 boards (RvC listed company, RvB listed company, audit-committee housing-corp), 10 board members with mixed roles and independence-status values, 5 board meetings (various states), 10 resolutions with different types and vote-thresholds, 25 votes (named, anonymous, proxy, absent, recused), 5 minutes records (draft, final, signed), 8 conflict declarations (varying severity and action-taken) — deferred to downstream cycle (handoff)
- [~] 1.11 Create `lib/Migration/RepairStep.php` implementing `IRepairStep` that calls `ConfigurationService::importFromApp('decidesk')` to register all 9 schemas — deferred to downstream cycle (handoff)
- [~] 1.12 Register `RepairStep` in `appinfo/info.xml` under `<repair-steps><post-migration>` — deferred to downstream cycle (handoff)
- [~] 1.13 Verify all 9 schemas are created and seed data loads (≥3 per core schema) — deferred to downstream cycle (handoff)

## 2. Service Layer: Audit Trail & Conflict Management

- [~] 2.1 Create `lib/Service/AuditLogService.php` with methods: — deferred to downstream cycle (handoff)
  - `append($actor, $action, $objectUids)`: Create AuditLogEntry, compute SHA-256 hash (timestamp + actor + action + objectUids + previousHash), return new entry
  - `verify($entryId)`: Load entry and all previous entries, recompute hashes to detect tampering; return boolean and tampering details
  - `export($startDate, $endDate)`: Return audit log as JSON or CSV for external auditor; include hash chain
  - `query($filters)`: Filterable query (actor, action, date-range, object-uuid)
- [~] 2.2 Create `lib/Service/ConflictOfInterestService.php` with methods: — deferred to downstream cycle (handoff)
  - `requireDeclaration($boardMemberId, $agendaItemId)`: Check if ConflictOfInterest exists for pair; return boolean
  - `declare($boardMemberId, $agendaItemId, $type, $description)`: Create declaration record, send notification to chairman if material
  - `recordAction($declarationId, $actionTaken)`: Update action-taken field, enforce view/vote restrictions if needed
  - `getActiveConflicts($boardMemberId, $agendaItemId)`: Return conflict record if exists with action-taken state
- [~] 2.3 Create `lib/Service/BoardMaterialAuthorizationService.php` with methods: — deferred to downstream cycle (handoff)
  - `canViewMaterial($boardMemberId, $materialId)`: Check access-level enum vs. board-member role; return boolean
  - `filterMaterialsByRole($boardId, $role)`: Return list of materials accessible to role
  - `logMaterialAccess($boardMemberId, $materialId, $granted)`: Log attempt (granted/denied) to audit trail
- [~] 2.4 Create `lib/Service/QuorumVerificationService.php` with methods: — deferred to downstream cycle (handoff)
  - `computeQuorum($meetingId)`: Count in-person + remote + valid-proxies; return {total, threshold, met: boolean}
  - `verifyAttendance($meetingId, $participantType)`: Validate participant (in-person, remote, proxy-holder, etc.); return boolean
  - `getAttendanceReport($meetingId)`: Return detailed breakdown per member (in-person, remote, proxy, absent)
- [~] 2.5 Add methods to existing `ObjectService` integration: — deferred to downstream cycle (handoff)
  - `loadBoardWithMembers($boardId)`: Fetch Board + all BoardMembers in one call
  - `saveVote($voteData, $anonymized)`: Create Vote, optionally encrypt board-member-koppeling via HMAC if anonymized
  - `computeResolutionAdoption($resolutionId)`: Query votes and compute adoption status vs. threshold

## 3. eIDAS Integration & Minutes Signing

- [~] 3.1 Create `lib/Service/eIDASSignatureService.php` with methods: — deferred to downstream cycle (handoff)
  - `initializeSigningRequest($minutesId, $signatories)`: Call openconnector-e-sign API to create QES request; return signing URL and request-id
  - `verifySignature($requestId, $signature)`: Verify signature against EU Trusted List via docudesk-eidas; return {valid: boolean, certificate-thumbprint, timestamp}
  - `finalizeMinutes($minutesId, $signatureList)`: Generate signed PDF via docudesk-eidas, store with reference, compute SHA-256 hash, update Minutes record; return pdf-archive-reference
  - `validateCertificateChain($certificateThumbprint)`: Query EU eIDAS Trusted List; return validity status and issuer info
- [~] 3.2 Create `lib/Service/MinutesReconciliationService.php` with methods: — deferred to downstream cycle (handoff)
  - `reconcile($meetingId)`: Load Dutch and English Minutes; extract structured elements (resolutions, action items); compare counts; return {discrepancies: array, severity: warning|error}
  - `extractStructure($minutesContent)`: Parse rich-text content (via HTML/Markdown parser) to identify sections and entities; return {resolutionCount, sectionList}
  - `reportDiscrepancy($minutesId, $discrepancy)`: Append note to reconciliation-notes field; notify secretary
- [~] 3.3 Integrate openconnector-e-sign in controller: — deferred to downstream cycle (handoff)
  - Create `lib/Controller/MinutesSigningController.php` with endpoints:
    - `POST /api/minutes/{id}/initiate-signing`: Call eIDASSignatureService::initializeSigningRequest
    - `POST /api/minutes/{id}/verify-signature`: Receive signature blob, verify, store
    - `POST /api/minutes/{id}/finalize`: Trigger finalizeMinutes, update status to "signed"
- [~] 3.4 Add webhook listener for openconnector-e-sign signature callbacks (signature event = webhook → update Vote record if written-resolution) — deferred to downstream cycle (handoff)

## 4. Board Portal Backend: Materials & Access Control

- [~] 4.1 Create `lib/Controller/BoardMaterialController.php` with endpoints: — deferred to downstream cycle (handoff)
  - `GET /api/board-materials`: List filtered by access-level + board-member role; pagination
  - `GET /api/board-materials/{id}`: Return material detail + watermark metadata; log access via AuditLogService::logMaterialAccess
  - `POST /api/board-materials/{id}/download`: Stream encrypted file (AES-256 key = member.id + device.uuid hash); return headers for app-side decryption
- [~] 4.2 Create `lib/Controller/VotingController.php` with endpoints: — deferred to downstream cycle (handoff)
  - `GET /api/resolutions/{id}/votes`: Return vote list (tally if chairman, else anonymous aggregate); filter by access-level (vote-type, anonymization)
  - `POST /api/resolutions/{id}/cast-vote`: Record vote, timestamp, method; enforce: ConflictOfInterest checked, quorum verified, vote-status = "open", no vote-changes after close
  - `POST /api/resolutions/{id}/close-vote`: Chairman only; finalize vote counts, compute adoption-status, update Resolution.status
  - `GET /api/resolutions/{id}/running-tally`: Chairman only; return real-time vote counts during open voting
- [~] 4.3 Create `lib/Controller/ConflictOfInterestController.php` with endpoints: — deferred to downstream cycle (handoff)
  - `POST /api/conflicts/declare`: Create ConflictOfInterest; enforce material conflicts notify chairman/secretary
  - `GET /api/board-members/{id}/conflicts`: Return all conflicts for board-member per meeting
  - `PUT /api/conflicts/{id}/action`: Update action-taken, enforce access restrictions (recuse = no-read, no-vote)
- [~] 4.4 Create `lib/Controller/AuditLogController.php` with endpoints (secretary/admin only): — deferred to downstream cycle (handoff)
  - `GET /api/audit-log`: Query with filters (actor, action, date-range, object-uuid); pagination; return JSON/CSV export
  - `GET /api/audit-log/{id}/verify`: Verify hash chain from entry to root; return tampering status
- [~] 4.5 Implement access-control middleware in all controllers: — deferred to downstream cycle (handoff)
  - Check board-member role and access-level; enforce 403 for unauthorized access
  - All reads of board-specific data (materials, votes, conflicts, minutes) check access-level enum

## 5. Board Portal Backend: Special Procedures

- [~] 5.1 Create `lib/Controller/ProxyVotingController.php` with endpoints: — deferred to downstream cycle (handoff)
  - `POST /api/proxies`: Register proxy (grantor requests, secretary approves); validate expiration and scope
  - `GET /api/proxies/{meetingId}`: Return active proxies for meeting (with suspension status if member joins remotely)
  - `PUT /api/proxies/{id}/suspend`: Suspend proxy (when grantor joins remotely); log to audit trail
  - `DELETE /api/proxies/{id}`: Revoke proxy (automatic at meeting-close or manual by secretary); log to audit trail
- [~] 5.2 Create `lib/Service/WrittenResolutionService.php` with methods: — deferred to downstream cycle (handoff)
  - `initiate($resolutionData, $requiredSignatories, $responseDeadline)`: Create Resolution type="written-resolution", send signature requests via openconnector-e-sign
  - `collectSignature($resolutionId, $signatureBlob)`: Record vote (via eIDASSignatureService::verifySignature), update vote-timestamp
  - `finalize($resolutionId)`: Check unanimity (all required signatories signed), update status, generate Minutes, return adoption-status
- [~] 5.3 Create `lib/Service/GovernanceReportingService.php` with methods: — deferred to downstream cycle (handoff)
  - `generateAnnualReport($year, $format)`: Query BoardMeetings, Resolutions, Votes, BoardMembers for year; compute statistics; check compliance flags; return report object
  - `exportReport($reportId, $format)`: Serialize to PDF, Excel, or JSON per format parameter; return binary/file-stream
  - `complianceFlagCheck($data)`: Run checks (independence-ratio, meeting-frequency, attendance-rate, conflict-trends); return {passed: boolean, flags: array}
- [~] 5.4 Create `lib/Controller/GovernanceReportingController.php` with endpoints: — deferred to downstream cycle (handoff)
  - `POST /api/governance-reports`: Generate annual report (secretary/admin only); store in register; return report-id
  - `GET /api/governance-reports/{id}`: Return report detail + optional {format: pdf|excel|json}
  - `GET /api/governance-reports/{id}/export/{format}`: Download report in specified format
  - `GET /api/governance-reports`: List historical reports

## 6. Regulator Access & Multi-Language Support

- [~] 6.1 Create `lib/Service/RegulatorAccessService.php` with methods: — deferred to downstream cycle (handoff)
  - `grantAccess($recipientEmail, $scope, $duration)`: Generate time-bound JWT token, email access link, log grant
  - `validateToken($token)`: Verify JWT signature, check expiration, verify not-revoked; return {valid: boolean, scope, recipient}
  - `revokeToken($tokenId)`: Set status=revoked, log revocation
  - `filterByScope($scope, $data)`: Return filtered data per scope (audit-committee-only, all-resolutions, all-records)
- [~] 6.2 Create `lib/Controller/RegulatorAccessController.php` with endpoints (secretary/admin only): — deferred to downstream cycle (handoff)
  - `POST /api/auditor-access`: Create access grant
  - `GET /api/auditor-access`: List active grants
  - `DELETE /api/auditor-access/{id}`: Revoke access
  - Token validation middleware for `/api/auditor/*` routes: check token, log view, apply scope filtering
- [~] 6.3 Add i18n support for multilingual minutes: — deferred to downstream cycle (handoff)
  - Extend Minutes schema: language field (enum: nl, en, both, etc.)
  - Create `lib/Service/MultilingualMinutesService.php` with methods:
    - `createLinkedMinutes($meetingId, $languages)`: Create Minutes records for each language, link via relation
    - `syncTranslation($dutchMinutesId, $englishMinutesId)`: Bidirectional sync (partial to full translation workflow)
  - Minutes-signing applies to all linked language-versions together

## 7. CalDAV Integration (ADR-002)

- [~] 7.1 Implement `lib/Service/CalDavService.php` (or extend if exists) with methods: — deferred to downstream cycle (handoff)
  - `createBoardMeetingVEVENT($boardMeetingData)`: Create VEVENT in Nextcloud Calendar with X-DECIDESK-* properties
  - `updateBoardMeetingVEVENT($caldavUid, $updates)`: Modify VEVENT and sync OpenRegister wrapper
  - `readBoardMeetingData($caldavUid)`: Parse VEVENT ICS blob, extract X-DECIDESK properties
  - `getBoardMeetingsBetween($boardId, $startDate, $endDate)`: Query CalDAV for VEVENTs, return as BoardMeeting objects
- [~] 7.2 Add X-DECIDESK-* property registry to CalDAV storage (documented in design.md): — deferred to downstream cycle (handoff)
  - X-DECIDESK-BOARD-UID: Board reference
  - X-DECIDESK-LIFECYCLE: Board meeting lifecycle state
  - X-DECIDESK-QUORUM-REQUIRED: Integer
  - X-DECIDESK-NOTICE-DEADLINE-DAYS: Integer

## 8. Frontend Views (Portal, Admin, Reporting) — Deferred to T2/T3

- [~] 8.1 Board member portal views (T2): — deferred to downstream cycle (handoff)
  - Materials list with access-level filtering
  - Material detail with watermark preview
  - Voting interface (resolution detail, cast-vote, running tally for chairman)
  - Conflict-of-interest declaration form
  - Minutes view with language toggle
  - Offline download with encryption/watermarking
  - Proxy grant request form

- [~] 8.2 Admin views (T2): — deferred to downstream cycle (handoff)
  - Board configuration (type, governance-model, statuten, quorum-rule)
  - BoardMember management (add, edit, term-dates, role assignment)
  - Material access control (per-material access-level assignment)
  - Audit log viewer (query, filter, verify hash-chain, export)
  - Governance reporting dashboard
  - Regulator access grant/revoke management

- [~] 8.3 Secretary views (T2): — deferred to downstream cycle (handoff)
  - Meeting notice scheduling & distribution
  - Minutes preparation (draft, review, reconciliation check)
  - Minutes signing workflow (initiate, track signature collection, finalize)
  - Resolution proposal & voting control (open/close vote, running tally)
  - Proxy management (register, approve, suspend, revoke)
  - Written resolution workflow (initiate, track signatures, finalize)

- [~] 8.4 Dashboard/Reporting (T3): — deferred to downstream cycle (handoff)
  - KPI cards (meetings this quarter, resolutions, attendance, conflicts)
  - Independence ratio trend chart
  - Attendance trend per member
  - Conflict heat map

## 9. Testing & Verification

- [~] 9.1 Unit tests for all services (AuditLogService, ConflictOfInterestService, eIDASSignatureService, etc.) — deferred to downstream cycle (handoff)
- [~] 9.2 Integration tests for OpenRegister CRUD (Board, BoardMember, Resolution, Vote, Minutes, etc.) — deferred to downstream cycle (handoff)
- [~] 9.3 API endpoint tests for all controllers (authentication, authorization, edge cases) — deferred to downstream cycle (handoff)
- [~] 9.4 Audit trail integrity tests (hash-chain verification, tampering detection) — deferred to downstream cycle (handoff)
- [~] 9.5 CalDAV integration tests (VEVENT creation/read, X-property preservation) — deferred to downstream cycle (handoff)
- [~] 9.6 eIDAS signature verification tests (certificate validation, QES-level enforcement) — deferred to downstream cycle (handoff)
- [~] 9.7 Quorum computation tests (in-person, remote, proxy, threshold calculations) — deferred to downstream cycle (handoff)
- [~] 9.8 Written resolution workflow tests (signature collection, unanimity check, minutegeneration) — deferred to downstream cycle (handoff)
- [~] 9.9 Multilingual reconciliation tests (language-pair discrepancies, signature linking) — deferred to downstream cycle (handoff)
- [~] 9.10 Regulator access tests (token generation, scope filtering, view logging) — deferred to downstream cycle (handoff)
- [~] 9.11 Install/upgrade tests (RepairStep runs idempotently, seed data loads, no duplicates on re-run) — deferred to downstream cycle (handoff)

## 10. Documentation & Regulatory Compliance

- [~] 10.1 Document data model (Board, BoardMember, BoardMeeting, Resolution, Vote, Minutes, ConflictOfInterest, BoardMaterial, AuditLogEntry) in ARCHITECTURE.md — deferred to downstream cycle (handoff)
- [~] 10.2 Document eIDAS integration and QES flow in ARCHITECTURE.md — deferred to downstream cycle (handoff)
- [~] 10.3 Document audit trail immutability guarantee and hash-chain algorithm in ARCHITECTURE.md — deferred to downstream cycle (handoff)
- [~] 10.4 Document access-level enforcement and least-privilege model in ARCHITECTURE.md — deferred to downstream cycle (handoff)
- [~] 10.5 Create admin guide: Board setup, member registration, quorum configuration, notice deadlines, language preferences — deferred to downstream cycle (handoff)
- [~] 10.6 Create user guide: Board member portal, voting, conflict-of-interest declaration, material download — deferred to downstream cycle (handoff)
- [~] 10.7 Create compliance guide: MCCG alignment, eIDAS compliance, audit-trail export for regulators, minutes signature process — deferred to downstream cycle (handoff)
- [~] 10.8 Create migration guide: Migrating from legacy board portals (Diligent, Boardvantage, SharePoint-based systems) — deferred to downstream cycle (handoff)
- [~] 10.9 Create API documentation (OpenAPI 3.0 spec) for all board-resolution endpoints and admin endpoints — deferred to downstream cycle (handoff)
- [~] 10.10 Audit review: Independent security audit of audit-trail immutability, access-control enforcement, eIDAS QES integration — deferred to downstream cycle (handoff)
