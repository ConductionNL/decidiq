# Proposal: Decision methods (how a stage reaches its outcome)

## Summary

C4 (`decision-route-and-stages`) made each step of a Decision's route a first-class `DecisionStage` carrying a `method` enum **placeholder** (`manual` / `vote` / `sign` / `chair-register` / `advice`) but no machinery behind it. This change (C5) makes each method **real**: it wires every `DecisionStage` to the resolution mechanism named by its `method` and derives the stage's `outcome` from that mechanism. A `vote` stage is resolved by a `VotingRound` (outcome derives from `VotingRound.result`); a `chair-register` stage records the outcome directly with a `registeredBy` person; a `signature` stage (renamed from `sign`) is resolved by one or more eIDAS signatures on a signed document; an `advice` stage produces a non-binding advisory outcome; `manual` stays the default fallback (outcome set directly). This formalises ADR-005's decision *methods* and ADR-006's "eIDAS signing is a decision method available to ANY decision regardless of mode".

## Motivation

The product vision describes five concrete ways a decision is reached — "personal vote, anonymous vote, general vote, chair registers the outcome, actual signing". C4 named these as an enum but left them inert: a stage can say `method=vote` yet has no link to the `VotingRound` that actually tallied the vote, and nothing derives the stage's outcome from that round. A secretary tracking a routed decision cannot answer "this stage was decided by a 28-3-2 vote" or "this resolution was ratified by the chair's and secretary's eIDAS signatures" because the mechanism is not connected to the stage. ADR-005's target diagram lists `method ∈ vote | secret-vote | chair-registers | sign | advice` as the resolution layer on the unified Decision; ADR-006 promotes eIDAS signing from a corporate-only feature to a decision method on any decision. C3 already retargeted `EIDASSignatureService` onto minutes/decision and left a `// TODO Cycle 2` note pointing at exactly this change. Now is the moment because C4 has just shipped the `DecisionStage` to hang the mechanism on, and the existing `VotingRound`/`Vote`/`Minutes`/`DigitalDocument` schemas already model the mechanisms — they only need wiring to the stage.

## Affected Projects

- [x] Project: `decidesk` — DecisionStage gains mechanism relations (`votingRound`, `registeredBy`, `signedDocument`) + declarative outcome derivation for `method=vote`; the `method` enum value `sign` is renamed to `signature`; `VotingRound` is retargeted from the retired `motion` schema onto `DecisionStage`; seeds exercise each method; a thin code touch on `EIDASSignatureService` resolves a `method=signature` stage (replaces the `// TODO Cycle 2` note).

## Scope

### In Scope

- **Mechanism relations on DecisionStage**: `votingRound` (→ VotingRound, optional, for `method=vote`), `registeredBy` (→ Person, optional, for `method=chair-register`), `signedDocument` (→ DigitalDocument, optional, for `method=signature`). Declarative validation note: the mechanism relation required by the stage's `method` must be present.
- **Method enum rename** `sign` → `signature` on `DecisionStage.method` (keep the coarse enum `manual` / `vote` / `signature` / `chair-register` / `advice`).
- **VotingRound retarget**: change `VotingRound`'s relation from the retired `motion` schema to `DecisionStage` (`votingRound` is the DecisionStage side; the inverse links a VotingRound to the stage it resolves).
- **Declarative outcome derivation**: for `method=vote`, the stage `outcome` derives from the linked `VotingRound.result` via `x-openregister-calculations` on DecisionStage; for `chair-register` / `advice` / `manual`, `outcome` is set directly.
- **Seeds**: extend the C4 route seeds so stages exercise different methods — a gemeenteraad stage = `vote` (linked VotingRound), an MT/chair stage = `chair-register` (registeredBy Person), the RvC stage = `signature` (signedDocument), a raadscommissie stage = `advice`.
- **eIDAS code touch**: a thin addition on `EIDASSignatureService` to resolve a `DecisionStage` of `method=signature` (set its `outcome`/`decidedAt` and link the `signedDocument`), replacing the `// TODO Cycle 2` note with the actual stage wiring.

### Out of Scope

- **The route/stage UI** (per-stage "open vote" / "request signatures" / "register outcome" buttons, the live vote projection panel) — owned by the C6 UI change.
- **Vote sub-variants as new enum values** — anonymous/secret/personal/general are expressed via the EXISTING `VotingRound.isSecret` + `VotingRound.votingMethod` fields, NOT by expanding `DecisionStage.method` (see design D1).
- **Auto-advancing the parent Decision's own lifecycle** when a decisive stage decides — that stays the existing guarded transition map; a method resolution MAY nudge it later (C6), but C5 only resolves the *stage*.
- **New eIDAS transport/QSP work** — `EIDASSignatureService` already delegates to openconnector's e-sign source; C5 only adds the stage-resolution touchpoint.
- **A standalone signature record schema** — signatures are modelled via the existing `Minutes.signedBy` + a `signedDocument` (DigitalDocument) reference rather than a new schema (see design D3).

## Approach

Register configuration first (ADR-031): add the three mechanism relations to `DecisionStage`, rename `method` value `sign`→`signature`, retarget `VotingRound` onto `DecisionStage`, add the declarative vote-outcome calculation, and update the C4 seeds so each method is exercised. Then one focused code touch: `EIDASSignatureService` learns to resolve a `method=signature` `DecisionStage` (link the signed document, set the stage outcome/decidedAt) — replacing the existing `// TODO Cycle 2` note. No Vue, no NC migration class; all data changes are additive (a method-enum value rename plus new optional relations).

## New Dependencies

None. Reuses the existing `VotingRound`, `Vote`, `Minutes`, `DigitalDocument` schemas and the existing `EIDASSignatureService` / openconnector e-sign integration.

## Impact

- **Schemas**: `DecisionStage` gains `votingRound` / `registeredBy` / `signedDocument` relations + a `method=vote` outcome calculation; `method` enum value `sign`→`signature`; `VotingRound` relation retargeted `motion`→`DecisionStage`. No properties removed; new relations are optional. Version bump.
- **Code**: one method touched on `EIDASSignatureService` (stage resolution) — this makes the change `kind: code` (see Mixed-spec rationale in design.md).
- **Specs**: new `decision-methods` capability spec; deltas on `decision-route`, `voting-system`, `vote-casting`, and `resolution-minutes` (signature method ↔ Minutes signing).
- **Data**: existing DecisionStage seeds with `method=sign` are updated to `method=signature`; this is a re-seed-friendly demo-data rename (no production data).
- **Downstream**: the C6 UI renders each stage's mechanism and exposes the resolve actions; nothing else depends on C5.

## Cross-Project Dependencies

None hard. `EIDASSignatureService` calls openconnector's e-sign source when present and falls back to the dormant `LogEIDASSignatureService` when absent — both paths already exist; C5 does not add a new openconnector dependency.

## Risks

- **Method-enum rename touches seeded data** — mitigated: only demo seeds use `method=sign`; the migration renames them in the same change and the rename is additive at the enum level (old objects re-seed).
- **Outcome-derivation drift** — mitigated: the vote outcome is a single declarative calculation on DecisionStage (one source of truth), not duplicated in Service code.
- **eIDAS code scope creep** — mitigated: the code touch is scoped to one stage-resolution method; transport/QSP stays in openconnector and is untouched.

## Rollback Plan

Revert the register-config diff (relations, enum rename, VotingRound retarget, calculation, seeds) in `lib/Settings/decidesk_register.json` and revert the single `EIDASSignatureService` method touch, then re-import the register. No NC migration class to roll back; demo data re-seeds to the C4 baseline.
