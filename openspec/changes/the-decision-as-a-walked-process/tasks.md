# Tasks: the-decision-as-a-walked-process

## 1. The approval as a work item

- [x] 1.1 Add `assignee`, `dueAt` and `state` to `ApprovalAction` and migrate the seeded rows.
- [x] 1.2 Block a subject whose current step has an open mandatory action, and name the action in the refusal.
- [x] 1.3 List open actions in the assignee's task list, with subject, step and due date.
- [x] 1.4 PHPUnit: an open mandatory action refuses the advance; a closed one allows it.

## 2. The threshold

- [x] 2.1 Add `thresholdKind` and `thresholdValue` to a route step.
- [x] 2.2 Compute a step's outcome from its actions, and cache it with the inputs it came from.
- [x] 2.3 Recompute on a threshold change and record the change as an action.
- [x] 2.4 PHPUnit: lowering a share closes an open step, raising it reopens a closed one.

## 3. Staleness

- [x] 3.1 Add `approvalBasis` to a route step.
- [x] 3.2 Withdraw grants with `withdrawnReason = basis-changed` when a listed property changes, and reopen the step.
- [x] 3.3 Notify the actor whose grant was withdrawn.
- [x] 3.4 PHPUnit: a change inside the basis withdraws, a change outside it does not, an empty basis never does.

## 4. Admissibility

- [x] 4.1 Add an `intake` step kind yielding `ontvankelijkheid`, `ground` and `decidedBy`.
- [x] 4.2 Close the route with `ended-at-intake` on `niet-ontvankelijk`.
- [x] 4.3 Refuse a verdict with no ground.
- [x] 4.4 PHPUnit on both paths, and expose the verdict on the read seam dossiq consumes.

## 5. Withdrawal

- [x] 5.1 Add `withdrawn`, `withdrawnAt`, `withdrawnReason` and `withdrawnBy` to the decision schema.
- [x] 5.2 Append the withdrawal to the history and keep the original outcome readable.
- [x] 5.3 Refuse a withdrawal with no actor kind.
- [x] 5.4 PHPUnit on both actor kinds.

## 6. The future effective date

<!-- DEFERRED: needs the governing-document release job and a frozen-clock harness; the decision half of this change ships without it. -->

- [ ] 6.1 Add `plannedEffectiveDate` and `effectiveDate` to the governing document schema.
- [ ] 6.2 Write the daily release job, approved documents only, and write each release to the audit trail.
- [ ] 6.3 Report an approved-but-unreleased document as overdue.
- [ ] 6.4 PHPUnit on the job with a frozen clock.

## 7. The remedy clause

- [x] 7.1 Add `legalRemedies` to the decision type: kind, term in days, body.
- [x] 7.2 Resolve the clause onto the decision at the moment it is taken.
- [x] 7.3 Refuse publication of a decision type with no declaration.
- [x] 7.4 PHPUnit on resolution and on the publication guard.

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
- [ ] 11.2 Confirm `ZgwZrcRulesService`'s refusal in dossiq stays where it is, so C-decisions-22 is not built twice.
- [ ] 11.3 Tick this change in `competitor-parity-2026-09/tasks.md` when it archives.
