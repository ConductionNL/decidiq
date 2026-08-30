# Migration: decision-methods

> Decidesk is a thin client and owns **no** Nextcloud database tables (ADR-005, project architecture). There is no `lib/Migration/Version*.php` class. "Migration" here is a **declarative OpenRegister register migration** — a version bump of `lib/Settings/decidesk_register.json` applied via the register-sync path (SettingsService register import / `occ openregister:import`). The change is **additive** apart from one demo-data enum value rename (`method=sign` → `method=signature` on seeded DecisionStage objects); no production data exists and seeds re-upsert idempotently by `@self.slug`. The lone code element is a thin touch on `EIDASSignatureService` (no DB migration).

## Current State

- `DecisionStage` (from C4) has `method` enum `manual` | `vote` | `sign` | `chair-register` | `advice` (default `manual`) and relations `decision`, `assignedPerson`, `assignedBody`. No mechanism relations; the `method` value is a placeholder with nothing behind it.
- `VotingRound` relates to the **retired** `motion` schema (`x-openregister-relations.motion`); it is not linked to any `DecisionStage`.
- DecisionStage seeds use `method=sign` for the RvC ratifying stage and `method=manual` for the advisory/chair stages; no `VotingRound`/`registeredBy`/`signedDocument` links exist.
- `EIDASSignatureService` is retargeted onto minutes/decision but carries a `// TODO Cycle 2` note and does not resolve any DecisionStage.

## Target State

- `DecisionStage.method` enum value `sign` renamed to `signature` (enum: `manual` | `vote` | `signature` | `chair-register` | `advice`).
- `DecisionStage.x-openregister-relations` gains `votingRound` (→ VotingRound, many-to-one, optional), `registeredBy` (→ Person, many-to-one, optional), `signedDocument` (→ DigitalDocument, many-to-one, optional), each with a description noting which `method` requires it.
- `DecisionStage.x-openregister-calculations` gains a vote-outcome derivation mapping the linked `VotingRound.result` to the stage `outcome` for `method=vote`.
- A declarative validation note records the required-mechanism-per-method integrity rule.
- `VotingRound.x-openregister-relations` retargeted from `motion` to `DecisionStage` (the round links to the stage it resolves).
- Register `version` and the `DecisionStage` / `VotingRound` schema `version` bumped.
- Seeds updated: the advisory municipal stage → `method=advice`; the gemeenteraad stage → `method=vote` + `votingRound` to a new seeded VotingRound (28-3-2, `result=adopted`); the MT prep stage → `method=chair-register` + `registeredBy` to a seeded chair Person; the executive-board stage → `method=vote` + a new secret VotingRound (`isSecret=true`); the RvC stage → `method=signature` + `signedDocument` to a seeded DigitalDocument (and `outcome=adopted`/`decidedAt` representing completed signing).
- `EIDASSignatureService` resolves a `method=signature` DecisionStage on signing completion (replaces the `// TODO Cycle 2` note).

## Migration Class

```
Version: n/a — no Nextcloud DB migration class
Mechanism: declarative OpenRegister register import + one PHP service touch
File: lib/Settings/decidesk_register.json (version bump) + lib/Service/EIDASSignatureService.php
Applied by: SettingsService register import on app enable/update, or
            occ openregister:import (re-import) in dev
Key operations:
- Rename DecisionStage.method value sign -> signature
- Add votingRound / registeredBy / signedDocument relations to DecisionStage
- Add the vote-outcome calculation + validation note to DecisionStage
- Retarget VotingRound relation motion -> DecisionStage
- Upsert updated/new seeds (DecisionStage, VotingRound, Person chair, DigitalDocument)
- Wire EIDASSignatureService to resolve a method=signature stage
```

## Migration Steps

1. In `DecisionStage`, rename the `method` enum value `sign` → `signature` (default stays `manual`).
2. Add `votingRound`, `registeredBy`, `signedDocument` to `DecisionStage.x-openregister-relations` (optional, with method-requirement descriptions) and a validation note for the required-mechanism-per-method rule.
3. Add the `method=vote` outcome calculation to `DecisionStage.x-openregister-calculations` (map `VotingRound.result` → stage `outcome`).
4. Retarget `VotingRound.x-openregister-relations` from `motion` to `DecisionStage`.
5. Bump `info.version` and the `DecisionStage` + `VotingRound` schema `version` fields.
6. Update/add seeds: rename `stage-investering-acme-3-rat` to `method=signature` + add `signedDocument`; set the advisory + MT stages to `advice` / `chair-register`; add the two VotingRound seeds, the chair Person seed, and the DigitalDocument seed; link them.
7. Touch `EIDASSignatureService` to resolve a `method=signature` stage on signing completion.
8. Apply via the register-sync path (`occ openregister:import`); verify the enum rename, new relations, the vote-outcome derivation, and the seeded objects materialise.

## Rollback

Revert the `decidesk_register.json` diff and the `EIDASSignatureService` touch, then re-import. Seeded demo objects re-upsert to the C4 baseline (`method=sign`); no production data is affected.
