# Corporate governance — architecture overview

> **Retirement notice (ADR-006, change `retire-board-portal`, Cycle-1 refactor 2026-06-14):**
> The standalone board portal with `Board*`-prefixed schemas has been **retired**. The seven
> schemas (Board, BoardMember, BoardMeeting, BoardVote, BoardMinutes, BoardMaterial,
> BoardAuditLogEntry), the `board-portal.json` manifest fragment, six Board/Resolution Vue views,
> and the dedicated board CRUD controllers/services no longer exist in the codebase.
>
> Corporate governance is now served by **mode-adaptation** (`organisatie_modus=corp`) of the
> universal Decidiq entities. See `docs/ARCHITECTURE.md` section 3.3b for the entity mapping table.
> This document is retained as the technical reference for the **corporate governance experience**
> delivered through the universal architecture.
>
> Audience: developers / integrators working on the corporate governance mode of Decidiq.
>
> Source spec: `openspec/changes/board-meeting-resolutions/` (archived), superseded by
> ADR-005 (Decision supertype) and ADR-006 (mode adaptation).

## 1. Layered architecture (post-ADR-006, unified)

Corporate governance uses the same layered architecture as all other Decidiq
domains. There are no `Board*`-prefixed layers. The mode-specific behaviour is
injected at render time via `organisatie_modus=corp`.

```
┌──────────────────────────────────────────────────────────────────┐
│ Vue 3 / CnAppRoot manifest shell                                 │
│   6-item mode-aware nav (ADR-004 IA + C7 ia-six-item-nav)        │
│   src/config/modeLabels.js — "Bodies" → "Board", etc.           │
│   Universal views: DecisionList, DecisionDetail, BodyDetail, ... │
└──────────────────────────────────────────────────────────────────┘
                            │ JSON over HTTP
                            ▼
┌──────────────────────────────────────────────────────────────────┐
│ Universal controllers — appinfo/routes.php                       │
│  Retained for corporate features (retargeted to universal ents): │
│  ConflictOfInterestController  EIDASSignatureController          │
│  ProxyVoteController           GovernanceReportController        │
│  RegulatorExportController     MultilingualReconciliationController│
│  AuditLogController                                              │
└──────────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌──────────────────────────────────────────────────────────────────┐
│ Services — lib/Service/ (retargeted to universal entities)       │
│  ConflictOfInterestService    EIDASSignatureService              │
│  ProxyVoteService             GovernanceReportService            │
│  RegulatorExportService       MultilingualReconciliationService  │
│  ITranslationAdapter          CalDavSyncService                  │
│  QuorumVerificationService                                       │
└──────────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌──────────────────────────────────────────────────────────────────┐
│ OpenRegister object API (ADR-022) — universal schemas            │
│  oc_openregister_table_decidesk_governance_body                  │
│  oc_openregister_table_decidesk_person                           │
│  oc_openregister_table_decidesk_membership                       │
│  oc_openregister_table_decidesk_post                             │
│  oc_openregister_table_decidesk_meeting                          │
│  oc_openregister_table_decidesk_decision  (incl. type=resolution)│
│  oc_openregister_table_decidesk_vote                             │
│  oc_openregister_table_decidesk_minutes                          │
│  oc_openregister_table_decidesk_digital_document                 │
└──────────────────────────────────────────────────────────────────┘
```

## 2. Corporate entity mapping (post-ADR-006)

The former board-* schemas are now expressed as universal entities with
mode-specific field values. All schemas are registered in
`lib/Settings/decidesk_register.json`.

| Corporate concept | Universal entity | Key distinguishing fields |
|---|---|---|
| Board (RvC) | `GovernanceBody` | `bodyType=supervisory-board` |
| Board (RvB) | `GovernanceBody` | `bodyType=executive-board` |
| Board committee | `GovernanceBody` | `bodyType=committee` + parent GovernanceBody ref |
| Board member | `Person` + `Membership` | `Membership.role` + `independenceStatus` extension |
| Board meeting | `Meeting` | linked GovernanceBody with corp bodyType |
| Resolution | `Decision` | `decisionType=resolution` |
| Board vote | `Vote` | linked to Decision with decisionType=resolution |
| Board minutes | `Minutes` | linked to corp Meeting |
| Board material | `DigitalDocument` | access-level via OpenRegister RBAC |
| Conflict of interest | Standalone entity (retained) | linked to Membership |
| Audit log | OR built-in `auditTrail` | per-object, no separate schema needed |

## 3. HTTP surface (corporate governance features)

The `Board*`-prefixed routes (`/api/boards`, `/api/board-meetings`, etc.) were
removed in C3 retire-board-portal. Corporate governance entities are now
accessed via the standard OpenRegister object API
(`/api/objects/decidiq/{schema}/{id}`).

The following **corporate-specific** feature controllers are retained, their
routes updated to reference universal entity IDs:

| Family | Route | Method | Auth |
|---|---|---|---|
| Conflict of interest | `/api/conflicts` + `/api/conflicts/{id}/action` | POST/PUT | user |
| eIDAS signatures | `/api/minutes/{minutesId}/eidas/*` | POST | user (signer cert) |
| Proxy vote | `/api/proxies[/{id}/suspend\|/{id}]` | POST/GET/PUT/DELETE | user |
| Governance report | `/api/governance-reports[/{id}[/export/{fmt}]]` | POST/GET | user |
| Regulator export | `/api/regulator-exports[/{id}]` | POST/GET | admin |
| Multilingual | `/api/multilingual/queue[/process]` | POST/GET | admin |
| Audit log verify | `/api/audit-log[/{id}/verify\|/export]` | GET | admin |

Auth model: every controller method carries `#[NoAdminRequired]`; the admin
gate is enforced inside `requireAdmin()` helpers in `RegulatorExportController` /
`MultilingualReconciliationController` / `AuditLogController`. Per-object
read/write authority is delegated to `ObjectService` (ADR-022).

## 4. Lifecycle state machines (corporate mode)

Corporate governance uses the same universal Symfony Workflow state machines
configured in `ProcessTemplate` objects. Corporate bodies typically configure
a governance-specific template.

### 4.1 Meeting lifecycle (corp — board meeting)

```
draft
   │ convene (send-notice)
   ▼
convened
   │ distribute-materials
   ▼
convened (materials distributed — tracked via boolean field)
   │ open
   ▼
in_progress
   │ adjourn      complete (skip adjourn)
   ▼              │
adjourned ────────┤
                  ▼
              completed
                  │ approve-minutes
                  ▼
            minutes_approved
```

This maps to the universal Meeting lifecycle (see `docs/ARCHITECTURE.md` §3.2).
An illegal transition returns a lifecycle guard error.

### 4.2 Decision lifecycle (resolution — decisionType=resolution)

```
draft ──amend──► draft
   │ submit
   ▼
submitted
   │ agenda (quorum pre-check available)
   ▼
agenda
   │ debate
   ▼
debated
   │ vote (quorum-guarded + ConflictOfInterestService)
   ▼
voted
   │ conclude (threshold against requiredMajority)
   ▼
approved   rejected
```

`QuorumVerificationService` and `ConflictOfInterestService` both run as
guards before the `vote` transition. The conclude step counts all linked
`Vote` objects, evaluates against `requiredMajority`, and persists the
outcome as `result` on the Decision.

## 5. Audit trail

Corporate governance audit logging uses the **OpenRegister built-in `auditTrail`**
field, available on every object. The former standalone `board-audit-log-entry`
schema (hash-chained, append-only) was retired with C3 retire-board-portal.

For regulatory export requirements that need an independent, verifiable hash
chain, `AuditLogService` (retained) can produce a tamper-evident export from
the OR audit trail using the same `sha256(prevHash || payload)` mechanic:

```
payload     = JSON.canonical({ actor, action, objectUids, payload, recordedAt })
prevHash    = hash of previous export row
hash        = sha256(prevHash || payload)
```

`AuditLogController::verify` recomputes hashes over the exported set.
Any tampering with row N invalidates every subsequent row.

## 6. eIDAS qualified signatures

Phase 4 wires the
`EIDASSignatureService` to openconnector's e-sign abstraction:

```
EIDASSignatureService::initiate(minutesId)
   ↓ openconnector e-sign source (Connective / Itsme / DigiD)
   ↓ QTSP response (poll via verify)
EIDASSignatureService::finalize(minutesId, attestationBundle)
   ↓ ObjectService::saveObject(board-minutes, { qesAttestation: ... })
   ↓ AuditLogService::append({ action: 'qes-signed', ... })
```

Certificate validation goes through the EU Trusted List (LOTL); when
LOTL fetch fails, the controller returns `503` with a
`Trusted List unavailable` body. Per ADR-031, the actual handshake with
the QTSP is done by `openconnector` so decidiq never holds a private
key.

## 7. CalDAV bridge {#caldav}

`BoardMeetingCalDavBridge` (subscribes to
`OCA\OpenRegister\Event\ObjectCreatedEvent` /
`ObjectUpdatedEvent`) forwards `board-meeting` rows to
`BoardCalDavSyncService::sync()`. The sync builds an RFC-5545 VEVENT
with these X-properties:

- `X-DECIDESK-MEETING-ID` — UUID of the board-meeting row.
- `X-DECIDESK-LIFECYCLE` — current status.
- `X-DECIDESK-BOARD` — board UUID.
- `X-DECIDESK-FORMAT` — `in-person` / `remote` / `hybrid`.
- `X-DECIDESK-LANGUAGE` — meeting language.

The VEVENT is written through
`OCP\Calendar\ICreateFromString::createFromString()` into the chairman's
first writable calendar. If no calendar is available the ICS blob is
stored on the row's `caldavIcsBlob` field so nothing is lost.

`readMeetingData` parses a stored VEVENT back into the canonical OR
field map, enabling round-trip safety.

## 8. Multilingual reconciliation

`MultilingualReconciliationService` writes one `board-minutes` row per
target locale, links it to the source via `sourceMinutesKoppeling`, and
queues a translation job through the registered `ITranslationAdapter`.

The default `LogTranslationAdapter` is dormant (records each call to
the log but doesn't touch the row's body). Production deployments
register a real adapter by overriding the binding in their bespoke
`Application::register()` (e.g. an openconnector LLM-translation
adapter).

The hourly `TranslationQueueJob` calls `processQueue($maxEntries=10)`,
which steps each entry through `queued` → `processing` → `complete` /
`failed`. The `status()` endpoint surfaces these counts on the
secretary dashboard.

## 9. Regulator export

`RegulatorExportService::generate(boardId, scope, format, actor)`:

- `scope` ∈ `resolutions` / `minutes` / `audit-log`.
- `format` ∈ `pdf` / `csv`.
- Persists a `regulator-export` row with `sha256(body)`, `processedAtMs`
  and the actor.
- Mirrors the export to the audit log via `AuditLogService::append`.

The default `pdf` is a self-contained PDF-1.4 renderer (no external
libs). When `decidesk:export_format_provider` is set to `docudesk` the
service hands off to docudesk for a richer (watermarked, headered)
layout.

## 10. Frontend (post-ADR-006)

The six `Board*.vue` views, two `BoardCreate*.vue` modals, and the
`src/manifest.d/board-portal.json` fragment were removed in C3 retire-board-portal.

Corporate governance is rendered by the **universal views** with mode-driven
label adaptation:

- `BodyList.vue` / `BodyDetail.vue` — renders as "Board" in corp mode.
- `DecisionList.vue` / `DecisionDetail.vue` — renders as "Resolutions" in corp mode;
  filtered by `decisionType=resolution` by default.
- `MeetingList.vue` / `MeetingDetail.vue` — universal; filtered to corp bodies.
- `modeLabels.js` (`src/config/`) — maps `organisatie_modus` to display strings for
  nav entries, column headers, and action labels.
- `src/manifest.json` — 6-item ADR-004 IA (C7 ia-six-item-nav); no board-portal fragment.
- Custom components are registered in `src/registry.js` as ADR-036 `page()` entries.

## 11. Testing matrix (post-ADR-006)

The `board-portal.postman_collection.json` collection and `BoardMeeting*`
unit tests were removed with the retired schemas. Corporate governance
scenarios are covered by the universal test suites:

| Layer | Suite | Coverage |
|---|---|---|
| Unit | `tests/Unit/Service/ConflictOfInterestServiceTest.php` | conflict gate |
| Unit | `tests/Unit/Service/EIDASSignatureServiceTest.php` | eIDAS flow |
| Unit | `tests/Unit/Service/ProxyVoteServiceTest.php` | proxy delegation |
| Unit | `tests/Unit/Service/GovernanceReportServiceTest.php` | corp reporting |
| Unit | `tests/Unit/Service/RegulatorExportServiceTest.php` | regulator export |
| Unit | `tests/Unit/Service/CalDavSyncServiceTest.php` | CalDAV bridge |
| Vitest | `tests/vitest/**` | UI behaviour incl. corp mode labels |
| Newman API | `tests/integration/decidiq.postman_collection.json` | universal + corp scenarios |
| Playwright e2e | `tests/e2e/**` | UI happy paths incl. resolution flow |

`composer test:unit:strict` + `tests/newman/run-all.sh` cover the contract; CI gates on both.

## 12. References

- ADR-005: `openspec/architecture/adr-005-decision-as-universal-supertype.md` — Decision supertype + decisionType discriminator.
- ADR-006: `openspec/architecture/adr-006-mode-adaptation-over-parallel-entities.md` — mode adaptation as the replacement for parallel entities.
- Retired spec (archived): `openspec/changes/board-meeting-resolutions/`.
- Hydra ADRs: ADR-022 (apps consume OR), ADR-031 (notification dialect), ADR-034 (MCP tool surface), ADR-036 (kind-tagged registry).
- User guide: [Corporate governance feature](../Features/board-portal.md).
- Architecture overview: [docs/ARCHITECTURE.md §3.3b](../ARCHITECTURE.md) — entity mapping table.
- Admin runbook: [Board portal admin](../admin/board-portal-admin.md).
