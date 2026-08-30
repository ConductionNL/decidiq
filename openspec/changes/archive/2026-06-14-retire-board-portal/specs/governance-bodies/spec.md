# governance-bodies Specification

**Status**: in-progress
**Scope**: decidesk
**OpenSpec changes**:
- retire-board-portal

## Purpose

Retires the parallel corporate `Board` entity from the governance-bodies
capability. Per ADR-006 a corporate board is the universal `governance-body`
with `bodyType` set to `supervisory-board` / `executive-board` and mode=corp
labels — never a separate schema. The corporate scenario is re-seeded on the
universal `governance-body`.

## ADDED Requirements

### Requirement: REQ-GBD-012 — Corporate board is the universal governance-body (mode=corp)
A corporate board MUST be expressed as a `governance-body` with
`bodyType=supervisory-board` / `executive-board` (ADR-006) and mode=corp labels —
never a separate schema. The parallel `Board` / `BoardMember` schemas, the
`BoardList` / `BoardDetail` views, the `BoardCreateModal`, and the board-CRUD
controller/service are removed; board members are `Person` + `Membership`
(ADR-001, `popolo-decision-makers`). The corporate scenario is re-seeded on the
universal `governance-body`.

#### Scenario: Corporate board uses the universal governance-body
- GIVEN the register is imported on a clean instance
- WHEN the schemas are listed
- THEN no `Board` or `BoardMember` schema exists
- AND a `governance-body` seed `raad-van-commissarissen-acme-bv` with `bodyType=supervisory-board` exists

#### Scenario: bodyType enum carries the corporate subtypes
- GIVEN the `GovernanceBody` schema
- WHEN its `bodyType` enum is inspected
- THEN it includes `supervisory-board` and `executive-board` (alongside the existing values)

## MODIFIED Requirements

### Requirement: REQ-GBD-003 — Meeting creation from governance body
The system MUST allow creating a `meeting` from a `governance-body` regardless of
mode. After retiring the board portal, a corporate board meeting is a universal
`meeting` linked to a `governance-body` (no `board-meeting` schema). A corporate
`meeting` seed and `minutes` seed are provided so the corporate scenario is
demonstrable on install.

#### Scenario: Pre-filled meeting from a corporate governance body
- GIVEN a `governance-body` with `bodyType=supervisory-board`
- WHEN a meeting is created from it
- THEN a universal `meeting` is created (no `board-meeting` schema is used)

#### Scenario: Corporate meeting + minutes seeded
- GIVEN the register is imported on a clean instance
- WHEN meetings and minutes are listed
- THEN a `meeting` seed `rvc-vergadering-2025-q2` and a `minutes` seed `notulen-rvc-2025-q2` exist

## Acceptance Criteria

- [ ] No `Board` / `BoardMember` schema remains in `decidesk_register.json`
- [ ] `GovernanceBody.bodyType` enum includes `supervisory-board` + `executive-board`
- [ ] Corporate `governance-body`, `meeting`, and `minutes` seeds exist and validate
