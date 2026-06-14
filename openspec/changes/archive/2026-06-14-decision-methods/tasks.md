# Tasks: decision-methods

Config-first (ADR-031): the bulk of the work is declarative register configuration in `lib/Settings/decidesk_register.json` + seeds. One bounded code touch wires `EIDASSignatureService` to resolve a `method=signature` stage (ADR-032 `kind: code`; see design Mixed-spec rationale). No Vue, no NC migration class. The route/stage UI is C6 (out of scope).

Acceptance criteria and verification are PLAIN bullets (not checkboxes); the column-0 `- [ ]` items are the deliverable steps.

## 1. Method enum + mechanism relations

- [x] Rename `DecisionStage.method` enum value `sign` → `signature` (default stays `manual`)
- [x] Add `votingRound` (→ VotingRound), `registeredBy` (→ Person), `signedDocument` (→ DigitalDocument) optional relations to `DecisionStage.x-openregister-relations`
- [x] Add the declarative required-mechanism-per-method validation note to `DecisionStage`

Acceptance criteria:
- GIVEN the register WHEN the DecisionStage `method` enum is inspected THEN it is `manual|vote|signature|chair-register|advice` with default `manual` and no `sign`
- GIVEN the DecisionStage relations WHEN inspected THEN `votingRound`/`registeredBy`/`signedDocument` are present, optional, and each describes the method that requires it
- GIVEN a `method=vote` stage with no `votingRound` WHEN evaluated against the note THEN it is flagged incomplete; GIVEN `method=advice`/`manual` THEN no mechanism is required

Verification:
- `spec_ref`: `openspec/changes/decision-methods/specs/decision-methods/spec.md#requirement-decision-method-enum-and-mechanism-relations`
- `spec_ref`: `openspec/changes/decision-methods/specs/decision-methods/spec.md#requirement-required-mechanism-relation-matches-the-method`
- `python3 -c` JSON read asserts the enum + relations on `DecisionStage`

## 2. VotingRound retarget

- [x] Retarget `VotingRound.x-openregister-relations` from the retired `motion` schema to `DecisionStage`

Acceptance criteria:
- GIVEN the VotingRound schema WHEN relations are inspected THEN it relates to `DecisionStage` (not `motion`)
- GIVEN vote sub-variants WHEN modelled THEN secret/personal/general are carried by `VotingRound.isSecret`/`votingMethod`, not by new `method` values

Verification:
- `spec_ref`: `openspec/changes/decision-methods/specs/voting-system/spec.md#requirement-votinground-resolves-a-decisionstage-of-method-vote`
- `python3 -c` JSON read asserts `VotingRound` relation target is `DecisionStage`

## 3. Declarative outcome derivation

- [x] Add the `method=vote` outcome calculation to `DecisionStage.x-openregister-calculations` (map `VotingRound.result` → stage `outcome`)
- [x] Confirm chair-register/advice/manual outcomes are directly-set (no calculation), documented on the schema

Acceptance criteria:
- GIVEN a `method=vote` stage linked to a round with `result=adopted` WHEN loaded THEN stage `outcome` derives to `adopted`; `result=rejected`→`rejected`; `tied`→`rejected`; `invalid`→no outcome
- GIVEN `method=chair-register`/`advice`/`manual` WHEN resolved THEN `outcome` is set directly with no derivation

Verification:
- `spec_ref`: `openspec/changes/decision-methods/specs/decision-methods/spec.md#requirement-vote-method-outcome-is-derived-declaratively-from-the-votinground`
- `spec_ref`: `openspec/changes/decision-methods/specs/decision-methods/spec.md#requirement-chair-register-advice-and-manual-outcomes-are-set-directly`
- `python3 -c` JSON read asserts the calculation references `VotingRound.result`

## 4. eIDAS signature method wiring (code)

- [x] Touch `EIDASSignatureService` to resolve a `method=signature` DecisionStage on signing completion (link `signedDocument`, set `outcome=adopted` + `decidedAt`), replacing the `// TODO Cycle 2` note

Acceptance criteria:
- GIVEN a `method=signature` stage with a `signedDocument` WHEN eIDAS signing completes via `EIDASSignatureService` THEN the stage is resolved (`outcome=adopted`, `decidedAt` stamped) reusing `Minutes.signedBy`
- GIVEN any organisation mode WHEN a `method=signature` stage is created THEN the method is available with no board-* schema
- the `// TODO Cycle 2` note in `EIDASSignatureService` is removed/replaced

Verification:
- `spec_ref`: `openspec/changes/decision-methods/specs/decision-methods/spec.md#requirement-signature-method-resolves-a-stage-via-eidas-signing`
- `spec_ref`: `openspec/changes/decision-methods/specs/resolution-minutes/spec.md#requirement-minutes-signing-resolves-a-signature-method-stage`
- `php -l lib/Service/EIDASSignatureService.php`; `composer check:strict`

## 5. Seeds

- [x] Update the C4 route seeds so each method is exercised: advisory municipal stage → `advice`; gemeenteraad stage → `vote` + linked VotingRound (28-3-2, adopted); MT prep stage → `chair-register` + `registeredBy` chair; executive-board stage → `vote` + secret VotingRound (`isSecret=true`); RvC stage → `signature` (renamed) + `signedDocument` + completed-signing outcome
- [x] Add the supporting seeds: two VotingRound objects, one chair Person, one DigitalDocument (signed minutes)

Acceptance criteria:
- GIVEN the register is imported WHEN seeds materialise THEN all five methods (`manual`/`vote`/`signature`/`chair-register`/`advice`) are exercised across the municipal and corporate routes
- GIVEN the secret executive-board vote WHEN inspected THEN its VotingRound has `isSecret=true` (anonymous sub-variant via VotingRound config)
- GIVEN the gemeenteraad vote stage WHEN loaded THEN its `outcome` derives `adopted` from the linked round

Verification:
- `spec_ref`: `openspec/changes/decision-methods/specs/decision-methods/spec.md#requirement-vote-method-outcome-is-derived-declaratively-from-the-votinground`
- `occ openregister:import` then `python3 -c` JSON read asserts the seed methods + mechanism links

## 6. Version bump + validation

- [x] Bump register `info.version` + `DecisionStage`/`VotingRound` schema `version`
- [x] Run `openspec validate decision-methods --strict` and the relevant hydra gates (spec-coverage, notification-dialect) green

Acceptance criteria:
- GIVEN the change WHEN `openspec validate decision-methods --strict` runs THEN it passes
- GIVEN the touched service WHEN `composer check:strict` runs THEN PHPCS/PHPMD/Psalm/PHPStan pass

Verification:
- `openspec validate decision-methods --strict`
- `composer check:strict`
