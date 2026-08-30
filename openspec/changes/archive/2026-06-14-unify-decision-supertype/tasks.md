# Tasks: unify-decision-supertype

> Config-first ordering (ADR-032 supervised-local exception): schema-register patches
> (Tasks 1-2) → frontend + ORI code (Task 3) → schema retirement + object re-seed (Task 4).

## Implementation Tasks

### Task 1: Add decisionType discriminator, folded fields, and declarative lifecycle to the Decision schema
- **spec_ref**: `openspec/changes/unify-decision-supertype/specs/decision-management/spec.md#requirement-decision-type-discriminator`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the decidesk register WHEN the `decision` schema is inspected THEN `decisionType` is a required enum with the 8 ADR-005 values
  - GIVEN the `decision` schema WHEN inspected THEN it carries the folded motion/amendment/resolution fields and an `x-openregister-lifecycle` block including the terminal `withdrawn` state
  - GIVEN a create with `decisionType = resolution` and no `resolutionNumber`/`voteThreshold` WHEN submitted THEN it is rejected with a validation error
- [x] Implement
- [ ] Test

### Task 2: Declare contract attachments and the amends relation; bump schema version
- **spec_ref**: `openspec/changes/unify-decision-supertype/specs/decision-management/spec.md#requirement-contract-decisions-carry-offerorderproduct-attachments`
- **files**: `lib/Settings/decidesk_register.json`
- **acceptance_criteria**:
  - GIVEN the `decision` schema WHEN inspected THEN `x-openregister-relations` declares `amends → Decision` and `offer`/`order`/`product` contract attachments, and the old `motion → Motion` relation is removed
  - GIVEN a `decisionType = contract` decision WHEN an offer is related to it THEN the offer appears as an attachment of the contract decision
- [x] Implement
- [ ] Test

### Task 3: Fold motion/amendment/resolution views into decisionType-filtered decision views and source ORI motions from decisions
- **spec_ref**: `openspec/changes/unify-decision-supertype/specs/ori-api/spec.md#requirement-req-ori-006-ori-motion-endpoint-sourced-from-typed-decisions`
- **files**: `src/views/decision/`, `src/` (remove motion/amendment/resolution views; fold "Moties" nav into a typed filter), `lib/Controller/OriController.php`, `lib/Service/OriService.php`
- **acceptance_criteria**:
  - GIVEN the nav WHEN "Moties" is opened THEN the decision list is shown pre-filtered to `decisionType=motion` from the same store
  - GIVEN the decision form WHEN `decisionType=motion` is selected THEN motion fields are revealed via progressive disclosure (NcSelect with inputLabel)
  - GIVEN published `decisionType=motion` decisions WHEN `GET /api/ori/v1/motions` is called THEN they are serialized as Popolo Motions with the pre-fold response shape, and non-motion/non-public decisions are excluded
- [x] Implement
- [ ] Test

### Task 4: Retire the motion/amendment/resolution schemas and re-seed their objects as typed decisions
- **spec_ref**: `openspec/changes/unify-decision-supertype/specs/decision-management/spec.md#requirement-re-seeded-typed-decision-demo-data`
- **files**: `lib/Settings/decidesk_register.json` (see `openspec/changes/unify-decision-supertype/migration.md`)
- **acceptance_criteria**:
  - GIVEN the register WHEN `components.schemas` is inspected THEN `Motion`, `Amendment`, and `Resolution` are absent
  - GIVEN a freshly installed register WHEN the decision register is listed THEN ≥1 decision exists for each of the 8 `decisionType` values
  - GIVEN the re-seeded data WHEN a `decisionType=amendment` seed is inspected THEN it carries an `amends` relation to a `decisionType=motion` decision, and a `decisionType=contract` seed resolves offer/order/product attachments
- [x] Implement
- [ ] Test

## Quality checklist

- Decision lifecycle, notifications, and contract/amends relations are DECLARATIVE in `lib/Settings/decidesk_register.json` (ADR-031) — no new state-machine/notification Service classes.
- ORI mapping stays a thin projection at the serialization boundary (ADR-001/ADR-003); endpoint path and response shape unchanged.
- Progressive-disclosure field visibility is driven purely by `decisionType` (ADR-004 Rule 2); per-type required-field matrix honoured.
- No hardcoded colours; NcSelect carries `inputLabel`; WCAG AA.
- Fix any pre-existing quality issues (PHPCS, PHPMD, PHPStan, Psalm, hydra-gates) encountered.

## Verification
- [x] All implementation tasks done (schema unify, manifest retarget, ORI fold, seeds)
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria (Motions page filters to decisionType=motion; ORI /api/ori/v1/motions returns published motion decisions as Popolo Motions with name/status/classification; webpack build green; register re-imports cleanly)
- [ ] Code review against spec requirements
- Pre-existing ORI bugs fixed in passing: `findAll()` was called with unknown named params (`register:`/`schema:`/`params:`) → restructured to the config-array form with register/schema nested in `filters`; the result loop did `(array) $object` (mangling the entity) → switched to `jsonSerialize()`. Both had broken ALL ORI list resources, not just motions.

## Tests (company-wide ADR-009)
- [ ] PHPUnit unit tests for the ORI motion serializer (decisionType=motion projection, shape parity)
- [ ] Newman test asserting `/api/ori/v1/motions` response-shape parity before/after the fold
- [ ] Browser tests (Playwright MCP) for the typed decision form (progressive disclosure) and "Moties" typed-filter nav
- [ ] All tests pass (`composer test`, `newman run`)

## Documentation (company-wide ADR-010)
- [ ] Feature documentation updated in `docs/` (decision types + progressive disclosure)
- [ ] Screenshot captured and committed to `docs/images/`

## i18n (company-wide ADR-005)
- [ ] Dutch (`nl_NL`) and English (`en_US`) strings added for the new `decisionType` labels and folded field labels
