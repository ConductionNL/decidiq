# What each of the six would need to be reachable

Written 2026-09-18 after the sweep found that every service class this change
added is named by exactly two files, its own and its own unit test. This is the
scope, not the build. Nothing here has been implemented.

## The prerequisite all of them share, and it is not the `state` field

`DecisionStage` carries **none** of `thresholdKind`, `thresholdValue`,
`approvalBasis` or `stepKind`. Those four live only on the `ApprovalRoute`
step, which is the template, and `ApprovalRouteStepMapper::silenceFields()`
copies exactly three keys onto a stage (`actorRule`, `actorRuleSubject`,
`onSilence`) plus `askSubstituteAfter` and `dueAt`.

So a running stage cannot carry its own threshold, its approval basis or its
step kind. Three of the six below are unbuildable until `DecisionStage` gains
those properties and the mapper copies them. That is one register fragment and
one loop, and it is the first thing to do whatever else is chosen.

## The six, one line each

| Class | What it needs to be reachable |
|---|---|
| **LegalRemedyResolver** | ✅ Done 2026-09-19, and "a caller only" was wrong. `Decision.legalRemedyClause` and `DecisionTemplate.legalRemedies` were DECLARED in `components.schemas`, which this row read as "already in the register". `decision-template` was not in `components.registers.decidiq.schemas`, so the register carried no such schema and `typeOf()` threw on every publish. Three things were needed, not one: attach the schema, call the resolver, and widen the publication allow-list so the clause reaches the reader. |
| **DecisionWithdrawalService** | ✅ Done (#1380). `POST /api/decisions/{decisionId}/withdraw` (`decision#withdraw`, administrators only) → `DecisionWithdrawFlow::withdraw()`, which checks the declared `* → withdrawn` edge (`DecisionTransitionGuard::WITHDRAWABLE_STATES`), applies the chair gate when the body's policy restricts it, calls this class, persists `lifecycle=withdrawn` plus the four `withdrawn*` properties and appends the audit entry. The Lifecycle widget on the decision page offers it. A decision still in flight can be withdrawn too (the lifecycle declares `draft → withdrawn`), recorded with no outcome rather than refused. |
| **AdmissibilityVerdictService** | ✅ Engine half done 2026-11-17: `record()` routes an act on an intake stage through IntakeVerdictRecorder (closes with `ended-at-intake`, writes `ontvankelijkheid*` on a decision); the dossiq read seam is still open. Was: `stepKind` on `DecisionStage` (shared prerequisite), a branch in `ApprovalRouteService::record()` for an intake step, a write of `ontvankelijkheid*` onto the Decision, and the read seam dossiq consumes, which does not exist in any form today. |
| **ApprovalThresholdCalculator** | ✅ Engine half done 2026-11-23: StepThresholdSettler settles a `count` or `share` step after each completion and caches the result with its inputs (task 2.2); `all` keeps today's walk, so no stored route changes when it advances. Since the same day StepThresholdChangeListener recomputes a step when its threshold changes and records a `threshold-changed` action (task 2.3). Was: `thresholdKind`/`thresholdValue` on `DecisionStage` (shared prerequisite), then `completeAndAdvance()` has to ask `outcome()` instead of advancing on the first completing action. That is a real change to how every route advances, so it needs its own change and its own e2e, not a wiring line. |
| **ApprovalBasisWatcher** | ✅ Done 2026-11-21: `ApprovalBasisChangeListener` on every object update, `ApprovalStalenessService` writes the withdrawals and rewinds the route, `ApprovalWithdrawalNotifier` tells the actors (tasks 3.2, 3.3). Was: `approvalBasis` on `DecisionStage` (shared prerequisite), an OpenRegister object-updated listener to notice a subject changing at all (none exists), and a notifier for task 3.3, which was never built. Three missing pieces, only one of them small. |
| **OpenApprovalGuard** | ✅ Blocking half done 2026-11-16: `appendAction()` writes a terminal `state`, `RepairApprovalActionStates` backfills stored rows, and `record()` refuses to close a step on somebody else's open mandatory item. `taskListFor()` still open. Was: Two halves. `assertCanAdvance()` needs `appendAction()` to write a terminal `state`, because the schema defaults `state` to `open` and nothing writes it, so every recorded action reads as blocking; plus a backfill for stored rows. `taskListFor()` needs `assignee` and `dueAt` to be written by something (nothing writes either) and a surface to render the list. |

## What is worth building, in what order

1. **The shared prerequisite.** `DecisionStage` gains the four step-config
   properties and the mapper copies them. Cheap, unblocks three of the six, and
   is worth doing even if nothing else on this list is.
2. **LegalRemedyResolver.** The best value per line here: two call sites that
   already exist, no data gap, no new surface, and a real consequence. A
   decision published today tells nobody how to contest it, and task 7.3 claimed
   that was refused.
3. **DecisionWithdrawalService.** One route, one controller method, one button.
   Self-contained, and the schema is ready.
4. **OpenApprovalGuard, blocking half only.** Do the `state` write and the
   backfill first, as its own change, because the migration is the risky part
   and it should not ride along with a guard.

## What is genuinely premature

- **ApprovalThresholdCalculator.** Not because it is wrong, but because wiring
  it changes when *every* route advances. That deserves a change with its own
  proposal and e2e, not a line added to a sweep.
- **ApprovalBasisWatcher.** Needs a change-observation seam the app does not
  have. Building the listener is a bigger decision than building the watcher was.
- **`taskListFor()`.** Nothing writes `assignee` or `dueAt`, so it can only
  return an empty list. A task surface fed by a field nobody writes is a surface
  that will look broken rather than empty.
- **AdmissibilityVerdictService's read seam.** The dossiq half is a cross-app
  contract, and 11.1 already parks the consumer side with that lane.

## What was deliberately not done

`appendAction()` still does not write `state`, and there is no backfill. With no
production reader of that field anywhere, fixing the write would be data work in
service of code nobody calls. It belongs to whichever change makes
`OpenApprovalGuard` reachable.
