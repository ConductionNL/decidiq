# Design: retire-board-portal

## Architecture Overview

Decidesk is a thin client over OpenRegister (ADR-022): the app owns no database
tables; entities live as JSON objects validated by schemas declared in
`lib/Settings/decidesk_register.json`. The "board portal" (Phase 8, archived
change `2026-06-12-board-meeting-resolutions`) added a **parallel** corporate
entity set on top of the universal entities, plus its own Vue views and nav.

This change collapses that parallel set back onto the universal entities per
ADR-006. There is no new runtime architecture — only deletion of the parallel
overlay and re-expression of the corporate scenario as `mode=corp` seeds on the
universal schemas. The result is the ADR-006 invariant: **one schema per
concept**, audience differences via label adaptation / type discriminators /
progressive disclosure.

### ADR-006 remap table (governing decision)

| Was (parallel schema/view) | Becomes (universal entity) | Status |
|---|---|---|
| `Board` / `BoardList`,`BoardDetail` | `governance-body` + `bodyType` (corporate), `mode=corp` labels | re-seed here |
| `BoardMeeting` / `BoardMeetingList`,`BoardMeetingDetail` | `meeting` (CalDAV VEVENT, ADR-002), `mode=corp` labels | re-seed here |
| `BoardMember` | Person + Membership (Popolo, ADR-001) | **done in C2** (`popolo-decision-makers`) — confirm seeds only |
| `BoardVote` | `vote` / `voting-round` | covered by universal voting |
| `BoardMinutes` | `minutes`, `mode=corp` labels | re-seed here |
| `Resolution` / `ResolutionList`,`ResolutionDetail` | `decision` with `decisionType=resolution` (ADR-005) | **done in C1** (`unify-decision-supertype`) |
| `BoardMaterial` | DigitalDocument attachment (generic) | retire schema + routes |
| `BoardAuditLogEntry` | OR built-in `auditTrail` | retire schema |
| eIDAS signing | a **decision method** (`signature`) on the unified Decision | **deferred to Cycle 2** (`decision-methods`) — note only |

## Mixed-spec rationale (ADR-032)

This change is mixed: it deletes **config** (7 schemas + their seeds in
`decidesk_register.json`, the `board-portal.json` manifest fragment) and
**code** (six Vue views, `registry.js` registrations, board routes, board
controllers/services/lifecycle/listener, DI registrations, search/dashboard
references). Per the C1 (`unify-decision-supertype`) and C2
(`popolo-decision-makers`) supervised-local precedent in this cycle, it is
delivered as **one** change with `kind: code`, because the config deletions are
meaningless without the simultaneous code cleanup — a half-applied state (schema
deleted but routes/views still referencing it) breaks the app at runtime.
Splitting would create an unbuildable intermediate.

## Deletion safety (no orphaned data)

The two board concepts that carry real demo/production data were migrated to the
universal entities in earlier cycles of this same branch **before** this
deletion runs:

- **Resolutions → Decisions** — C1 `unify-decision-supertype` stores resolutions
  as `decision` objects with `decisionType=resolution` (ADR-005). The
  `Decision` schema already holds 17 seeds; removing the `Resolution` schema
  orphans nothing.
- **Board members → Person + Membership** — C2 `popolo-decision-makers` stores
  corporate board members as `Person` (5 seeds) + `Membership` (6 seeds) objects
  (Popolo, ADR-001). Removing `BoardMember` orphans nothing.

The remaining deleted schemas (`Board`, `BoardMeeting`, `BoardVote`,
`BoardMinutes`, `BoardMaterial`, `BoardAuditLogEntry`) hold only **seed/demo**
data, which is re-provided as `mode=corp` seeds on the universal entities (see
Seed Data). No `lib/Migration/*` class runs — OpenRegister object data is not
touched by this change; only schema declarations and code are.

## Reference cleanup (authoritative inventory)

Every reference to a deleted schema/view/route. The apply step works this list
file-by-file; **delete** = remove outright, **retarget** = point at the unified
entity, **flag** = apply decides per-file (board-only ⇒ delete, board-coupled
but reusable ⇒ retarget onto `meeting`/`decision`/`minutes`).

### Config — register (`lib/Settings/decidesk_register.json`)

- **DELETE** `components.schemas`: `Board`, `BoardMember`, `BoardMeeting`,
  `BoardVote`, `BoardMinutes`, `BoardMaterial`, `BoardAuditLogEntry` (each
  deletion also removes that schema's `x-openregister-seeds`: Board 3,
  BoardMember 10, BoardMeeting 5, BoardVote 25, BoardMinutes 5, BoardMaterial 8,
  BoardAuditLogEntry 0).

### Config — manifest + views + registry

- **DELETE** `src/manifest.d/board-portal.json` (nav items BoardDashboard /
  Boards / BoardMeetings / Resolutions + the 7 board pages; its dashboard
  widgets bind `schema: boardMeeting`, `schema: resolution`, `schema:
  boardMember` — all deleted slugs).
- **DELETE** views: `src/views/BoardList.vue`, `BoardDetail.vue`,
  `BoardMeetingList.vue`, `BoardMeetingDetail.vue`, `ResolutionList.vue`,
  `ResolutionDetail.vue`.
- **DELETE** orphaned modals (only imported by the deleted views):
  `src/modals/BoardCreateModal.vue` (imported by `BoardList.vue`),
  `src/modals/BoardMeetingCreateModal.vue` (imported by `BoardDetail.vue`).
- **EDIT** `src/registry.js`: remove the 6 `import` lines (lines ~30-37) and the
  6 `page(...)` registrations + the board-portal comment block (lines ~146-156).

### Code — routes (`appinfo/routes.php`)

- **DELETE** the whole "Board portal" block (lines ~128-198): `board#*` (4),
  `boardMember#*` (4), `boardMeeting#*` (3), `resolution#*` (4), `boardVote#*`
  (3), `boardMaterial#*` (3), `conflictOfInterest#*` (3 — uses
  `/api/board-members/{id}/conflicts`), `auditLog#*` (3), `eIDASSignature#*`
  (4), `proxyVote#*` (4), `governanceReport#*` (4), `regulatorExport#*` (3),
  `multilingualReconciliation#*` (3). For the board-coupled-but-reusable ones
  (conflictOfInterest / auditLog / eIDASSignature / proxyVote /
  governanceReport / regulatorExport / multilingualReconciliation) the apply
  step **flags** whether to retarget the route paths onto unified entities
  (`/api/meetings/...`, `/api/decisions/...`, `/api/minutes/...`) rather than
  delete; board-prefixed paths (`/api/boards`, `/api/board-meetings`,
  `/api/board-materials`, `/api/board-members`) are deleted.

### Code — DI registrations + listener (`lib/AppInfo/Application.php`)

- **DELETE** the "Board portal Phase 2/3 services/controllers" DI blocks
  (~lines 559-700+): registrations for `BoardMaterialAuthorizationService`,
  `BoardService`, `BoardMemberService`, `BoardMeetingService`,
  `ResolutionLifecycleGuard`, `ResolutionService`, `BoardVoteService`,
  `BoardController`, `BoardMemberController`, `BoardMeetingController`, and the
  matching resolution/board-vote/board-material controllers.
- **DELETE** the `BoardMeetingCalDavBridge` import (line 38) + its DI
  registration + `registerEventListener` (~lines 1167-1205). Board-meeting
  CalDAV sync is subsumed by the universal `meeting` CalDAV path (ADR-002).
- **FLAG** DI for board-coupled services (`EIDASSignatureService` +
  `IEIDASSignatureService`/`LogEIDASSignatureService`, `ConflictOfInterestService`,
  `AuditLogService`, `ProxyVoteService`, `GovernanceReportingService`,
  `RegulatorExportService`, `MultilingualReconciliationService`,
  `BoardCalDavSyncService`, `WrittenResolutionService`, `QesGuard`) — apply
  decides retarget-vs-remove per file.

### Code — controllers (`lib/Controller/`)

- **DELETE** (board-only): `BoardController.php`, `BoardMemberController.php`,
  `BoardMeetingController.php`, `BoardVoteController.php`,
  `BoardMaterialController.php`, `ResolutionController.php`,
  `BoardPortalControllerTrait.php`.
- **FLAG** (board-coupled, reusable): `AuditLogController.php`,
  `ConflictOfInterestController.php`, `EIDASSignatureController.php`,
  `GovernanceReportController.php`, `RegulatorExportController.php`,
  `ProxyVoteController.php`, `MultilingualReconciliationController.php` — retarget
  onto unified entities or remove; apply decides per file.

### Code — services / lifecycle (`lib/Service/`, `lib/Lifecycle/`)

- **DELETE** (board-only): `BoardService.php`, `BoardMemberService.php`,
  `BoardMeetingService.php`, `BoardVoteService.php`, `BoardMaterialAuthorizationService.php`,
  `BoardCalDavSyncService.php`, `ResolutionService.php`,
  `WrittenResolutionService.php`, `lib/Lifecycle/ResolutionLifecycleGuard.php`.
- **FLAG** (board-coupled, reference deleted slugs `board-meeting`/`resolution`/
  `boardMember`): `EIDASSignatureService.php`, `IEIDASSignatureService.php`,
  `LogEIDASSignatureService.php`, `ConflictOfInterestService.php`,
  `AuditLogService.php`, `ProxyVoteService.php`, `GovernanceReportingService.php`,
  `RegulatorExportService.php`, `MultilingualReconciliationService.php`,
  `lib/Lifecycle/QesGuard.php` — each must be retargeted onto `meeting`/
  `decision`/`minutes` or removed; apply decides per file. (eIDAS specifically
  becomes a Cycle-2 decision method — see note below; here only its dangling
  board-schema references are cleaned so the app boots.)

### Code — listeners (`lib/Listener/`)

- **DELETE** `BoardMeetingCalDavBridge.php` (board-meeting → CalDAV; subsumed by
  universal `meeting`).
- **FLAG** `SubmissionDeadlineListener.php`, `MeetingFolderListener.php` — verify
  they reference `meeting`, not `board-meeting`; retarget any board-meeting
  reference.

### Code — search / dashboard / activity / migration

- **EDIT** `lib/Search/DecideskSearchProvider.php`: remove the
  `'resolution' => 'resolutions'` entry from the `SCHEMAS` constant (line 63)
  and the `'resolution' => $this->l10n->t('Resolution')` label (line 247). The
  deleted `resolution` schema no longer exists; resolutions remain searchable as
  `decision` objects (the `decision` schema is already indexed).
- **FLAG** `lib/Dashboard/DecideskDashboardWidget.php`,
  `lib/Service/DashboardWidgetService.php`,
  `lib/Controller/DashboardController.php`,
  `lib/Service/GovernanceReportingService.php`,
  `lib/Activity/DecideskProvider.php`, `lib/Activity/GovernanceSetting.php`,
  `lib/Migration/MigrateActionItemsToDeckLeaf.php`,
  `lib/Service/ActivityPublisherService.php` — grep each for `board`/`resolution`
  slug references; retarget to unified entities. (Most hits are prose/labels;
  apply verifies no live `schema => 'board-*'` query remains.)
- **EDIT** `lib/Settings/register.d/42-admin-settings-v1.json`,
  `43-process-config-v1.json`,
  `40-migrate-action-items-to-deck-leaf.json` — verify their `board`/`resolution`
  hits are prose only (process-config domain labels are fine); strip any
  register-mapping group that targets a deleted schema slug.

### Frontend services / comments (low-risk, retarget prose)

- **EDIT** `src/services/noticeRules.js` — comments reference
  `BoardMeetingService` and "board meeting"; the logic operates on a generic
  `meeting` payload, so keep the code and update comments to "meeting".
- **KEEP** `src/services/agendaRules.js` — `board-elections` is an agenda-topic
  label (legitimate domain vocabulary), not a board-schema reference.

## Nextcloud Integration

- Controllers: removing board controllers; flagged controllers retargeted/removed.
- Services: removing board services; flagged services retargeted/removed.
- Mappers/Entities: none (thin client — OpenRegister owns objects).
- Events/Hooks: removing the `BoardMeetingCalDavBridge` event listener;
  meeting CalDAV sync stays on the universal `meeting` path (ADR-002).

## Security Considerations

Removing endpoints reduces attack surface. The flagged board-coupled controllers
(conflict-of-interest, audit-log, eIDAS, proxy-vote, governance-report,
regulator-export, multilingual) must keep their existing per-object / admin
authorization guards when retargeted — the apply step must not drop a guard
while moving a method onto the unified entity (avoid the
hydra-gate-no-admin-idor / orphan-auth class of regressions). No new endpoints
are introduced.

## NL Design System

The deleted board views and their nav items disappear; no new UI is added. The
mode-aware labels that replace the parallel "Boards / Board meetings /
Resolutions" nav are delivered by the separate `ia-six-item-nav` (C7) change,
using NL Design tokens — out of scope here.

## Seed Data

Replace the deleted board demo data with `mode=corp` seeds on the universal
schemas so the corporate scenario survives the deletion. `bodyType` uses the
existing enum value `corporate-board` (see DEFERRED_QUESTIONS — ADR-006 names
`supervisory-board`/`executive-board`, but the live enum is `corporate-board`;
splitting the enum is deferred to avoid an invalid seed).

### Schema: `governance-body`
| Field | Object 1 |
|-------|----------|
| @self.register | decidesk |
| @self.schema | GovernanceBody |
| @self.slug | raad-van-commissarissen-acme-bv |
| name | Raad van Commissarissen ACME B.V. |
| bodyType | corporate-board |
| domain | corporate |
| votingDefault | for-against-abstain |
| termStart | 2024-01-01T00:00:00Z |
| termEnd | 2027-12-31T00:00:00Z |

### Schema: `meeting`
| Field | Object 1 |
|-------|----------|
| @self.register | decidesk |
| @self.schema | Meeting |
| @self.slug | rvc-vergadering-2025-q2 |
| title | RvC-vergadering Q2 2025 |
| meetingType | regular |
| scheduledDate | 2025-04-17T14:00:00Z |
| endDate | 2025-04-17T16:30:00Z |
| location | ACME B.V. hoofdkantoor, bestuurskamer |
| meetingMode | hybrid |
| lifecycle | closed |

### Schema: `minutes`
| Field | Object 1 |
|-------|----------|
| @self.register | decidesk |
| @self.schema | Minutes |
| @self.slug | notulen-rvc-2025-q2 |
| title | Notulen RvC-vergadering Q2 2025 |
| lifecycle | approved |
| content | De voorzitter van de Raad van Commissarissen opent de vergadering om 14:00 uur... |
| approvedAt | 2025-05-15T10:00:00Z |
| signedBy | ["Voorzitter RvC", "Secretaris"] |
| version | 1 |

**Related items per object:**
- Files: corporate board materials as generic DigitalDocument attachments on the
  `meeting` (replaces the deleted `BoardMaterial` schema).
- Notes: none.
- Tasks: none.
- Contacts: corporate board members already seeded as Person + Membership in C2.

**Note — eIDAS signing:** the corporate signing/attestation flow is NOT a seed
field here. Per ADR-006 it becomes a pluggable **decision method** (`signature`)
on the unified Decision in Cycle 2 (`decision-methods`), available regardless of
mode. This change only leaves the note and cleans eIDAS's dangling board-schema
references; it does not build the method.

## Trade-offs

- **One change vs split (ADR-032):** chosen one change because the config and
  code deletions are co-dependent — see Mixed-spec rationale.
- **Re-seed corp demo vs accept its loss:** chosen to re-seed so the corporate
  scenario stays demonstrable on install (ADR-016), at the cost of three small
  seed additions.
- **Flag board-coupled services vs auto-delete:** chosen to flag (not blind
  delete) because several Phase 4-6 services (eIDAS, governance-report,
  regulator-export, etc.) carry reusable behaviour that should be retargeted
  onto the unified entities; deleting them would lose capability.

## Open Questions

- `GovernanceBody.bodyType` enum offers `corporate-board`, not the
  `supervisory-board`/`executive-board` pair named in ADR-006. The re-seed uses
  `corporate-board` to stay schema-valid; splitting the enum is a separate,
  deferred change (DEFERRED_QUESTIONS).
- For each **flagged** board-coupled controller/service the apply step decides
  retarget-vs-remove; this design lists the coupling but does not pre-decide,
  to keep per-file judgement with apply.

## Owner decisions (resolved — these OVERRIDE the provisional decisions above)

### Board-coupled services: RETARGET to unified entities (do NOT remove)
The board-coupled governance features are preserved by repointing them at the
universal entities — no feature loss:

| Service | Retarget from → to |
|---|---|
| `EIDASSignatureService` (+ interface/log) | `board-minutes`/`resolution` → `minutes` / `decision` (becomes a proper decision *method* in Cycle 2; here just repoint slugs + keep working) |
| `ConflictOfInterestService` | `board-member` → `Person` + `Membership` (the `conflict-of-interest` schema itself stays) |
| `ProxyVoteService` | `board-vote` → `vote` / `voting-round` |
| `AuditLogService` | `board-audit-log-entry` → OR built-in `auditTrail` (use the entity's auditTrail; drop the bespoke schema) |
| `GovernanceReportingService` | `board-meeting`/`resolution` → `meeting` / `decision` |
| `RegulatorExportService` | `board-*` → `meeting`/`decision`/`minutes` |
| `MultilingualReconciliationService` | `board-minutes` → `minutes` |
| `QesGuard` | repoint any `board-*` slug references to the unified equivalents |

Their CONTROLLERS/ROUTES are RETAINED (retargeted), not deleted. Only the
board-CRUD controllers/services/views that have a direct unified equivalent are
deleted: `BoardController`, `BoardMemberController`, `BoardMeetingController`,
`BoardVoteController`, `BoardMaterialController`, `ResolutionController`,
`BoardPortalControllerTrait`, `BoardService`, `BoardMemberService`,
`BoardMeetingService`, `BoardVoteService`, `BoardMaterialAuthorizationService`,
`BoardCalDavSyncService`, `ResolutionService`, `WrittenResolutionService`,
`ResolutionLifecycleGuard`, `BoardMeetingCalDavBridge`, and the orphaned
`BoardCreateModal.vue` / `BoardMeetingCreateModal.vue`. (The CRUD of these is now
served by the universal manifest pages over governance-body/meeting/decision/minutes.)

### bodyType enum: ADD supervisory-board + executive-board now
Extend `GovernanceBody.properties.bodyType.enum` with `supervisory-board` and
`executive-board` (keep existing values incl. `corporate-board`). Corp re-seeds use
`supervisory-board` / `executive-board` to match ADR-006 exactly.
