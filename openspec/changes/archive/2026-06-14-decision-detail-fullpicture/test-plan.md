# Test Plan: decision-detail-fullpicture

## Test Cases

### TC-1: Route timeline renders with current stage highlighted
- **spec_ref**: `openspec/changes/decision-detail-fullpicture/specs/decision-route/spec.md#requirement-route-timeline-view-on-the-decision-detail`
- **type**: functional
- **persona**: Henk (council secretary tracking a decision's journey)
- **preconditions**: a decision with a three-stage route (College decided, Auditcommissie decided, Gemeenteraad active); `currentStage` is the Gemeenteraad stage
- **steps**: open the decision detail → open the route tab
- **expected result**: three stages listed by sequence with decision-maker, stageType, method, status, outcome, decidedAt; Gemeenteraad highlighted as current; progress reads "2 of 3 stages decided"
- **test command**: /test-functional (Playwright UI)

### TC-2: Stageless decision route tab shows an empty state
- **spec_ref**: `openspec/changes/decision-detail-fullpicture/specs/decision-route/spec.md#requirement-route-timeline-view-on-the-decision-detail`
- **type**: functional
- **preconditions**: a decision with `stageCount` = 0
- **steps**: open the route tab
- **expected result**: "No staged route configured" empty state; no console error
- **test command**: /test-functional (Playwright UI) + vitest on the empty-state branch

### TC-3: Add a supersedes relation via the Related decisions tab
- **spec_ref**: `openspec/changes/decision-detail-fullpicture/specs/relation-tab-ui/spec.md#requirement-req-rtu-002-peer-relation-tabs-for-typed-links-between-existing-objects`
- **type**: functional
- **persona**: Henk (with governance-body authority)
- **preconditions**: decisions "Programmabegroting 2027" and enacted "Programmabegroting 2026" exist
- **steps**: open 2027's Related decisions tab → Add relation → search/select 2026 → choose `supersedes` → confirm
- **expected result**: relation saved on 2027; appears under the `supersedes` group after refresh; both audit trails record it
- **test command**: /test-functional (Playwright UI)

### TC-4: Effective-status banner + in-force filter
- **spec_ref**: `openspec/changes/decision-detail-fullpicture/specs/decision-management/spec.md#requirement-in-force-visibility-in-list-and-detail-views`
- **type**: functional
- **preconditions**: enacted 2027 supersedes enacted 2026 (seed)
- **steps**: open 2026 detail; then on `/decisions` set the in-force filter to "in force"
- **expected result**: 2026 shows effectiveStatus `superseded` + "Superseded by Programmabegroting 2027" banner with navigation, lifecycle badge still visible; the in-force list excludes 2026
- **test command**: /test-functional (Playwright UI)

### TC-5: Relation integrity — self-reference and cycle rejected
- **spec_ref**: `openspec/changes/decision-detail-fullpicture/specs/decision-management/spec.md#requirement-relation-integrity-validation`
- **type**: api
- **preconditions**: decisions A and B with A `supersedes` B
- **steps**: attempt to add a relation from a decision to itself; attempt to add `supersedes` from B to A
- **expected result**: both rejected with a validation error naming the conflict; nothing stored; dialog stays open showing the error inline (UI)
- **test command**: /test-api (Newman) + PHPUnit on the validation seam

### TC-6: Effect-bearing relation requires authority (IDOR)
- **spec_ref**: `openspec/changes/decision-detail-fullpicture/specs/decision-management/spec.md#requirement-typed-decision-to-decision-modification-relations`
- **type**: security
- **persona**: Noor (CISO probing authorization)
- **preconditions**: an authenticated user WITHOUT governance-body authority
- **steps**: attempt to add a `repeals` relation via the OR object API
- **expected result**: HTTP 403; no relation or audit entry created
- **test command**: /test-api (Newman) + /test-security

### TC-7: Derived effective status precedence + draft exerts no effect
- **spec_ref**: `openspec/changes/decision-detail-fullpicture/specs/decision-management/spec.md#requirement-derived-effective-status`
- **type**: functional
- **preconditions**: a target with both an enacted supersedes and an enacted repeals; plus a draft repeal of an enacted target
- **steps**: compute effectiveStatus for the target; display the draft-repealed target
- **expected result**: precedence yields `repealed`; the draft-repealed target still presents in force until the source reaches decided/enacted
- **test command**: /test-functional + PHPUnit on the derivation (or vitest if client-side per design D2)

### TC-8: Empty-objectId peer tab issues no fetch
- **spec_ref**: `openspec/changes/decision-detail-fullpicture/specs/relation-tab-ui/spec.md#requirement-req-rtu-002-peer-relation-tabs-for-typed-links-between-existing-objects`
- **type**: regression
- **preconditions**: peer-relation tab mounted with empty `objectId`
- **steps**: initialise the tab
- **expected result**: `refresh()` returns without a fetch (no network call)
- **test command**: vitest (component unit)

## Coverage Summary

- decision-route / Route timeline view — covered (TC-1, TC-2, banner in TC-4)
- relation-tab-ui / REQ-RTU-002 peer-relation tabs — covered (TC-3, TC-5 inline error, TC-8)
- decision-management / Typed modification relations — covered (TC-3, TC-6)
- decision-management / Relation integrity validation — covered (TC-5)
- decision-management / Derived effective status — covered (TC-7, TC-4)
- decision-management / In-force visibility — covered (TC-4)
- Notification rule (superseded/repealed) — covered by the notification-dialect gate + PHPUnit on rule import (not a UI flow).

## Out of Scope

- Consolidation text rendering for `amends` chains (deferred, design Non-goals).
- Published-payload relation fields (public-publication capability) — verified by Newman when that capability is configured; not part of this change's surface.
