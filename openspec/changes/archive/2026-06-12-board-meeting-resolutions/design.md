## Status: draft

## Context

The decidesk platform (ADR-000) provides foundational schemas for meetings, motions, voting, and governance bodies. However, corporate and institutional boards operate under significantly stricter regulatory and audit requirements than municipal councils:

1. **Immutability for legal defense**: Every vote, conflict declaration, and materials access must be recorded with cryptographic integrity (SHA-256 chained log) so that subsequent regulator investigations or shareholder litigation can establish what was known, when, and by whom.

2. **Principle of least privilege**: A non-executive director should not see executive-session preparations; an audit committee member should not see remuneration committee deliberations on their own compensation. The OpenRegister built-in relation mechanism and CalDAV access lists are insufficient — access control must be enforced at the data-visibility layer via an `access-level` field on BoardMaterial.

3. **Jurisdictional pragmatism**: While the Dutch Corporate Governance Code is the primary reference for listed entities, the spec must support housing corporations (Woningwet, Autoriteit woningcorporaties supervision), healthcare boards (WTZi/WMG, NZa supervision), and foundations (WBTR, Aw supervision) without per-sector forks. The data model uses role enums and board-type enums to encode this variety.

This change adds 8 new entities to the decidesk register — Board, BoardMember, BoardMeeting, Resolution, Vote, Minutes, ConflictOfInterest, BoardMaterial — along with supporting services for eIDAS QES signing, conflict tracking, audit logging, and regulator access.

## Goals / Non-Goals

**Goals:**
- Define 8 new OpenRegister schemas with all properties needed for board-portal, board-meetings, resolutions-and-voting, conflict-of-interest, eIDAS signatures, multilingual minutes, audit trail, and regulator access workflows
- Implement immutable audit trail with SHA-256 chaining for every vote, conflict declaration, and material access
- Enforce access-level (board-only, executive-only, audit-committee, external-auditor, regulator) at query time in board-portal views
- Integrate with openconnector-e-sign and docudesk-eidas for eIDAS Qualified Electronic Signatures
- Provide seed data (3–5 Dutch boards, 5–10 board members per board, 5+ meetings with resolutions, votes, conflicts, and minutes)
- Support one-tier and two-tier governance models, per-board quorum rules, configurable notice deadlines, and role hierarchies
- Implement CalDAV-first storage (ADR-002) for BoardMeeting as VEVENT with X-DECIDESK-* properties

**Non-Goals:**
- Board-portal frontend views (subsequent sprint — T2 or T3)
- Admin views for board configuration (subsequent sprint)
- Mobile app (subsequent sprint)
- Integration with Teams/Webex/Zoom (openconnector handles this)
- Integration with OpenCatalogi for public-resolution publishing (p3-ori-publication)
- Real-time video conferencing for remote meetings (opentalk provides this)
- Machine-translation of minutes (Deepl or similar handles this)

## Decisions

### Decision 1: Board, BoardMember, and BoardMeeting as separate entities from GovernanceBody/Meeting

The generic GovernanceBody and Meeting entities (ADR-000) model all governance bodies and meetings uniformly. Board-specific requirements (conflict-of-interest declarations, eIDAS-qualified signatures on minutes, granular access control per material, immutable audit trail) require board-specific schemas.

**Trade-off**: Increases schema count by 3 but allows board-specific workflows without conditional logic polluting generic entities. Alternative (extend GovernanceBody/Meeting with board-specific fields) was rejected because most municipal councils do not need conflict-of-interest declarations, and adding them universally would confuse simpler use cases.

**Decision**: Create Board, BoardMember, BoardMeeting as separate schemas. Relations link them to GovernanceBody/Meeting/Person/Membership for cross-domain queries if needed.

### Decision 2: ConflictOfInterest as mandatory per-agenda-item declaration

Dutch Corporate Governance Code (MCCG) and eIDAS regulation require boards to document conflict declarations. The system enforces that access to an agenda item's materials is blocked until a ConflictOfInterest record exists for the (board-member, agenda-item) pair.

**Alternative**: Store conflict declarations as annotations on Vote or Minutes. Rejected because conflicts must be declared BEFORE materials access, before voting, and in some cases before discussion — earlier in the timeline than the vote or minutes.

**Decision**: Create ConflictOfInterest as a first-class entity with board-member + agenda-item primary key. Access-control logic checks for its existence in the portal views.

### Decision 3: Vote anonymization via cryptographic unlinkability

Named votes preserve member identity and vote choice in the audit trail. Anonymous votes (for appointments, sensitive personnel) must record that each member voted (for quorum), but the vote choice must be unrecoverable per MCCG best practice.

**Implementation**: 
- Vote schema stores `anonymized: boolean`
- When `anonymized: true`, the board-member-koppeling is encrypted with a one-way hash (HMAC) keyed to the voting-round; the audit log records only the hash, not the member or choice
- The aggregate counts (votes-for, votes-against, votes-abstain) are recorded in the VotingRound
- No stored key allows reconstruction of the individual votes

**Alternative**: Delete member/vote pairs after vote closing. Rejected because audit trail must be immutable; deletions leave no evidence.

**Decision**: Use HMAC-based anonymization; member identity is hashed per voting-round and irretrievable.

### Decision 4: eIDAS QES integration via openconnector-e-sign

openconnector-e-sign abstracts QES providers (Signicat, ConnectiveTrust, eHerkenning, DigiD, Itsme) behind a standard API. docudesk-eidas provides the signature storage, PDF generation, and EU Trusted List verification.

**Decision**: Minutes signing is delegated to openconnector-e-sign; signatures are stored and verified via docudesk-eidas. The Minutes entity stores `eidas-signature-level` (SES, AdES, QES) and `pdf-archive-reference` (docudesk handle).

### Decision 5: Multilingual minutes with reconciliation

Boards of multinational corporations and cross-border holding companies operate in multiple languages. Minutes must be prepared in both Dutch and English (configurable per board), and both versions have equal legal standing. Discrepancies between language versions (e.g., different resolution count) are a compliance risk.

**Decision**: Minutes entity stores `language` field. A separate Minutes record is created for each language. A `reconciliation` check runs after preparation to alert the secretary if resolution counts diverge. Both versions are signed together (signature block applies to both).

### Decision 6: Access-level field on BoardMaterial enforces least-privilege

MaterialViewAuthorization is handled at the API layer via `access-level` enum on BoardMaterial: board-only, executive-only, audit-committee, external-auditor, regulator.

When a board member requests /api/board-materials/{id}, the backend:
1. Loads the BoardMaterial record
2. Checks the board-member's role (executive-member, non-executive-member, audit-committee-member, etc.)
3. If role matches access-level, allows view; otherwise 403 Forbidden
4. Logs the view attempt (allowed or denied) to the audit trail

**Decision**: Access-level is a data field, not an OpenRegister permission. The app's BrdMaterialController enforces authorization at view time.

### Decision 7: SHA-256 chained audit log for immutability

Every vote, conflict declaration, material access, and minutes signature is appended to an immutable log. Each entry includes:
- Timestamp (ISO 8601)
- Actor (board-member UID or system actor)
- Action (vote, conflict-declaration, material-access, signature)
- Object UIDs
- Previous entry's SHA-256 hash
- Current entry's SHA-256 hash

The hash is computed over: timestamp + actor + action + object-uids + previous-hash.

**Storage**: Audit log is stored in OpenRegister as a separate AuditLogEntry entity (not shown in user-facing reports but queryable for investigations).

**Immutability guarantee**: If a past entry is modified, its hash changes; all subsequent entries' hashes become invalid. An auditor comparing hashes can detect tampering.

**Decision**: Implement AuditLogService that appends all governance events; clients query the log for investigation.

### Decision 8: CalDAV-first BoardMeeting storage

Per ADR-002, meetings are stored in Nextcloud Calendar as VEVENTs. BoardMeeting adds board-specific X-DECIDESK-* properties:
- `X-DECIDESK-BOARD-UID`: Board reference
- `X-DECIDESK-LIFECYCLE`: Board-specific state (notice-sent, materials-distributed, minutes-signed, etc.)
- `X-DECIDESK-QUORUM-REQUIRED`: Integer quorum

BoardMeeting entity in OpenRegister stores only the CalDAV UID and relations to Resolution, ConflictOfInterest, BoardMaterial.

**Decision**: BoardMeeting is a thin wrapper; meeting-data lives in CalDAV. OpenRegister wrapper is used for relational queries (all resolutions for a meeting, all conflicts in a meeting).

## Risks / Trade-offs

- **Risk: Audit-log immutability is computationally expensive if queried frequently** → Mitigation: Audit log is indexed; queries are scoped to date ranges or specific actors. Archive old entries to cold storage after 7 years (retention period per MCCG).

- **Risk: HMAC-anonymized votes cannot be re-verified if the HMAC key is lost** → Mitigation: Store the HMAC key in a separate secure vault (Nextcloud Vault app or external HSM); document key-rotation policy.

- **Risk: Access-level enforcement at API layer does not prevent information leakage via search or list endpoints** → Mitigation: All board-material list queries include access-level filtering; full-text search on board-materials respects access-level.

- **Risk: eIDAS QES integration depends on external provider availability (Signicat, ConnectiveTrust)** → Mitigation: openconnector-e-sign abstracts the provider; boards can reconfigure provider without changing app code. A fallback to basic (non-QES) electronic signatures is possible but not recommended for listed entities.

- **Risk: Multilingual reconciliation check is manual; errors are not automatically corrected** → Mitigation: The reconciliation report flags discrepancies; secretary must resolve manually or reject the minutes for re-drafting.

- **Risk: Quorum calculation is complex (in-person + remote + valid proxies) and easy to get wrong** → Mitigation: Quorum verification is a separate micro-service; all meeting-open requests are validated by it before allowing votes to proceed.

## Migration Plan

### Phase 1: Schema Registration (Sprint T1)
1. Create `Board`, `BoardMember`, `BoardMeeting` schemas in `lib/Settings/decidesk_register.json` with board-specific fields and lifecycle enums
2. Create `Resolution`, `Vote`, `Minutes`, `ConflictOfInterest`, `BoardMaterial` schemas
3. Create `AuditLogEntry` schema (internal, not user-facing)
4. Add seed data: 3 boards (RvC, RvB, audit-committee types), 10 board members with mixed roles, 5 meetings, 10 resolutions, 25 votes, 5 minutes records, 8 conflict declarations, 20 board materials
5. Register `RepairStep` in `appinfo/info.xml`
6. Verify: Install on test instance; confirm all 8 schemas appear in OpenRegister admin UI; seed data loads; no duplicates on re-run

### Phase 2: Service Layer (Sprint T2)
1. Implement `AuditLogService` (append-only logging, SHA-256 chaining)
2. Implement `ConflictOfInterestService` (mandatory declaration check, action-tracking)
3. Implement `eIDASSignatureService` (integration with openconnector-e-sign, docudesk-eidas)
4. Implement `BoardMaterialAuthorizationService` (access-level checks)
5. Implement `MinutesReconciliationService` (language-version checking)
6. Implement `QuorumVerificationService` (attendance + proxy + remote calculation)
7. Create thin controller classes exposing these services via REST API
8. Add integration tests for each service

### Phase 3: Portal & Admin Views (Sprint T3+)
1. Board member portal: materials list, material detail with watermark, voting views, minutes signature interface
2. Admin views: board configuration, member registration, material access control, audit log export
3. Governance reporting dashboard: attendance statistics, independence ratios, conflict trends
4. Regulator access portal: time-bound read-only links, scoped visibility

### Rollback Strategy
- Delete the 8 new schemas via OpenRegister admin UI; no data-loss (no SQL tables created)
- Services remain inert (no routes registered, no calendar integration until explicitly enabled)

## Seed Data

### Board (3 objects)

**RvC (Supervisory Board) - Listed Company**
```json
{
  "@self": { "register": "decidesk", "schema": "Board", "slug": "rvc-acme-nv" },
  "name": "Raad van Commissarissen ACME N.V.",
  "type": "raad-van-commissarissen",
  "legal-entity": "ACME N.V. (KvK: 01234567)",
  "governance-model": "two-tier",
  "establishment-date": "2010-01-15T00:00:00Z",
  "statuten-reference": "ACME NV Statuten artikel 3.2",
  "chairman": "board-member-uuid-001",
  "vice-chairman": "board-member-uuid-002",
  "secretary": "company-secretary-uuid-001",
  "default-language": "nl",
  "additional-languages": ["en"],
  "quorum-rule": "majority"
}
```

**RvB (Executive Board) - Listed Company**
```json
{
  "@self": { "register": "decidesk", "schema": "Board", "slug": "rvb-acme-nv" },
  "name": "Raad van Bestuur ACME N.V.",
  "type": "raad-van-bestuur",
  "legal-entity": "ACME N.V. (KvK: 01234567)",
  "governance-model": "two-tier",
  "establishment-date": "2010-01-15T00:00:00Z",
  "statuten-reference": "ACME NV Statuten artikel 4.1",
  "chairman": "board-member-uuid-010",
  "vice-chairman": "board-member-uuid-011",
  "secretary": "company-secretary-uuid-002",
  "default-language": "nl",
  "additional-languages": ["en"],
  "quorum-rule": "majority"
}
```

**Audit Committee - Housing Corporation**
```json
{
  "@self": { "register": "decidesk", "schema": "Board", "slug": "audit-committee-woningbouw-noord" },
  "name": "Auditcommissie Woningbouw Noord",
  "type": "audit-committee",
  "legal-entity": "Woningbouw Noord U.A. (KvK: 98765432)",
  "governance-model": "one-tier",
  "establishment-date": "2015-06-01T00:00:00Z",
  "statuten-reference": "Woningbouw Noord Statuutartikel 2.3",
  "chairman": "board-member-uuid-020",
  "vice-chairman": null,
  "secretary": "company-secretary-uuid-003",
  "default-language": "nl",
  "additional-languages": [],
  "quorum-rule": "two-thirds"
}
```

### BoardMember (10 objects)

**Independent Non-Executive (RvC)**
```json
{
  "@self": { "register": "decidesk", "schema": "BoardMember", "slug": "bm-anna-bergstra" },
  "persoon-koppeling": "person-uuid-001",
  "board-koppeling": "board-uuid-001",
  "rol": "independent-member",
  "appointment-date": "2020-03-15T00:00:00Z",
  "appointment-resolution-reference": "R-2020-001",
  "term-end-date": "2024-03-14T23:59:59Z",
  "reappointment-eligible": true,
  "nationality": "NL",
  "nevenfuncties": ["Bestuurslid Stichting Kunst en Cultuur Rotterdam", "Adviseur EY"],
  "independence-status": "independent"
}
```

**Executive Member (RvB)**
```json
{
  "@self": { "register": "decidesk", "schema": "BoardMember", "slug": "bm-carlos-diaz" },
  "persoon-koppeling": "person-uuid-010",
  "board-koppeling": "board-uuid-010",
  "rol": "executive-member",
  "appointment-date": "2018-01-01T00:00:00Z",
  "appointment-resolution-reference": "R-2018-012",
  "term-end-date": "2026-12-31T23:59:59Z",
  "reappointment-eligible": true,
  "nationality": "ES",
  "nevenfuncties": [],
  "independence-status": "non-independent"
}
```

**Non-Executive Member (RvC)**
```json
{
  "@self": { "register": "decidesk", "schema": "BoardMember", "slug": "bm-maria-petrov" },
  "persoon-koppeling": "person-uuid-002",
  "board-koppeling": "board-uuid-001",
  "rol": "non-executive-member",
  "appointment-date": "2021-06-10T00:00:00Z",
  "appointment-resolution-reference": "R-2021-008",
  "term-end-date": "2025-06-09T23:59:59Z",
  "reappointment-eligible": true,
  "nationality": "RU",
  "nevenfuncties": ["CFO Gazprom Neft subsidiary in Amsterdam"],
  "independence-status": "non-independent"
}
```

**Audit Committee Member (Housing Corp)**
```json
{
  "@self": { "register": "decidesk", "schema": "BoardMember", "slug": "bm-johan-brouwer" },
  "persoon-koppeling": "person-uuid-020",
  "board-koppeling": "board-uuid-020",
  "rol": "member",
  "appointment-date": "2019-01-15T00:00:00Z",
  "appointment-resolution-reference": "R-2019-003",
  "term-end-date": "2027-01-14T23:59:59Z",
  "reappointment-eligible": true,
  "nationality": "NL",
  "nevenfuncties": ["Bestuurslid Maatschappij Belangen"],
  "independence-status": "independent"
}
```

(5 additional board members with mixed roles omitted for brevity)

### BoardMeeting (5 objects, CalDAV VEVENTs with OpenRegister wrappers)

**Regular RvC Meeting**
```json
{
  "@self": { "register": "decidesk", "schema": "BoardMeeting", "slug": "meeting-rvc-2026-05-28" },
  "board-koppeling": "board-uuid-001",
  "meeting-type": "regular",
  "meeting-date": "2026-05-28T14:00:00+02:00",
  "meeting-start": "2026-05-28T14:00:00+02:00",
  "meeting-end": "2026-05-28T16:30:00+02:00",
  "location": "Amsterdam, Boardroom 3e verdieping",
  "format": "in-person",
  "language": "nl",
  "status": "materials-distributed",
  "notice-sent-date": "2026-05-21T09:00:00Z",
  "materials-deadline": "2026-05-27T23:59:59Z",
  "quorum-required": 4,
  "quorum-achieved": true,
  "recording-allowed": false,
  "caldav-uid": "rvc-2026-05-28@acme.nl"
}
```

**Extraordinary RvB Meeting (Executive Session)**
```json
{
  "@self": { "register": "decidesk", "schema": "BoardMeeting", "slug": "meeting-rvb-2026-06-01" },
  "board-koppeling": "board-uuid-010",
  "meeting-type": "executive-session",
  "meeting-date": "2026-06-01T10:00:00+02:00",
  "meeting-start": "2026-06-01T10:00:00+02:00",
  "meeting-end": "2026-06-01T11:45:00+02:00",
  "location": "Remote (Teams link in notice)",
  "format": "remote",
  "language": "en",
  "status": "notice-sent",
  "notice-sent-date": "2026-05-30T08:00:00Z",
  "materials-deadline": "2026-05-31T23:59:59Z",
  "quorum-required": 3,
  "quorum-achieved": false,
  "recording-allowed": false,
  "caldav-uid": "rvb-exec-2026-06-01@acme.nl"
}
```

(3 additional meetings omitted for brevity)

### Resolution (10 objects)

**Approval Resolution**
```json
{
  "@self": { "register": "decidesk", "schema": "Resolution", "slug": "r-2026-001" },
  "meeting-koppeling": "meeting-uuid-001",
  "resolution-number": "R-2026-001",
  "title": "Approval of 2025 Annual Report and Financial Statements",
  "type": "approval",
  "proposing-member": "board-member-uuid-010",
  "full-text": "Resolved, that the Board approves the 2025 Annual Report and Financial Statements as presented...",
  "background": "The Finance Committee has reviewed the draft report and recommends approval...",
  "legal-basis": "ACME Statuten artikel 5.3 (approval rights)",
  "vote-type": "named",
  "vote-threshold": "simple-majority",
  "status": "adopted",
  "adoption-date": "2026-05-28T15:30:00+02:00",
  "effective-date": "2026-05-28T15:30:00+02:00"
}
```

**Appointment Resolution**
```json
{
  "@self": { "register": "decidesk", "schema": "Resolution", "slug": "r-2026-002" },
  "meeting-koppeling": "meeting-uuid-001",
  "resolution-number": "R-2026-002",
  "title": "Appointment of External Auditor (2026–2028)",
  "type": "appointment",
  "proposing-member": "board-member-uuid-001",
  "full-text": "Resolved, that the Board appoints Deloitte & Touche LLP as External Auditor for the period 2026–2028...",
  "background": "The Audit Committee has conducted a vendor selection process and recommends Deloitte...",
  "legal-basis": "EU Directive 2014/56 (audit requirements for listed entities)",
  "vote-type": "anonymous",
  "vote-threshold": "qualified-majority-two-thirds",
  "status": "adopted",
  "adoption-date": "2026-05-28T15:45:00+02:00",
  "effective-date": "2026-06-15T00:00:00+02:00"
}
```

(8 additional resolutions with varied types omitted for brevity)

### Vote (25 objects)

**Named Vote (Member votes in favor)**
```json
{
  "@self": { "register": "decidesk", "schema": "Vote", "slug": "vote-r2026-001-anna" },
  "resolution-koppeling": "resolution-uuid-001",
  "board-member-koppeling": "board-member-uuid-001",
  "vote": "in-favor",
  "vote-timestamp": "2026-05-28T15:28:00+02:00",
  "vote-method": "electronic",
  "proxy-holder": null,
  "anonymized": false
}
```

**Anonymous Vote (Anonymized via HMAC)**
```json
{
  "@self": { "register": "decidesk", "schema": "Vote", "slug": "vote-r2026-002-anon-1" },
  "resolution-koppeling": "resolution-uuid-002",
  "board-member-koppeling": "hmac-encrypted-uuid",
  "vote": "in-favor",
  "vote-timestamp": "2026-05-28T15:43:00+02:00",
  "vote-method": "electronic",
  "proxy-holder": null,
  "anonymized": true
}
```

**Proxy Vote**
```json
{
  "@self": { "register": "decidesk", "schema": "Vote", "slug": "vote-r2026-001-proxy-carlos" },
  "resolution-koppeling": "resolution-uuid-001",
  "board-member-koppeling": "board-member-uuid-010",
  "vote": "in-favor",
  "vote-timestamp": "2026-05-28T15:31:00+02:00",
  "vote-method": "electronic",
  "proxy-holder": "board-member-uuid-011",
  "anonymized": false
}
```

(22 additional votes with varied outcomes and methods omitted for brevity)

### ConflictOfInterest (8 objects)

**Material Financial Interest**
```json
{
  "@self": { "register": "decidesk", "schema": "ConflictOfInterest", "slug": "coi-maria-petrov-agenda-3" },
  "board-member-koppeling": "board-member-uuid-002",
  "agenda-item-koppeling": "agenda-item-uuid-003",
  "declaration-type": "financial-interest",
  "description": "Board member holds 2.3% of Gazprom shares; agenda item discusses supply contract with Gazprom subsidiary",
  "severity": "material",
  "action-taken": "recused-from-vote",
  "declaration-timestamp": "2026-05-28T14:05:00+02:00"
}
```

**Non-Material Disclosure**
```json
{
  "@self": { "register": "decidesk", "schema": "ConflictOfInterest", "slug": "coi-anna-bergstra-agenda-4" },
  "board-member-koppeling": "board-member-uuid-001",
  "agenda-item-koppeling": "agenda-item-uuid-004",
  "declaration-type": "personal-relationship",
  "description": "Board member is spouse of candidate for management position being discussed; relationship disclosed and participant recused from discussion",
  "severity": "material",
  "action-taken": "recused-from-discussion",
  "declaration-timestamp": "2026-05-28T14:08:00+02:00"
}
```

**No Conflict**
```json
{
  "@self": { "register": "decidesk", "schema": "ConflictOfInterest", "slug": "coi-johan-brouwer-agenda-2" },
  "board-member-koppeling": "board-member-uuid-020",
  "agenda-item-koppeling": "agenda-item-uuid-002",
  "declaration-type": "none",
  "description": null,
  "severity": null,
  "action-taken": "no-action-needed",
  "declaration-timestamp": "2026-05-28T14:02:00+02:00"
}
```

(5 additional conflict declarations omitted for brevity)

### Minutes (5 objects, one per meeting)

**RvC Minutes (Dutch, Draft)**
```json
{
  "@self": { "register": "decidesk", "schema": "Minutes", "slug": "minutes-rvc-2026-05-28-nl" },
  "meeting-koppeling": "meeting-uuid-001",
  "language": "nl",
  "version": "draft",
  "content": "<h1>Notulen Raad van Commissarissen ACME N.V.</h1><p>Vergadering van 28 mei 2026, 14:00–16:30 uur</p>...",
  "prepared-by": "company-secretary-uuid-001",
  "reviewed-by": "board-member-uuid-001",
  "signed-by": [],
  "signing-completion-date": null,
  "eidas-signature-level": null,
  "pdf-archive-reference": null,
  "hash-sha256": null
}
```

**RvC Minutes (Dutch, Signed with QES)**
```json
{
  "@self": { "register": "decidesk", "schema": "Minutes", "slug": "minutes-rvc-2026-05-28-nl-signed" },
  "meeting-koppeling": "meeting-uuid-001",
  "language": "nl",
  "version": "signed",
  "content": "<h1>Notulen Raad van Commissarissen ACME N.V.</h1>...",
  "prepared-by": "company-secretary-uuid-001",
  "reviewed-by": "board-member-uuid-001",
  "signed-by": [
    {
      "signer": "board-member-uuid-001",
      "signature-timestamp": "2026-05-29T11:15:00Z",
      "certificate-thumbprint": "7d3a8f4c2e9b1a6c5d0e3f7a2b4c6d8e"
    },
    {
      "signer": "company-secretary-uuid-001",
      "signature-timestamp": "2026-05-29T11:20:00Z",
      "certificate-thumbprint": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6"
    }
  ],
  "signing-completion-date": "2026-05-29T11:20:00Z",
  "eidas-signature-level": "QES",
  "pdf-archive-reference": "docudesk://archive/pdf/rvc-2026-05-28-nl-signed.pdf",
  "hash-sha256": "5a7f8c3b1d6e9a2f4c7b0e1a3d5f8c0b"
}
```

(3 additional minutes records omitted for brevity)

### BoardMaterial (20 objects)

**Confidential Board Material (Board-Only)**
```json
{
  "@self": { "register": "decidesk", "schema": "BoardMaterial", "slug": "material-rvc-2026-05-28-agenda" },
  "meeting-koppeling": "meeting-uuid-001",
  "agenda-item-koppeling": "agenda-item-uuid-001",
  "title": "Agenda RvC Meeting 28 May 2026",
  "document-reference": "docudesk://documents/rvc-2026-05-28-agenda.pdf",
  "access-level": "board-only",
  "distribution-timestamp": "2026-05-27T09:00:00Z",
  "watermarked": true
}
```

**Audit Committee Material (Audit-Committee Only)**
```json
{
  "@self": { "register": "decidesk", "schema": "BoardMaterial", "slug": "material-rvc-2026-05-28-audit-report" },
  "meeting-koppeling": "meeting-uuid-001",
  "agenda-item-koppeling": "agenda-item-uuid-006",
  "title": "Internal Audit Report 2025 Q4",
  "document-reference": "docudesk://documents/audit-report-2025-q4.pdf",
  "access-level": "audit-committee",
  "distribution-timestamp": "2026-05-27T10:00:00Z",
  "watermarked": true
}
```

**Executive-Only Material (Executive Board Only)**
```json
{
  "@self": { "register": "decidesk", "schema": "BoardMaterial", "slug": "material-rvb-2026-06-01-strategy" },
  "meeting-koppeling": "meeting-uuid-010",
  "agenda-item-koppeling": "agenda-item-uuid-010",
  "title": "Strategic Initiatives 2026–2028 (Confidential)",
  "document-reference": "docudesk://documents/strategy-2026-2028-confidential.pdf",
  "access-level": "executive-only",
  "distribution-timestamp": "2026-05-31T08:00:00Z",
  "watermarked": true
}
```

(17 additional board materials with varied access levels omitted for brevity)
