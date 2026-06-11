# Board portal — architecture overview

> Audience: developers / integrators extending the Decidesk board portal.
>
> Source spec: `openspec/changes/board-meeting-resolutions/`. Implementation
> shipped in PRs feature/decidesk-w2..w13 across Phases 1-10.
>
> This document supplements `docs/ARCHITECTURE.md`; that file describes
> the council/local-government surface (meeting / motion / voting-round /
> decision). The board-portal surface coexists with it under the same
> register (`decidesk`); naming uses the `Board*` prefix on schemas that
> would otherwise collide (`Vote` → `BoardVote`, `Minutes` → `BoardMinutes`,
> `Meeting` → `BoardMeeting`).

## 1. Layered architecture

```
┌──────────────────────────────────────────────────────────────────┐
│ Vue 3 / CnAppRoot manifest shell (Phase 8)                       │
│   src/views/Board*.vue, ResolutionList.vue, ResolutionDetail.vue │
│   src/modals/BoardCreate*.vue, BoardMeetingCreate*.vue           │
│   src/manifest.d/board-portal.json + src/registry.js (ADR-036)   │
└──────────────────────────────────────────────────────────────────┘
                            │ JSON over HTTP
                            ▼
┌──────────────────────────────────────────────────────────────────┐
│ Controllers — appinfo/routes.php (Phase 3)                       │
│  BoardController          Resolution / BoardVoteController        │
│  BoardMemberController    BoardMaterialController                 │
│  BoardMeetingController   ConflictOfInterestController            │
│  AuditLogController       EIDASSignatureController                │
│  ProxyVoteController      GovernanceReportController              │
│  RegulatorExportController                                        │
│  MultilingualReconciliationController                             │
└──────────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌──────────────────────────────────────────────────────────────────┐
│ Services — lib/Service/ (Phase 2, 5, 6)                          │
│  BoardService             ResolutionService                       │
│  BoardMemberService       BoardVoteService                        │
│  BoardMeetingService      ResolutionLifecycleGuard                │
│  QuorumVerificationService ConflictOfInterestService              │
│  BoardMaterialAuthorizationService                                │
│  AuditLogService (hash-chained, append-only)                      │
│  EIDASSignatureService    ProxyVoteService                        │
│  GovernanceReportService  RegulatorExportService                  │
│  MultilingualReconciliationService + ITranslationAdapter          │
│  BoardCalDavSyncService (Phase 7)                                 │
└──────────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌──────────────────────────────────────────────────────────────────┐
│ OpenRegister object API (ADR-022)                                │
│  oc_openregister_table_decidesk_board                            │
│  oc_openregister_table_decidesk_board_member                     │
│  oc_openregister_table_decidesk_board_meeting                    │
│  oc_openregister_table_decidesk_resolution                       │
│  oc_openregister_table_decidesk_board_vote                       │
│  oc_openregister_table_decidesk_board_minutes                    │
│  oc_openregister_table_decidesk_conflict_of_interest             │
│  oc_openregister_table_decidesk_board_material                   │
│  oc_openregister_table_decidesk_board_audit_log_entry            │
└──────────────────────────────────────────────────────────────────┘
```

## 2. Data model — nine schemas

Phase 1 of the spec registers these schemas atomically via
`lib/Settings/decidesk_register.json` + `lib/Repair/InitializeSettings.php`.

| Schema | Purpose | Key fields |
|---|---|---|
| `board` | Bestuur (RvC, RvB, committees) | name, type, governanceModel, quorumRule, quorumThreshold, minimumNoticeHours |
| `board-member` | Lidmaatschap | personKoppeling, rol, appointmentDate, termEndDate, independenceStatus |
| `board-meeting` | Vergadering | boardKoppeling, meetingDate, meetingType, format, language, status, noticeSentDate, caldavIcsBlob |
| `resolution` | Besluit | meetingKoppeling, title, type, voteThreshold, voteType, status |
| `board-vote` | Individuele stem | resolutionKoppeling, boardMemberKoppeling, vote, voteMethod, voteTimestamp, anonymized, proxyHolder |
| `board-minutes` | Notulen | meetingKoppeling, language, sourceMinutesKoppeling, signedAt, qesAttestation |
| `conflict-of-interest` | Belangenconflict | boardMemberKoppeling, type, status, scope, declaredAt, resolvedAt |
| `board-material` | Bestuursdocument | boardKoppeling, accessLevel, contentRef, watermarkRequired |
| `board-audit-log-entry` | Auditlog | actor, action, objectUids[], payload, prevHash, hash, recordedAt |

OpenRegister generates the underlying tables on schema activation
(`oc_openregister_table_decidesk_<schema>`); the controllers and
services never touch SQL directly.

## 3. HTTP surface (Phase 3)

Routes live in `appinfo/routes.php` under
`board-meeting-resolutions` spec markers. Every route is wired to a
controller method via NC's standard `IRouteRegister` syntax.

| Family | Route | Method | Auth |
|---|---|---|---|
| Board | `/api/boards` | GET/POST | user |
| Board | `/api/boards/{id}` | GET/PUT | user |
| BoardMember | `/api/boards/{boardId}/members` | GET/POST | user |
| BoardMember | `/api/board-members/{id}` | DELETE | user |
| BoardMember | `/api/board-members/{id}/role` | PUT | user |
| BoardMeeting | `/api/boards/{boardId}/meetings` | POST | user |
| BoardMeeting | `/api/board-meetings/{id}/send-notice` | POST | user |
| BoardMeeting | `/api/board-meetings/{id}/lifecycle` | POST | user |
| Resolution | `/api/board-meetings/{meetingId}/resolutions` | POST | user |
| Resolution | `/api/resolutions/{id}` | PUT | user |
| Resolution | `/api/resolutions/{id}/open-vote` | POST | user |
| Resolution | `/api/resolutions/{id}/conclude` | POST | user |
| BoardVote | `/api/resolutions/{resolutionId}/votes` | POST | user |
| BoardVote | `/api/resolutions/{resolutionId}/tally` | GET | user |
| BoardVote | `/api/resolutions/{resolutionId}/audit` | GET | user |
| BoardMaterial | `/api/boards/{boardId}/materials` | GET | user |
| BoardMaterial | `/api/board-materials/{id}[/download]` | GET/POST | user |
| Conflict | `/api/conflicts` + `/api/conflicts/{id}/action` | POST/PUT | user |
| AuditLog | `/api/audit-log[/{id}/verify\|/export]` | GET | admin |
| eIDAS | `/api/minutes/{minutesId}/eidas/*` | POST | user (signer cert) |
| ProxyVote | `/api/proxies[/{id}/suspend\|/{id}]` | POST/GET/PUT/DELETE | user |
| GovernanceReport | `/api/governance-reports[/{id}[/export/{fmt}]]` | POST/GET | user |
| RegulatorExport | `/api/regulator-exports[/{id}]` | POST/GET | admin |
| Multilingual | `/api/multilingual/queue[/process]` | POST/GET | admin |

Auth model: every controller method carries `#[NoAdminRequired]` (NC's
`SecurityMiddleware` would otherwise reject the request as admin-only by
default). The admin gate is enforced **inside** the
`requireAdmin()` helper in `RegulatorExportController` /
`MultilingualReconciliationController` / `AuditLogController` so
anonymous callers get `401`, non-admin callers get `403`. Per-object
read/write authority is delegated to `ObjectService` (ADR-022).

## 4. Lifecycle state machines

### 4.1 BoardMeeting

```
scheduled
   │ send-notice
   ▼
notice-sent
   │ distribute-materials
   ▼
materials-distributed
   │ open
   ▼
in-session
   │ adjourn      close (skip adjourn)
   ▼              │
adjourned ────────┤
                  ▼
              closed
                  │ sign-minutes
                  ▼
            minutes-signed
```

Transitions are encoded in `BoardMeetingService::TRANSITIONS`. An
illegal action returns `422` with a static
"lifecycle transition not allowed from {status}" message; never `500`.

### 4.2 Resolution

```
proposed ──amend──► proposed
   │ openVote (quorum-guarded)
   ▼
under-discussion
   │ conclude (threshold-evaluated)
   ▼
adopted   rejected
```

`ResolutionLifecycleGuard` composes `QuorumVerificationService` +
`ConflictOfInterestService` — both must clear for `openVote`. The
conclude step counts every linked `board-vote` row, applies the
`voteThreshold`, and persists the outcome plus a tally on the
resolution.

## 5. Audit trail — hash chain

`AuditLogService::append()` writes one `board-audit-log-entry` row per
mutating action:

```
payload     = JSON.canonical({ actor, action, objectUids, payload, recordedAt })
prevHash    = (SELECT hash FROM board_audit_log_entry ORDER BY recordedAt DESC LIMIT 1) ?? ""
hash        = sha256(prevHash || payload)
```

Verification (`AuditLogController::verify`) recomputes the hash for the
target row and compares to the stored value. Any tampering with row N
invalidates every subsequent row, because their `prevHash` chains back
to N.

This is the same mechanic used by openconnector's call log; the
implementation here is in pure PHP (no external libs).

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
the QTSP is done by `openconnector` so decidesk never holds a private
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

## 10. Frontend (Phase 8 — Vue + manifest shell)

- Six views under `src/views/`: `BoardList`, `BoardDetail`,
  `BoardMeetingList`, `BoardMeetingDetail`, `ResolutionList`,
  `ResolutionDetail`.
- Two ADR-004-isolated modals under `src/modals/`:
  `BoardCreateModal.vue` (NcDialog) and `BoardMeetingCreateModal.vue`
  (NcDialog).
- Manifest fragment `src/manifest.d/board-portal.json` adds six pages
  + three primary-nav entries (Boards / Board Meetings / Resolutions);
  the fragment is merged into `src/manifest.json` by
  `main.js::mergeManifestFragments`.
- Custom components are registered in `src/registry.js` as ADR-036
  `page()` entries (kind-tagged registry).

## 11. Testing matrix

| Layer | Suite | Spec coverage |
|---|---|---|
| Unit | `tests/Unit/Service/*` PHPUnit | 9.1, 9.6, 9.7, 9.8, 9.9, 9.10 |
| Unit | `tests/Unit/Listener/BoardMeetingCalDavBridgeTest.php` | 9.5 |
| Unit | `tests/Unit/Service/BoardCalDavSyncServiceTest.php` | 9.5 |
| Vitest | `tests/vitest/**` | UI behaviour |
| Newman API | `tests/integration/board-portal.postman_collection.json` | 9.3 |
| Newman aggregate | `tests/newman/run-all.sh` | runs both decidesk + board-portal collections |
| Playwright e2e | `tests/e2e/**` | UI happy paths |

`composer test:unit:strict` + `tests/newman/run-all.sh` together cover
the contract; CI gates on both.

## 12. References

- Spec: `openspec/changes/board-meeting-resolutions/proposal.md` and
  `openspec/changes/board-meeting-resolutions/tasks.md`.
- Hydra ADRs: ADR-022 (apps consume OR), ADR-031 (notification dialect),
  ADR-034 (MCP tool surface), ADR-036 (kind-tagged registry).
- User guide: [Board portal feature](../Features/board-portal.md).
- Admin runbook: [Board portal admin](../admin/board-portal-admin.md).
