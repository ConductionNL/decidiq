# Test Plan: unify-decision-supertype

## Test Cases

### TC-1: decisionType is required on decision create
- **spec_ref**: `openspec/changes/unify-decision-supertype/specs/decision-management/spec.md#requirement-decision-type-discriminator`
- **type**: functional
- **persona**: Secretary (griffier / board secretary)
- **preconditions**: GIVEN a user with decision-making access on the decision register
- **steps**: WHEN they submit a new decision without selecting a `decisionType`
- **expected result**: THEN the create is rejected with a validation error naming `decisionType` as required
- **test command**: /test-functional

### TC-2: Progressive disclosure reveals type-specific fields
- **spec_ref**: `openspec/changes/unify-decision-supertype/specs/decision-management/spec.md#requirement-folded-type-specific-fields-with-progressive-disclosure`
- **type**: functional
- **persona**: Member (raadslid / board member)
- **preconditions**: GIVEN the decision create form
- **steps**: WHEN the user selects `decisionType = motion`, then switches to `meeting-outcome`
- **expected result**: THEN `proposer`/`coSigners`/`motionType` are revealed for `motion` and hidden for `meeting-outcome`; switching to `resolution` reveals and requires `resolutionNumber` and `voteThreshold`
- **test command**: /test-functional

### TC-3: "Moties" nav is a typed filter over the unified store
- **spec_ref**: `openspec/changes/unify-decision-supertype/specs/decision-management/spec.md#requirement-decision-type-discriminator`
- **type**: functional
- **persona**: Member
- **preconditions**: GIVEN decisions exist with `decisionType` values `motion` and `resolution`
- **steps**: WHEN the user opens the "Moties" nav entry
- **expected result**: THEN the decision list shows only `decisionType=motion` decisions, sourced from the same `decision` store (no separate motion store/endpoint is called)
- **test command**: /test-functional

### TC-4: Declarative decision lifecycle guards transitions and supports withdraw
- **spec_ref**: `openspec/changes/unify-decision-supertype/specs/decision-management/spec.md#requirement-declarative-decision-lifecycle`
- **type**: functional
- **persona**: Chair
- **preconditions**: GIVEN a decision in lifecycle `draft` and another in `deliberating`
- **steps**: WHEN a transition `draft → enacted` is attempted; WHEN the `deliberating` decision is withdrawn
- **expected result**: THEN the `draft → enacted` jump is rejected by the declared `x-openregister-lifecycle` guard; the `deliberating` decision transitions to terminal `withdrawn`
- **test command**: /test-functional

### TC-5: Contract decision carries offer/order/product attachments
- **spec_ref**: `openspec/changes/unify-decision-supertype/specs/decision-management/spec.md#requirement-contract-decisions-carry-offerorderproduct-attachments`
- **type**: functional
- **persona**: Operations manager (MT)
- **preconditions**: GIVEN a `decisionType = contract` decision
- **steps**: WHEN an `offer` object is related to it
- **expected result**: THEN the offer is stored as an OpenRegister relation and appears in the contract decision's attachments; `offer`/`order`/`product` do not appear as standalone nav stores
- **test command**: /test-functional

### TC-6: ORI motions endpoint serializes typed decisions with unchanged shape
- **spec_ref**: `openspec/changes/unify-decision-supertype/specs/ori-api/spec.md#requirement-req-ori-006-ori-motion-endpoint-sourced-from-typed-decisions`
- **type**: api
- **persona**: Sem (external open-data / ORI consumer)
- **preconditions**: GIVEN published `decisionType=motion` decisions, plus `resolution` decisions and a non-public motion decision
- **steps**: WHEN `GET /api/ori/v1/motions` is called
- **expected result**: THEN only published motion decisions are returned as Popolo/ORI Motions with the recorded pre-fold response shape (fields, `@context`, namespaces); resolution and non-public decisions are excluded
- **test command**: /test-api

### TC-7: Retired schemas absent and every decisionType seeded
- **spec_ref**: `openspec/changes/unify-decision-supertype/specs/decision-management/spec.md#requirement-re-seeded-typed-decision-demo-data`
- **type**: regression
- **preconditions**: GIVEN a freshly installed/re-imported decidesk register
- **steps**: WHEN `components.schemas` and the decision register are inspected
- **expected result**: THEN `Motion`/`Amendment`/`Resolution` schemas are absent; ≥1 decision exists per `decisionType` value (8/8); a `decisionType=amendment` seed resolves an `amends` relation to a `decisionType=motion` decision
- **test command**: /test-regression

### TC-8: Decision form accessibility
- **spec_ref**: `openspec/changes/unify-decision-supertype/specs/decision-management/spec.md#requirement-folded-type-specific-fields-with-progressive-disclosure`
- **type**: accessibility
- **preconditions**: GIVEN the decision create form with the `decisionType` selector
- **steps**: WHEN the form is audited with a screen reader / axe
- **expected result**: THEN the `decisionType` NcSelect exposes a programmatic label (`inputLabel`), conditionally-revealed fieldsets are announced, and WCAG 2.1 AA passes
- **test command**: /test-accessibility

## Coverage Summary

| Requirement | Covered by |
|---|---|
| Decision type discriminator | TC-1, TC-3 (covered) |
| Folded type-specific fields with progressive disclosure | TC-2, TC-8 (covered) |
| Declarative decision lifecycle | TC-4 (covered) |
| Contract decisions carry offer/order/product attachments | TC-5 (covered) |
| Re-seeded typed decision demo data | TC-7 (covered) |
| REQ-ORI-006 ORI Motion endpoint from typed decisions | TC-6 (covered) |
| motion-management REQ-MOT-001 (REMOVED) | TC-1/TC-2 — motion creation now via decision-management (covered) |
| amendment-workflow REQ-AMD-001 (REMOVED) | TC-7 — amendment seed `amends` relation (covered) |
| resolution-minutes Resolution Generation (MODIFIED) | TC-7 — resolution stored as `decisionType=resolution` decision (covered) |

## Out of Scope

- Decision route/stages and pluggable decision methods (Cycle 2).
- Cross-decision relations beyond `amends` (owned by `decision-relations`).
- Live-instance data migration runbook (operator responsibility; demo re-seed is in scope).
