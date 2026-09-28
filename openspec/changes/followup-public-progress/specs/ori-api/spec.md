# ori-api Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [followup-public-progress](../../) (this delta)

## Purpose

Motions are exposed on the public ORI API, but commitments are not published at all and there is no public progress view. Moves decidiq matrix rows fol-06 toward built.

## ADDED Requirements

### Requirement: REQ-FPP-001 The public sees commitment and motion progress

Commitments and motions SHALL be available on the public ORI API with their status, deadline and progress, without internal fields.

#### Scenario: A journalist follows a promise
- GIVEN the alderman committed to a housing report by 1 December with one progress entry
- WHEN a journalist reads the public commitments list
- THEN she sees the commitment, its deadline and the progress entry
