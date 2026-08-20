# Design: done-spec-fixes

## Context
88 `done` specs, never semantically audited. The audit ran the full hydra gate suite from a fresh
worktree off hydra `origin/development` (64aa367b, including the #113 gate-6 semantic-FP fix and #114
gate-57) and then hand-triaged every finding verify-first.

## The six shapes, and what decidesk actually had
| Shape | Result |
|---|---|
| Orphaned capability | **3 found** — `computeQuorum()`, `notifyOnPublish()`, `createPreference()` (residuals; product decision needed) |
| Phantom handler ref | **1 found** — `settings#load` has no route (gate-14); also `settings#index`, `settings#create`, `preferences#get/setPreference` |
| Phantom-ticked tasks.md | none found |
| **Fabricated pass** | **NONE** — see below |
| Inert declaration | **2 found, both fixed here** (notification trigger drift; retired relation dialect) |
| REQ-never-implemented | none found beyond the orphans above |

## Fabricated pass: a negative result, stated plainly
Grepped `lib/Service/` and `lib/Lifecycle/` for `=> true` / `return true` within three lines of
*always*, *for now*, *TODO*, *placeholder*, *stub*, *temporary*, *assume*, *simplif*. **Zero hits.**
decidesk has no shillinq-style `'segregation' => true` "always passes here". The authorization surface
that gate-6 was built from is, on this evidence, honest: `MeetingService:191` denies on an unresolved
body or an empty chair scope rather than waving the check through.

This is reported as a finding in its own right. An audit that only reports defects teaches that
absence of a report means absence of a look.

## Seed Data
No seed data is added or changed. The `x-openregister.seedData.objects` block landed in
`fix-inert-seeds` (PR #138) and is untouched here; the relation properties added to `BoardEvaluation`
and `EvaluationResponse` are schema shape, not seed content. The existing BoardEvaluation and
EvaluationResponse seed objects carry no `governanceBody` / `template` / `boardEvaluation` values, so
materialising these properties changes no planted object — the properties simply become expressible.

## ADR-031
ADR-031 holds that an app declares intent in its register and OpenRegister's engines consume it.
The corollary this change enforces: **a declarative dialect is only real if some engine reads that
exact key with that exact vocabulary.** Both defects fixed here satisfied the *letter* of ADR-031 —
declarative, in the register, no imperative workaround — while being read by nothing:

- `trigger.type: "create"` is in the right key, in the right block, with a value no dispatch branch
  matches.
- `x-openregister-relations` was the right dialect until 2026-07-08 and is now read by nobody.

So ADR-031 compliance cannot be established by inspection of the app alone; it is a claim about the
engine, and it expires. The tests added here pin decidesk's declarations to OpenRegister's actual
vocabularies (`VALID_TRIGGERS`, ADR-062 rule 7) so drift fails loudly in CI instead of silently in
production.

## Relation dialect: canonical shape
Canonical (ADR-062 rule 7) is a property-level `$ref`:
- **to-one**: `{"type":"string","format":"uuid","$ref":"GovernanceBody"}`
- **to-many**: `{"type":"array","items":{"type":"string","format":"uuid","$ref":"DecisionStage"}}`

`Decision.route` is to-many, so the `$ref` lives under `items`. The first version of the repointed
test asserted a property-level `$ref` only and flagged `Decision.route` as a defect — it is not one.
The test now accepts either shape. Worth recording: the *audit tooling* produced a false positive that
looked exactly like a real finding, which is the failure mode this whole exercise exists to catch.

## Version bump
`info.version` 0.6.0 → 0.6.1. `ImportHandler::importFromJson()` early-returns when the computed
version is not newer than the stored one, before the config is applied — so a corrected register with
an unbumped version is itself inert. This is the trap `fix-inert-seeds` fell into and proved live; not
repeating it here is a direct application of that lesson.

## Alternatives considered
- **Delete the three orphaned capabilities.** Rejected: zero callers proves nothing invokes them, not
  that nobody wants them. `computeQuorum()` in particular is a coherent, tested implementation that
  looks like a wiring omission rather than dead weight. Deleting on that evidence would trade a false
  `done` for a false `resolved`.
- **Wire them.** Rejected for this change: each needs a product decision about where it belongs, which
  is not an audit's call to make.
- **Leave the two red tests alone as "pre-existing".** Rejected per the standing rule to fix
  pre-existing issues on contact — and because they were red *about this change's exact subject*.
