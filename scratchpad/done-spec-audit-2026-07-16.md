# decidesk — inert seeds + done-spec semantic audit (2026-07-16)

Base: decidesk `development` = e1479515. OR verified READ-ONLY at origin/development (2d50c8b0c).
Hydra gates from a FRESH worktree off hydra origin/development `64aa367b` (includes #113 gate-6 FP fix, #114 gate-57).

## TASK 1 — 21 inert seeds

### Mechanism verification (OR origin/development, read-only)
- `Schema::ANNOTATION_VOCABULARY` (lib/Db/Schema.php:2094) contains **neither** `x-openregister-seeds`
  (plural) **nor** `x-openregister-seed` (singular). Confirms the audit: `setConfiguration()` drops the
  key (Schema.php:1940). Since R07 the drop is at least *logged* via `consumeDroppedAnnotationKeys()`.
- Seeds are therefore NOT a schema-level annotation at all. OR honours exactly two seed locations today,
  both in `ImportHandler`:
  1. **`x-openregister.seedData.objects`** (top level of the config doc), keyed by **schema slug** →
     `importSeedData()` (ImportHandler.php:3804, reads `$configData['x-openregister']['seedData']` at 3812,
     iterates `foreach ($seedData['objects'] as $schemaSlug => $objects)` at 3911).
  2. `components.objects` / top-level `objects` — a flat list of objects carrying `@self`
     (ImportHandler.php:2017), de-duped by uuid or register/schema/slug.
- **Wiring proven, not assumed**: `importSeedData()` IS called — ImportHandler.php:2318, inside
  `import()`, passing `configData: $data` (the full merged config). Guarded by `$configuration !== null`
  and wrapped in try/catch (per-entity resilience). Re-import on equal version still checks seedData
  (ImportHandler.php:3026-3034). So this is a live path, not another phantom.

### Chosen fix
Location 1 (`x-openregister.seedData.objects`) — it is keyed by schema slug, which maps 1:1 onto the
21 per-schema `x-openregister-seeds` blocks decidesk already had. Location 2 would require hand-writing
`@self` blocks per object.

- 20 blocks in `lib/Settings/decidesk_register.json` + 1 in `lib/Settings/register.d/43-process-config-v1.json` = **21**.
- Fragment merge verified: `SettingsService::deepMergeConfig()` unions assoc arrays by key and
  concatenates lists, so the fragment's `x-openregister.seedData` survives into the merged config
  handed to `importFromApp()`. (This mattered — a components-only merge would have re-created the phantom.)
- Schema keys are slugs (`process-template`, `governance-body`, …), not PascalCase — required by
  `importSeedData()`'s slug lookup.

## TASK 2 — done-spec semantic audit (93 spec files; 88 `done`)

### gate-6 ORIGINAL TRIO re-verify (decidesk is the origin corpus of this defect class)
- `isTransitionAllowed()` — **GENUINELY WIRED**. Real calls: MeetingService.php:176,
  DecisionLifecycleService.php:229, DecisionTransitionGuard.php:216.
- `requiresChairAuthorization()` — **GENUINELY WIRED**. Real calls: MeetingService.php:191,
  DecisionLifecycleService.php:133 and :245. MeetingService:191 is a real fail-closed guard
  (unresolved body or empty chair scope ⇒ deny).
- `validateQuorum()` — **LEGITIMATELY SUPERSEDED**, not dead. `QuorumService` was deleted in
  quorum-chain-3 (#164). Replacements are wired: `isQuorumRequired()` (MeetingService.php:207,
  DecisionLifecycleService.php:266), `checkQuorum()` (VotingService.php:413).
⇒ The 2026-04 findings are all resolved. Verdict: **fixed, not regressed.**
(Method note: my first grep used `grep -v "function X"` + `head`, which truncated the real call sites and
made the trio *look* orphaned. Only an invocation-shaped grep `->X(` gave the truth. Verify-first prevented
two manufactured findings.)

### Genuine findings
1. **Inert declaration — `ConsultationReaction.reactionPendingModeration`** (decidesk_register.json).
   Declares `"trigger": {"type": "create"}`. OR's canonical vocabulary is
   `NotificationAnnotationValidator::VALID_TRIGGERS = ['created','updated','transition','scheduled','threshold','calculatedChange']`
   — `create` is not a member. Every other decidesk notification correctly uses `created`.
   ⇒ dialect drift; the moderation notification can never fire. Same family as the lifecycle
   `initialState`-vs-`initial` drift.
2. **Orphaned capability — `QuorumVerificationService::computeQuorum()`**. DI-registered
   (Application.php:772) and covered by 5 green unit tests, but **zero production callers** —
   only tests call it. Textbook "green tests, dead feature".
3. **Orphaned write capability (gate-57)** — `DecisionNotificationService::notifyOnPublish()`
   (:64) and `NotificationPreferenceService::createPreference()` (:185): zero callers anywhere,
   not even tests. notifyOnPublish is plausibly superseded by the declarative
   `x-openregister-notifications` dialect (ADR-031) — supersession check pending.

### Clean (no findings) — reported truthfully
- **Fabricated pass: NONE.** Grepped lib/Service + lib/Lifecycle for `=> true` / `return true` near
  always / for now / TODO / placeholder / stub / temporary / assume / simplif — zero hits.
  decidesk has no shillinq-style `'segregation' => true` "always passes here".
- gate-6 orphan-auth: PASS. gate-3 stub-scan, gate-8 unsafe-auth-resolver, gate-9 semantic-auth,
  gate-17 redundant-controller, gate-56 register-handler-resolution: PASS.
- Annotation keys in use — `notifications, lifecycle, calculations, relations, aggregations,
  object-source` — are ALL in `ANNOTATION_VOCABULARY`. `seeds` was the only out-of-vocabulary key.
- Lifecycle dialect: Decision + DecisionStage use canonical `initial`/`states` — no drift.

### Tooling defect found (hydra)
`scripts/run-hydra-gates.sh:2803` — `[ -n "${HYDRA_GATE_PR_BODY}" ]` under `set -u` with the var
unset ⇒ "unbound variable", aborting the run **before gates 50-57**. Only fires when gate-49's log is
non-empty, so it silently truncates the suite exactly on the apps that have findings. First run reported
"8 failed" and never ran the orphan gates; with `HYDRA_GATE_PR_BODY=""` the real total is 12.
