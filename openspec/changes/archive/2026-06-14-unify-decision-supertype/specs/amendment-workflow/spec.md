# Spec delta: Amendment Workflow — retired into decision-management

This file contains delta specifications for the `unify-decision-supertype` change against the existing `amendment-workflow` capability. Per ADR-005/ADR-006 the separate `amendment` schema is retired; an amendment is now a `decision` with `decisionType = amendment` that carries an `amends` relation to a parent motion decision. The amendment submission/storage requirement is removed and its behaviour is owned by `decision-management`. Conflict alerting, listing and independent voting continue to apply to amendment-typed decisions.

---

## REMOVED Requirements

### Requirement: REQ-AMD-001 Amendment is submitted against an existing Motion

**Reason:** ADR-005 makes `Decision` the universal supertype; the separate `amendment` schema is retired. An amendment is now a `decision` object with `decisionType = amendment` that carries an `amends` relation to a parent `decisionType = motion` decision, with amendment fields (`proposedText`, `proposer`) folded onto the decision schema and revealed via progressive disclosure.

**Migration:** Amendment creation is now governed by `decision-management` → "Folded type-specific fields with progressive disclosure" (which requires the `amends` relation for `decisionType = amendment`). Existing amendment seed objects are re-seeded as `decisionType = amendment` decisions and their former `amendment → motion` relation is re-pointed to the corresponding motion decision (see migration.md).

#### Scenario: Member submits an amendment as a typed decision

- **GIVEN** a `decisionType = motion` decision in lifecycle `deliberating`
- **WHEN** a member creates a decision with `decisionType = amendment`, fills in `title`, `proposedText`, and `proposer`, and sets the `amends` relation to that motion decision
- **THEN** a `decision` object is saved with `decisionType = amendment` and an OpenRegister relation to the parent motion decision
