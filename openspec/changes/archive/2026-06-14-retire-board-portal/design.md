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
deleted.

### bodyType enum: ADD supervisory-board + executive-board now
Extend `GovernanceBody.properties.bodyType.enum` with `supervisory-board` and
`executive-board` (keep existing values incl. `corporate-board`). Corp re-seeds use
`supervisory-board` / `executive-board` to match ADR-006 exactly.

## Seed Data

Three `mode=corp` seeds are added to the universal schemas so the corporate
scenario survives the deletion:

- `governance-body` slug `raad-van-commissarissen-acme-bv`
  (`bodyType=supervisory-board`, domain `corporate`).
- `meeting` slug `rvc-vergadering-2025-q2`.
- `minutes` slug `notulen-rvc-2025-q2`.

## Security Considerations

Removing endpoints reduces attack surface. The retained board-coupled
controllers keep their existing per-object / admin authorization guards when
retargeted — the apply step does not drop a guard while moving a method onto the
unified entity (avoiding hydra-gate-no-admin-idor / orphan-auth regressions). No
new endpoints are introduced.
