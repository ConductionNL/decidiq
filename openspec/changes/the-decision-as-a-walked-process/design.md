# Design: the-decision-as-a-walked-process

## Context

`approval-routes` gives decidiq `ApprovalRoute` (the template),
`DecisionStage` (the instance) and `ApprovalAction` (the append-only record
of what an actor did). That change is open and is the dependency of this
one. Everything below is a property on one of those three, a scheduler, or
a guard, and nothing here introduces a fourth model.

## Decisions

### An approval is a task, not a flag

An open approval gets `dueAt`, `assignee` and `state`. That is the whole of
C-decisions-1: the work item already exists as `ApprovalAction`, it simply
has nowhere to say it is outstanding. Request Tracker's approvals are
tickets in their own queue, which is the same choice made once.

The alternative, a separate task object, was rejected. It would need its
own lifecycle beside the action's, and the two would drift.

### The threshold recomputes, it does not freeze

A step holds `thresholdKind` (`all`, `count`, `share`) and
`thresholdValue`. The step's outcome is a function of the actions recorded
against it, evaluated on read and on write. Changing the threshold
therefore changes the outcome without a migration, which is what GLPI's
validation steps do and what C-decisions-4 asks for.

Storing the computed outcome as well would be a second source of truth. It
is stored only as a cached value with the inputs it was computed from, and
recomputed when they differ.

### Staleness is declared, not inferred

A step declares `approvalBasis`: the properties of the subject the approval
was given for. When one changes, the step's approvals are withdrawn with
`withdrawnReason = basis-changed`. Forgejo and Gitea dismiss approvals on a
new push, which is the same rule with one basis, the diff.

Inferring the basis from any change to the subject would withdraw
approvals when a typo is fixed, and people would stop approving.

### Admissibility is a verdict with a ground

`ontvankelijkheid` is an enum on the intake step with a `ground` reference
into the grounds list. It is not a free-text note, because an inadmissible
verdict ends a case and has to be explainable afterwards.

### Withdrawal names the actor kind

`withdrawnBy` is `bestuursorgaan` or `belanghebbende`. The two have
different consequences in Dutch administrative practice, and a single
boolean loses the difference this candidate exists to keep.

### A future effective date needs a job, not a view

A document with `plannedEffectiveDate` in the future is not effective. A
daily background job promotes it and writes the promotion to the audit
trail. Computing effectiveness on read would make the moment of taking
effect invisible, and the moment is the point.

### The risk score is declarative

The score is a declarative expression over the subject's own fields,
evaluated before the route opens. No model, no service call. The candidate
is documented rather than driven, so the spec labels it and the design
keeps it small on purpose.

## Risks

- **Recomputing a threshold changes a closed outcome.** A step that closed
  can reopen. The spec requires the recomputation to be recorded as an
  action with its reason, so it is never silent.
- **Stale-approval withdrawal is noisy if the basis is too wide.** The
  basis is an explicit property list, and an empty basis means the approval
  never goes stale.
- **A released document cannot be unreleased.** Release is a recorded act,
  and correcting it is a new version, not a deletion.

## Open questions

- Which grounds list the admissibility ground refers to, and whether it is
  decidiq's or openregister's. The spec names the reference and leaves the
  register to the implementation.
- Whether a nominated approver is bounded by a group or by a role. The
  spec requires a bound and does not choose it.
