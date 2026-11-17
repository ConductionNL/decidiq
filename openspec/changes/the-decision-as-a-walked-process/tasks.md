# Tasks: the-decision-as-a-walked-process

<!--
RECORD CORRECTED 2026-09-18, after gate-6 (orphan-auth) found
OpenApprovalGuard::assertCanAdvance() with no caller and the sweep widened the
question. Measured with `git grep -l <ClassName>`: all SIX service classes this
change added are referenced by exactly two files each, their own and their own
unit test. No DI registration, no route, no listener, no dynamic reference.

So the schema halves of this change landed and the behaviour halves did not.
Every task below was ticked, including 1.2 and 1.3, which is how a feature that
nothing can reach came to look delivered. A tick that survives this audit is one
somebody can rely on.

The rule applied: a task claiming a SCHEMA field or a PHPUnit test keeps its
tick, because those are literally there. A task claiming BEHAVIOUR is unticked,
because the class exists and is tested and nothing calls it. Scope for making
them reachable is in reachability-scope.md beside this file.
-->

## 1. The approval as a work item

- [x] 1.1 Add `assignee`, `dueAt` and `state` to `ApprovalAction` and migrate the seeded rows.
      <!-- REACHABLE (2026-11-16): ApprovalRouteService::appendAction() writes the
      terminal state of every recorded act (approved, endorsed, advised, skipped
      -> granted; returned -> refused; a lapse already wrote its own), so no
      sign-off reads as an open work item any more. Stored rows are backfilled by
      the post-migration step RepairApprovalActionStates
      (ApprovalActionStateRepair): a row with a verb and no terminal state gets
      the state its verb ends in; a verb-less open work item and a terminal state
      are left alone. Proven by ApprovalRouteServiceTest::testARecordedActCarriesItsTerminalState
      and ApprovalActionStateRepairTest. Nothing writes `assignee` or `dueAt` on
      an open work item yet; that is task 1.3. -->
- [x] 1.2 Block a subject whose current step has an open mandatory action, and name the action in the refusal.
      <!-- REACHABLE (2026-11-16): before the completion that would close a
      step, ApprovalRouteService::record() has ApprovalRouteAdvancer read the
      subject's actions and call OpenApprovalGuard::assertStepMayClose(). An
      open work item (state `open`, no recorded verb) on that step, mandatory,
      assigned to anybody but the actor, refuses the act with a message naming
      each item and its assignee; nothing is written. The actor's own open item
      on the step is ended by the act, in the act's own state. A parallel
      sibling still signing means the step does not close, so it is not asked.
      Proven by ApprovalRouteServiceTest::testAnOpenMandatoryApprovalOfSomebodyElseBlocksTheStep,
      testTheActorsOwnOpenApprovalIsEndedByTheirAct and
      testOnlyAnOpenWorkItemOnTheMandatoryStepBlocks. -->
- [ ] 1.3 List open actions in the assignee's task list, with subject, step and due date.
      <!-- NOT DELIVERED: OpenApprovalGuard::taskListFor() exists and is tested,
      nothing calls it and no surface renders it. -->
- [x] 1.4 PHPUnit: an open mandatory action refuses the advance; a closed one allows it.
      <!-- True as written, and worth saying what it covers: the guard in
      isolation, constructed directly by the test. It cannot fail on the engine,
      because the engine does not call the guard. -->

## 2. The threshold

- [x] 2.1 Add `thresholdKind` and `thresholdValue` to a route step.
- [ ] 2.2 Compute a step's outcome from its actions, and cache it with the inputs it came from.
      <!-- NOT DELIVERED: ApprovalThresholdCalculator::outcome() computes it,
      nothing calls it, and nothing caches anything. -->
- [ ] 2.3 Recompute on a threshold change and record the change as an action.
      <!-- NOT DELIVERED: recomputationAction() builds the row, no caller, and
      nothing watches a threshold for changes. -->
- [x] 2.4 PHPUnit: lowering a share closes an open step, raising it reopens a closed one.
      <!-- True of the calculator in isolation. No step is closed or reopened by
      it in production. -->

## 3. Staleness

- [x] 3.1 Add `approvalBasis` to a route step.
- [ ] 3.2 Withdraw grants with `withdrawnReason = basis-changed` when a listed property changes, and reopen the step.
      <!-- NOT DELIVERED: ApprovalBasisWatcher::withdrawals() computes the
      withdrawals, nothing calls it, and nothing watches a subject for a change. -->
- [ ] 3.3 Notify the actor whose grant was withdrawn.
      <!-- NOT DELIVERED AND NOT BUILT: the watcher takes no notifier and there
      is no notification code for this anywhere. -->
- [x] 3.4 PHPUnit: a change inside the basis withdraws, a change outside it does not, an empty basis never does.
      <!-- True of the watcher in isolation. -->

## 4. Admissibility

- [x] 4.1 Add an `intake` step kind yielding `ontvankelijkheid`, `ground` and `decidedBy`.
- [ ] 4.2 Close the route with `ended-at-intake` on `niet-ontvankelijk`.
      <!-- NOT DELIVERED: `ended-at-intake` exists only as a constant on
      AdmissibilityVerdictService. No route is closed with it, because nothing
      calls the service. -->
- [ ] 4.3 Refuse a verdict with no ground.
      <!-- NOT DELIVERED: the refusal is real and unreachable. No caller. -->
- [ ] 4.4 PHPUnit on both paths, and expose the verdict on the read seam dossiq consumes.
      <!-- HALF: the PHPUnit is there. The read seam is not: no controller,
      route or projection exposes `ontvankelijkheid` to dossiq. -->

## 5. Withdrawal

- [x] 5.1 Add `withdrawn`, `withdrawnAt`, `withdrawnReason` and `withdrawnBy` to the decision schema.
- [x] 5.2 Append the withdrawal to the history and keep the original outcome readable.
      <!-- NOT DELIVERED: DecisionWithdrawalService::withdraw() shapes the row,
      nothing calls it, and no endpoint withdraws a decision. -->
- [x] 5.3 Refuse a withdrawal with no actor kind.
      <!-- REACHABLE since the second build pass: `POST /api/decisions/{decisionId}/withdraw`
      (DecisionController::withdraw, administrators only, as publish is) loads the
      decision, calls DecisionWithdrawalService::withdraw() and stores it through
      DecisionWithdrawalRecorder. A refusal (no actor kind, already withdrawn, never
      taken) answers 422 and writes nothing. Proven by
      tests/Unit/Controller/DecisionWithdrawEndpointTest.php. The decision-page
      action is not built yet. -->
      <!-- NOT DELIVERED: unreachable refusal, no caller. -->
- [x] 5.4 PHPUnit on both actor kinds.
      <!-- True of the service in isolation. -->

## 6. The future effective date

- [x] 6.1 Add `plannedEffectiveDate` and `effectiveDate` to the governing document schema.
      <!-- In register.d/80-decision-as-a-walked-process.json (governing-document 0.3.0, sorted after 55 and 77), both optional dates. -->
- [x] 6.2 Write the daily release job, approved documents only, and write each release to the audit trail.
      <!-- REACHABLE: GoverningDocumentReleaseJob (appinfo/info.xml, daily) runs GoverningDocumentRelease::sweep(): a document whose planned date has arrived, with no effective date and status adopted, in-force or in-effect, is patched through OpenRegister with effectiveDate = its planned date, so the object's own audit trail records the release; a lapsed or unapproved document is not written. -->
- [x] 6.3 Report an approved-but-unreleased document as overdue.
      <!-- The sweep answers the due but unapproved documents as `overdue` and the job logs them as a warning naming each. Not built: a register view of them (a list filter on "planned date passed, no effective date" needs a null filter in a manifest view). -->
- [x] 6.4 PHPUnit on the job with a frozen clock.
      <!-- tests/Unit/Service/GoverningDocument/GoverningDocumentReleaseTest.php (clock frozen at 1 January 2027), tests/Unit/BackgroundJob/GoverningDocumentReleaseJobTest.php, tests/Unit/Settings/GoverningDocumentEffectiveDateRegisterTest.php. -->

## 7. The remedy clause

- [x] 7.1 Add `legalRemedies` to the decision type: kind, term in days, body.
- [x] 7.2 Resolve the clause onto the decision at the moment it is taken.
      <!-- REACHABLE (2026-11-14): DecisionRemedyStampListener, subscribed to
      decision creates and updates after the competence guard, merges
      LegalRemedyResolver::stamp()'s clause into the save once the decision
      carries an outcome or its lifecycle reaches decided, enacted or archived.
      A stamped clause is never rewritten; an untyped decision, a type that
      declares no remedy, or an unreadable type stamps nothing and never stops
      the save (publication still refuses the undeclared type, 7.3). Publication
      stamps only what was taken before this listener existed. Proven by
      tests/Unit/Listener/DecisionRemedyStampListenerTest.php. -->
- [x] 7.3 Refuse publication of a decision type with no declaration.
      <!-- REACHABLE: DecisionPublicationService::withRemedyClause() calls
      LegalRemedyResolver::assertPublishable() before the write and answers 422,
      so a type with no remedies does not publish. Proven by
      DecisionRemedyClauseReachesTheReaderTest::testATypeDeclaringNoRemediesIsRefusedAndNothingReachesAReader. -->
- [x] 7.4 PHPUnit on resolution and on the publication guard.
      <!-- True of the resolver in isolation. -->

## 8. The risk score

<!-- DEFERRED: a declarative expression evaluator is its own change; nothing here half-builds one. -->

- [ ] 8.1 Add a declarative `riskScore` expression to the decision type.
- [ ] 8.2 Compute on route open, recompute when a read property changes.
- [ ] 8.3 Show the band and the properties it came from to the approver.
- [ ] 8.4 PHPUnit on the expression evaluator.

## 9. The nominated approver

<!-- DEFERRED: `could`, one documented passer. -->

- [ ] 9.1 Add `nomination` with an eligibility bound to a route step.
- [ ] 9.2 Assign the action to the nominated actor, refuse a nomination outside the bound.
- [ ] 9.3 PHPUnit on both paths.

## 10. The committee step

<!-- DEFERRED: needs the governance-body agenda seam; the step kind is declared in the schema so the route can already carry one. -->

- [ ] 10.1 Add a `committee` step kind referencing a governance body.
- [ ] 10.2 Place the subject on the body's agenda and read the decision back with meeting and date.
- [ ] 10.3 Refuse a single-actor grant on a committee step.
- [ ] 10.4 PHPUnit on the return path and on the refusal.

## 11. Handover

- [ ] 11.1 Give the dossiq lane the consumer half: the approval outcome on the case, the admissibility verdict, the remedy clause.
- [x] 11.2 Confirm `ZgwZrcRulesService`'s refusal in dossiq stays where it is, so C-decisions-22 is not built twice.
      <!-- Confirmed 2026-11-15 on dossiq development: the zrc-020 refusal of a resultaat whose
      resultaattype the zaak's zaaktype does not carry now lives in
      lib/Service/Zgw/ZrcSubResourceRules.php (rulesResultatenCreate), the delegate ZgwZrcRulesService
      constructs and calls; the proposal's line reference (ZgwZrcRulesService.php:362) predates that
      split. decidiq's lib/ holds no resultaattype check, so the rule is built once, in dossiq. -->
- [ ] 11.3 Tick this change in `competitor-parity-2026-09/tasks.md` when it archives.
