# Spec delta: Motion Management — retired into decision-management

This file contains delta specifications for the `unify-decision-supertype` change against the existing `motion-management` capability. Per ADR-005/ADR-006 the separate `motion` schema is retired; a motion is now a `decision` with `decisionType = motion`. The motion submission/storage requirement is removed and its behaviour is owned by `decision-management` (folded type-specific fields, declarative lifecycle). The role-based lifecycle, co-signatory, financial-impact and listing behaviours continue to apply to motion-typed decisions and are recovered there.

---

## REMOVED Requirements

### Requirement: REQ-MOT-001 Motion can be submitted against a decision-type AgendaItem

**Reason:** ADR-005 makes `Decision` the universal supertype; the separate `motion` schema is retired. A motion is now a `decision` object with `decisionType = motion`, created through the unified decision surface with the motion fields (`motionType`, `proposer`, `coSigners`, `text`) folded onto the decision schema and revealed via progressive disclosure.

**Migration:** Motion creation is now governed by `decision-management` → "Decision type discriminator" and "Folded type-specific fields with progressive disclosure". A motion is created by selecting `decisionType = motion`; `proposer` is set to the submitting user's display name and the decision is linked to the `decision`-type AgendaItem via an OpenRegister relation. Existing motion seed objects are re-seeded as `decisionType = motion` decisions (see migration.md).

#### Scenario: Member submits a motion as a typed decision

- **GIVEN** a Meeting in lifecycle `opened` with a `decision`-type AgendaItem
- **WHEN** a member creates a decision with `decisionType = motion` from that agenda item, filling in `title`, `text`, and `motionType`
- **THEN** a `decision` object is saved with `decisionType = motion`, `proposer` set to the user's display name, and linked to the AgendaItem via an OpenRegister relation
