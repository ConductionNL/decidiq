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
| **LegalRemedyResolver** | A caller only. `stamp()` at the point a decision reaches `decided`, and `assertPublishable()` in `PublicationController::publish` / `DecisionController::publish`, both of which exist. `Decision.legalRemedyClause` and `DecisionTemplate.legalRemedies` are already in the register. No data gap, no new surface. |
| **DecisionWithdrawalService** | A route and a controller method (`decision#withdraw`), plus one UI action on the decision detail page. The four `withdrawn*` properties are already on `Decision`. No data gap. |
| **AdmissibilityVerdictService** | `stepKind` on `DecisionStage` (shared prerequisite), a branch in `ApprovalRouteService::record()` for an intake step, a write of `ontvankelijkheid*` onto the Decision, and the read seam dossiq consumes, which does not exist in any form today. |
| **ApprovalThresholdCalculator** | `thresholdKind`/`thresholdValue` on `DecisionStage` (shared prerequisite), then `completeAndAdvance()` has to ask `outcome()` instead of advancing on the first completing action. That is a real change to how every route advances, so it needs its own change and its own e2e, not a wiring line. |
| **ApprovalBasisWatcher** | `approvalBasis` on `DecisionStage` (shared prerequisite), an OpenRegister object-updated listener to notice a subject changing at all (none exists), and a notifier for task 3.3, which was never built. Three missing pieces, only one of them small. |
| **OpenApprovalGuard** | Two halves. `assertCanAdvance()` needs `appendAction()` to write a terminal `state`, because the schema defaults `state` to `open` and nothing writes it, so every recorded action reads as blocking; plus a backfill for stored rows. `taskListFor()` needs `assignee` and `dueAt` to be written by something (nothing writes either) and a surface to render the list. |

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
