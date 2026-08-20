# decidesk — inert seeds + done-spec semantic audit (2026-07-16)

Base: decidesk `development` (task said `e1479515`; it had already moved to `17eac8b2` — merged before push).
OR verified READ-ONLY at `origin/development` (2d50c8b0c) **and** against the OR actually deployed in the
dev container (0.2.17-unstable.14) — they agree on the seed path. Hydra gates from a FRESH worktree off
hydra `origin/development` 64aa367b (incl. #113 gate-6 FP fix, #114 gate-57).

PRs: **#138** fix-inert-seeds · **#140** done-spec-fixes · **#141** archive — all merged.
Umbrella issue: **#139** "audit: decidesk done-spec verification".

---

## TASK 1 — 21 inert seeds → PLANTED

### The mechanism OR honours today (verified, not assumed)
- `Schema::ANNOTATION_VOCABULARY` (Schema.php:2094) contains **neither** `x-openregister-seeds` (plural)
  **nor** the singular `x-openregister-seed`. Seeds are **not a schema-level annotation at all** — the
  singular form other apps still carry is equally inert. `setConfiguration()` drops unknown keys
  (Schema.php:1940); since R07 the drop is logged, but a warning is not a failure, which is how 21
  dropped keys survived review.
- OR honours exactly two seed locations, both in `ImportHandler`:
  1. **`x-openregister.seedData.objects`** — top level, keyed by schema **slug** (read at :3812)
  2. `components.objects` / top-level `objects` — flat list, each object carrying `@self` (:2017)
- The importer is genuinely **wired, not an orphan**: `importSeedData()` called from `import()` at :2318.

### Fix
All 21 blocks (20 in `decidesk_register.json`, 1 in `register.d/43-process-config-v1.json`) relocated to
`x-openregister.seedData.objects`, keyed by slug, `@self` flattened to a `slug` property.
**Plus `info.version` 0.5.1 → 0.6.0** — `importFromJson()` early-returns (~:1601) when the version is
`<=` stored and the hash matches, **before** `importSeedData()` runs. Relocating without bumping would
have shipped a *second inert fix*. Not deduced — observed live.

### PLANTED-PROOF (live)
`process-template` (magic table `oc_openregister_table_18_1200`): **0 → 5** via the ordinary
`occ app:enable decidesk` upgrade path, no forced import, no manual key deletion:
`association-alv`, `association-board`, `corporate-board`, `municipal-council`, `operational-team`.
Log: `SeedData will be imported into register` + `Importing seed data objects`, no `Seed-data import failed`.
These 5 came from the **fragment**, which also proves `deepMergeConfig()` carries fragment seedData through.

### Two OpenRegister defects found BY live-verifying (filed → #139)
1. `importSeedData()` resolves `$configuration->getRegisters()[0]` and calls `find()` **unguarded** — a
   stale id throws, the outer catch swallows it, and **every** seed silently fails even when a valid
   register sits later in the list. Hit live: config 150 = `[2409,18]`, 2409 dangling, 18 valid, ignored.
2. `importFromJson()`'s early-return skips `importSeedData()` entirely, contradicting the comment at
   :3029 promising the version-equal path still "checks seedData".
Code-reading alone would have shipped the relocation and called it done; both defects only appeared
because the seed was actually counted.

---

## TASK 2 — done-spec semantic audit (93 spec files, 88 `done`)

### gate-6 ORIGINAL TRIO — re-verified (decidesk is the origin corpus)
| Method | 2026-04 | Now |
|---|---|---|
| `isTransitionAllowed()` | orphaned | **Genuinely wired** — MeetingService:176, DecisionLifecycleService:229, DecisionTransitionGuard:216 |
| `requiresChairAuthorization()` | orphaned | **Genuinely wired**, fail-closed — MeetingService:191, DecisionLifecycleService:133 & :245 |
| `validateQuorum()` | orphaned | **Legitimately superseded** — QuorumService deleted in #164; `isQuorumRequired()` / `checkQuorum()` wired |

**Verdict: fixed, not regressed.** gate-6 PASSes and the pass is real.
⚠️ Method note: my first grep (`grep -v "function X"` + `head`) truncated the real call sites and made the
trio *look* orphaned. Only `->X(` gave the truth. Verify-first prevented two manufactured findings in the
one app where a manufactured regression would have been most believable.

### Six shapes
| Shape | Result |
|---|---|
| Orphaned capability | **3** — `computeQuorum()` (DI + **5 green unit tests**, only tests call it), `notifyOnPublish()`, `createPreference()` → #139 |
| Phantom handler ref | **1** — `settings#load` (the re-import endpoint) has **no route**; also settings#index/create, preferences#get/set → #139 |
| Phantom-ticked tasks.md | none |
| **Fabricated pass** | **NONE** — zero hits across lib/Service + lib/Lifecycle |
| Inert declaration | **2 — both FIXED** (below) |
| REQ-never-implemented | none beyond the orphans |

### Fixed in #140
1. **`ConsultationReaction.reactionPendingModeration` could never fire** — `trigger.type: "create"`; OR's
   `VALID_TRIGGERS` = created|updated|transition|scheduled|threshold|calculatedChange. Every other decidesk
   notification already used `created`. One character of drift; same family as `initialState`-vs-`initial`.
2. **BoardEvaluation / EvaluationResponse relations dead twice over** — declared only in the retired
   `x-openregister-relations` block (ADR-062 rule 7, retired 2026-07-08), **and** the properties were never
   materialised, so the reference had nowhere to live. Migrated to canonical property-level `$ref`.

Tests were **documenting** the drift, not blocking it: `testRelationsAreConfigured` /
`testEngagementRecordSchemaExists` asserted the retired dialect → red when core schemas migrated, then just
stayed red. Repointed (incl. to-many `items.$ref`, e.g. `Decision.route`) + 2 new guards, **bad-path proven**.
Honest correction: my repointed test's first version flagged `Decision.route` as a defect — it is a
legitimate to-many relation. The audit tooling produced a false positive that looked exactly like a real finding.

### Numbers (real, by test NAME vs a pristine worktree — not exit codes)
- Baseline (clean origin/development): **774 tests, 2 failures** (`testRelationsAreConfigured`, `testEngagementRecordSchemaExists`)
- After #138: 775 / same 2 (no regression) · After #140: **776 / 0 failures**, 30 skipped
- Gates: 12 failed → **11**; gate-54 relation-dialect **2 findings → PASS**; gate-6 PASS throughout.

### Hydra tooling defect (→ #139)
`run-hydra-gates.sh:2803` — `[ -n "${HYDRA_GATE_PR_BODY}" ]` under `set -u` ⇒ unbound variable, aborting
**before gates 50-57**. Fires only when gate-49's log is non-empty, so it silently truncates the suite
**exactly on the apps that have findings** — skipping the orphan gates. First run said "8 failed" and never
ran 50-57; with the var set, the real total is 12. Fix: `${HYDRA_GATE_PR_BODY:-}`.

### Coverage caveat (honest)
The 88 `done` specs were audited by **defect-shape sweep** (gates + targeted greps + supersession checks),
NOT by reading all 88 end-to-end or driving each through the UI. Only the seed path was driven live. A spec
whose capability is wired and whose dialect is canonical passes this audit while still being wrong about its
behaviour. Verdict = "no evidence of the six shapes", not "all 88 verified live".

### Verdict on 88 `done`
- **Live / no evidence against**: 84
- **Partial-dead → downgraded, filed #139**: 3 (computeQuorum, notifyOnPublish, createPreference — zero prod callers)
- **Was dead, now fixed**: 2 declarations (notification trigger; BoardEvaluation/EvaluationResponse relations)
- **Unsure**: 1 (`notifyOnPublish` may be superseded by the declarative dialect — unconfirmed; reported as unsure rather than quietly deleted)

### Environment note
Dev instance: seeds are now planted in register 18; config 150's dangling register id was repaired
(`[2409,18]` → `[18]`) — that is a repair of pre-existing dirt, left in place deliberately. The deployed
gitignored copy under `openregister/custom_apps/decidesk` was restored to match the checkout. The main
checkout was never written to.
