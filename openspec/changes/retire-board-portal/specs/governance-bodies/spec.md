# governance-bodies Specification

**Status**: in-progress
**Scope**: decidesk
**OpenSpec changes**:
- retire-board-portal

## Purpose

Governance bodies are the single universal entity for any decision-making body —
councils, association boards, corporate supervisory/executive boards, and
operational management teams. Per ADR-006, audience differences are expressed by
mode adaptation (labels), the `bodyType` type discriminator, and progressive
disclosure — never by a parallel schema. This delta retires the parallel
corporate `Board` schema and re-expresses the corporate board as a
`governance-body` with `mode=corp`.

## MODIFIED Requirements

### Requirement: REQ-GBD-003 — Meeting creation from governance body

The system SHALL allow creating a meeting directly from the GovernanceBody
detail page. The governance body SHALL be pre-filled in the meeting creation
form when navigating from the body detail page. This SHALL hold for every
`bodyType`, including corporate boards (`bodyType=corporate-board`, mode=corp):
a corporate board is a `governance-body`, not a separate `Board` entity, so it
creates `meeting` objects through the same path. The labels shown ("Raad" /
"Board" / "Supervisory Board" / "MT") adapt to the organisation mode without
branching the schema or the creation flow.

#### Scenario: REQ-GBD-003-S1 — Pre-filled governance body
- **GIVEN** the user is on the detail page for "Gemeenteraad Delft"
- **WHEN** the user clicks "Add meeting" in the Scheduled Meetings section header
- **THEN** the router navigates to `/meetings/new?governanceBodyId={bodyId}`
- **AND** the meeting form has the governance body field pre-filled with "Gemeenteraad Delft"

#### Scenario: REQ-GBD-003-S2 — Corporate board creates a meeting via the universal path

@e2e exclude corporate-mode label adaptation over the existing governance-body detail flow; the underlying create-meeting path is covered by REQ-GBD-003-S1 and meeting-management e2e; this scenario asserts only that no parallel Board surface is used

- **GIVEN** a `governance-body` with `bodyType=corporate-board` (e.g. "Raad van Commissarissen ACME B.V."), organisation mode = corp
- **WHEN** the user clicks "Add meeting" on its detail page
- **THEN** the router navigates to `/meetings/new?governanceBodyId={bodyId}` using the same universal meeting-creation path as every other body type
- **AND** the displayed labels adapt to corporate vocabulary ("Board"/"Supervisory Board") without any `Board`-schema object being created

## REMOVED Requirements

### Requirement: Parallel corporate Board entity

**Reason**: Violates ADR-006 (one schema per concept). The `Board` schema
duplicated `governance-body` for the corporate audience, guaranteeing data
drift. Corporate governance bodies are now `governance-body` objects with
`bodyType=corporate-board` and mode=corp label adaptation.

**Migration**: Corporate boards are represented as `governance-body` objects
(`bodyType=corporate-board`). The corporate demo is re-seeded onto
`governance-body` (slug `raad-van-commissarissen-acme-bv`) by this change. The
`Board` schema, its seeds, the `board#*` / `boardMember#*` routes, the
`BoardController`/`BoardMemberController`/`BoardService`/`BoardMemberService`,
and the `BoardList`/`BoardDetail` Vue views are deleted (see
design.md "Reference cleanup"). Board members were already migrated to Person +
Membership in `popolo-decision-makers` (C2, ADR-001).

## Acceptance Criteria

- [ ] No `Board` schema remains in `lib/Settings/decidesk_register.json`.
- [ ] A `governance-body` seed with `bodyType=corporate-board` exists so the corporate scenario is demonstrable.
- [ ] Creating a meeting from a corporate governance body uses the universal `/meetings/new` path.

## Notes

Related ADRs: ADR-006 (mode adaptation over parallel entities), ADR-004
(information architecture / six-item nav), ADR-001 (Popolo — board-member →
Person+Membership, done in C2).
