# Spec delta: Decision Management — publication state ownership

This file contains delta specifications for the publish-decisions-via-opencatalogi change against the existing `decision-management` capability. It gives the dormant `isPublished`/`publishedAt` fields an owner; it does not change the decision lifecycle.

---

## ADDED Requirements

### Requirement: Publication state is owned by the publication flow

The `Decision` fields `isPublished` and `publishedAt` SHALL be derived outputs of the public-publication flow: set on publish, cleared on withdraw, and rejected when written directly through object update requests. The decision detail view SHALL expose publish and withdraw actions to staff with governance-body authority, visible only when the decision meets the publication eligibility gates. Publish and withdraw events SHALL be recorded in the decision's immutable audit trail with actor, timestamp, and (for withdraw) reason.

#### Scenario: Publish action visible only when eligible

- **GIVEN** a staff member viewing a decision in status `enacted`
- **WHEN** they open the decision detail view
- **THEN** a publish action is available, while the same view for a `draft` decision offers no publish action

#### Scenario: Direct client write to isPublished rejected

@e2e exclude server-side field guard — covered by Newman attempting a direct OR object update
- **WHEN** a client sends an object update setting `isPublished: true` on a decision outside the publication flow
- **THEN** the write to `isPublished`/`publishedAt` is rejected and the stored values are unchanged

#### Scenario: Publication events in the audit trail

- **GIVEN** a decision that has been published and later withdrawn
- **WHEN** a user views the decision's audit trail
- **THEN** both the publish and the withdraw appear in chronological order with timestamp, actor, and the withdraw reason
