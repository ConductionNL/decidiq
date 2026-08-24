# resolution-minutes Specification

**Status**: in-progress
**Scope**: decidesk
**OpenSpec changes**:
- retire-board-portal

## Purpose

Retires the parallel corporate `Resolution`, `BoardVote`, `BoardMinutes`,
`BoardMaterial`, and `BoardAuditLogEntry` entities from the resolution-minutes
capability. Per ADR-006/ADR-005 a resolution is a universal `decision` with
`decisionType=resolution`; board votes are `vote`/`voting-round`; board minutes
are `minutes`; board materials are generic DigitalDocument attachments; the board
audit log folds into the OR built-in `auditTrail`.

## ADDED Requirements

### Requirement: REQ-RM-CORP-RES — Resolution is a typed decision (mode=corp)
A resolution MUST be a universal `decision` with `decisionType=resolution`
(ADR-005), never a separate schema. Accordingly the parallel `Resolution` schema
(slug `resolution`), the `ResolutionList` /
`ResolutionDetail` Vue views, the resolution routes, the resolution
controller/service, and the `ResolutionLifecycleGuard` are REMOVED. A resolution
is a universal `decision` with `decisionType=resolution` (ADR-005, done in
`unify-decision-supertype`). The `resolution` entry is removed from the unified
search provider; decisions remain searchable.

#### Scenario: Resolution is a decision, not a separate schema
- GIVEN the register is imported on a clean instance
- WHEN the schemas are listed
- THEN no `resolution` schema exists
- AND resolutions are represented as `decision` objects with `decisionType=resolution`

#### Scenario: Decisions remain searchable after resolution removal
- GIVEN the unified search provider
- WHEN its searched schemas are inspected
- THEN `resolution` is not listed
- AND `decision` and `meeting` are still searched

### Requirement: REQ-RM-CORP-SUB — Board vote/minutes/material/audit fold into universal entities (mode=corp)
Corporate board votes MUST be `vote`/`voting-round`, board minutes MUST be
`minutes`, board materials MUST be DigitalDocument attachments, and the board
audit log MUST use the OR built-in `auditTrail` — never separate schemas.
Accordingly the parallel `BoardVote` (slug `board-vote`), `BoardMinutes`
(slug `board-minutes`), `BoardMaterial` (slug `board-material`), and
`BoardAuditLogEntry` (slug `board-audit-log-entry`) schemas are REMOVED. Board
votes are `vote`/`voting-round`; board minutes are `minutes`; board materials are
generic DigitalDocument attachments; the board audit log uses the OR built-in
audit trail. The retained governance services (eIDAS, regulator-export,
governance-report, multilingual-reconciliation, proxy-vote, audit-log) are
retargeted onto these unified entities, keeping their auth guards.

#### Scenario: Board sub-entities removed and services retargeted
- GIVEN the register is imported and the app boots
- WHEN the schemas are listed and the governance services run
- THEN no `board-vote` / `board-minutes` / `board-material` / `board-audit-log-entry` schema exists
- AND the retained governance services query `vote` / `minutes` / `decision` / `audit-trail` instead

## Acceptance Criteria

- [ ] No `resolution` / `board-vote` / `board-minutes` / `board-material` / `board-audit-log-entry` schema remains
- [ ] `resolution` is removed from the search provider; decisions stay searchable
- [ ] Retained governance services keep their existing auth guards after retargeting
