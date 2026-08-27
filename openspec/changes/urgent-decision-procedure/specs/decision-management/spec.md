# decision-management Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- urgent-decision-procedure

## Purpose

Delta for the urgent-decision-procedure change: adds the urgency declaration fields to the universal `Decision` schema and the declarative `awaitingRatification` derivation. Behavioural requirements (trigger guard, ratification, indicators) live in the `urgent-decision-procedure` capability spec.

## ADDED Requirements

### Requirement: Decision urgency fields

The `decision` schema SHALL carry four additive optional urgency properties: `isUrgent` (boolean, default false), `urgencyReason` (string — required when `isUrgent` is true, enforced by the trigger flow), `urgencyDeclaredBy` (the declaring Nextcloud user id), and `urgencyDeclaredAt` (date-time). These fields SHALL be orthogonal to `lifecycle`, `outcome`, and `isPublished` — no lifecycle state or transition SHALL be added or changed (the existing `x-openregister-lifecycle` block is extended with nothing; urgency mirrors how `isPublished` already sits beside the lifecycle). The urgency fields SHALL be writable only through the guarded urgency-trigger flow and MUST be rejected when set directly through ordinary object update requests (same server-side field-guard posture as `isPublished`/`publishedAt`). Because OR `saveObject` is PUT-semantic, every decision write path MUST carry the urgency fields forward; an unrelated update to an urgent decision MUST NOT clear them.

#### Scenario: Urgency fields are additive and lifecycle-orthogonal

- GIVEN the decidesk register definition after this change
- WHEN the `decision` schema is inspected
- THEN it contains `isUrgent`, `urgencyReason`, `urgencyDeclaredBy`, `urgencyDeclaredAt` as optional properties
- AND the `x-openregister-lifecycle` transition map is byte-identical to the pre-change map

#### Scenario: Direct client write to isUrgent rejected

- WHEN a client sends an object update setting `isUrgent: true` on a decision outside the urgency-trigger flow
- THEN the write to the urgency fields is rejected and the stored values are unchanged

#### Scenario: Unrelated update preserves urgency fields

- GIVEN an urgent decision with `isUrgent=true` and a stored `urgencyReason`
- WHEN a user updates only the decision's `title`
- THEN `isUrgent`, `urgencyReason`, `urgencyDeclaredBy`, and `urgencyDeclaredAt` survive unchanged

### Requirement: Declarative awaitingRatification derivation

The `decision` schema SHALL expose an `awaitingRatification` field derived declaratively (`x-openregister-calculations`, ADR-031) from the decision's own fields and its related DecisionStage objects: true if and only if `isUrgent` is true AND at least one related stage has `stageType=ratifying` with a non-terminal `status` (neither `decided` nor `skipped`). No imperative Service SHALL compute this value; list, detail, and dashboard consumers SHALL read the materialised field and SHALL NOT recompute it (mirroring the existing `currentStage`/`routeComplete` pattern).

#### Scenario: Derivation over the route

- GIVEN an urgent decision with a ratifying stage in `status=pending`
- WHEN the decision is loaded
- THEN `awaitingRatification` is true
- AND when the ratifying stage reaches `decided`, `awaitingRatification` derives to false

#### Scenario: Non-urgent decisions never await ratification

- GIVEN a decision with `isUrgent=false` that happens to carry a `ratifying` stage in its regular route (e.g. an MT → RvB → RvC route)
- WHEN the decision is loaded
- THEN `awaitingRatification` is false, because the derivation requires `isUrgent`
