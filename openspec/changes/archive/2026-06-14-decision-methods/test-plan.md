# Test Plan: decision-methods

## Scope

Verify each `DecisionStage.method` is wired to its resolution mechanism and the stage `outcome` is correctly derived (vote) or directly set (chair-register/advice/manual/signature). Most assertions are register-config (schema + seeds) checks; one is a code-path test for the eIDAS signature stage resolution. The route/stage UI is C6 — not tested here.

## Test Strategy

| Layer | Tool | What it covers |
|---|---|---|
| Schema config | `python3 -c` JSON read on `decidesk_register.json` | enum rename, mechanism relations, VotingRound retarget, vote-outcome calculation, validation note, version bump |
| Seed materialisation | `occ openregister:import` + JSON read | each method exercised; mechanism links present; secret-ballot sub-variant |
| Declarative derivation | OpenRegister object read | `method=vote` stage `outcome` derives from `VotingRound.result` |
| eIDAS code | PHPUnit | `EIDASSignatureService` resolves a `method=signature` stage on signing completion |
| Strict gates | `openspec validate --strict`, `composer check:strict` | spec validity + PHP quality on the touched service |

## Test Cases

### TC-1 — Method enum is coarse and renamed
- Assert `DecisionStage.method` enum == `[manual, vote, signature, chair-register, advice]`, default `manual`, no `sign`.

### TC-2 — Mechanism relations present and optional
- Assert `votingRound`/`registeredBy`/`signedDocument` exist on `DecisionStage.x-openregister-relations`, are optional, and each names the requiring method in its description.

### TC-3 — Required-mechanism integrity note
- Assert the validation note records `vote⇒votingRound`, `chair-register⇒registeredBy`, `signature⇒signedDocument`, and that `advice`/`manual` require none.

### TC-4 — VotingRound retargeted to DecisionStage
- Assert `VotingRound.x-openregister-relations` targets `DecisionStage` and no longer references the retired `motion` schema.

### TC-5 — Vote outcome derives from the round
- GIVEN a `method=vote` stage linked to a round `result=adopted` THEN stage `outcome=adopted`; `rejected`→`rejected`; `tied`→`rejected`; `invalid`→no outcome (assert the calculation expression maps `VotingRound.result`).

### TC-6 — Secret-ballot sub-variant via VotingRound config
- GIVEN the executive-board vote stage THEN its linked VotingRound `isSecret=true` and no new `method` enum value is used for the anonymous variant.

### TC-7 — Chair-register / advice / manual set outcome directly
- GIVEN `method=chair-register` THEN `registeredBy` set + `outcome`/`decidedAt` directly set, no round; GIVEN `method=advice` THEN `outcome ∈ {advised, deferred}` directly set.

### TC-8 — Signature stage resolution (code)
- PHPUnit: GIVEN a `method=signature` stage with a `signedDocument` WHEN signing completes via `EIDASSignatureService` THEN the stage is linked + `outcome=adopted` + `decidedAt` stamped; signatories read from `Minutes.signedBy`; assert the `// TODO Cycle 2` note is gone.

### TC-9 — Signature available regardless of mode
- Assert no board-* schema is required for `method=signature`; a stage in any mode can use it (config check + ADR-006 conformance).

### TC-10 — Seeds exercise all five methods
- After `occ openregister:import`, assert the municipal route covers `manual`/`advice`/`vote` and the corporate route covers `chair-register`/`vote`(secret)/`signature`, with mechanism links populated.

### TC-11 — Strict validation green
- `openspec validate decision-methods --strict` passes; `composer check:strict` passes on the touched `EIDASSignatureService`.

## Out of Scope

- Route/stage UI, per-stage resolve buttons, live vote projection (C6).
- New eIDAS transport/QSP behaviour (openconnector e-sign source — unchanged).
- Auto-advancing the parent Decision's lifecycle on stage resolution (C6).
