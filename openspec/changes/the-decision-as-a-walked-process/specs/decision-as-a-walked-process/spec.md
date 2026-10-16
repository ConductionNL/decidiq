# decision-as-a-walked-process Specification

**Status**: proposed
**Scope**: decidiq
**OpenSpec changes**:
- [the-decision-as-a-walked-process](../../changes/the-decision-as-a-walked-process/)

## Purpose

The acts that happen along a sign-off route: an approval that blocks, a
threshold that recomputes, an approval that goes stale, an admissibility
verdict, a withdrawal with its actor, a future effective date, a remedy
declared per decision type, a risk score and an approver the applicant
names. Extends `approval-routes`. Requested by the dossiq competitor
programme, discovery cluster 22.

## ADDED Requirements

### Requirement: REQ-DWP-001 An open approval is a work item

An `ApprovalAction` awaiting an actor SHALL carry `assignee`, `dueAt` and
`state` (`open`, `granted`, `refused`, `withdrawn`). An open action SHALL
appear in the assignee's task list and SHALL mark its subject as blocked.
A subject with an open mandatory action SHALL NOT advance past its step.

Candidate C-decisions-1, `must`, one driven passer (request-tracker).

#### Scenario: An open approval blocks the subject

- **GIVEN** a decision on a route whose current step has one mandatory open action
- **WHEN** a user asks to advance the decision
- **THEN** the advance is refused and names the open action and its assignee
- @e2e exclude {route engine invariant, covered by PHPUnit on ApprovalRouteService}

#### Scenario: The assignee sees the approval as work

- **GIVEN** an open action assigned to a user with a due date of tomorrow
- **WHEN** that user opens their task list
- **THEN** the action is listed with its subject, its step and its due date

### Requirement: REQ-DWP-002 A step accepts a share of its approvers

A route step SHALL carry `thresholdKind` (`all`, `count`, `share`) and
`thresholdValue`. The step's outcome SHALL be computed from the actions
recorded against it. Changing `thresholdKind` or `thresholdValue` SHALL
recompute the outcome from those same actions and SHALL record the
recomputation as an action with the old and the new threshold.

Candidate C-decisions-4, `should`, one driven passer (glpi).

#### Scenario: Lowering the threshold closes an open step

- **GIVEN** a step of five approvers with `share = 0.8` and three grants recorded
- **WHEN** an administrator changes the share to `0.6`
- **THEN** the step closes as granted
- **AND** an action records the change from `0.8` to `0.6` and the actor who made it

#### Scenario: Raising the threshold reopens a closed step

- **GIVEN** a step closed as granted at `share = 0.5` with three grants of five
- **WHEN** the share is raised to `0.8`
- **THEN** the step reopens and the subject is blocked again

### Requirement: REQ-DWP-003 An approval goes stale when its basis changes

A route step SHALL carry `approvalBasis`, a list of property paths on the
subject. When any listed property changes after an action was granted, the
system SHALL set that action to `withdrawn` with
`withdrawnReason = basis-changed`, SHALL reopen the step, and SHALL notify
the actor whose approval was withdrawn. An empty `approvalBasis` SHALL
never withdraw an approval.

Candidate C-decisions-10, `should`, two driven passers (forgejo, gitea).

#### Scenario: Editing what was approved withdraws the approval

- **GIVEN** a step with `approvalBasis = ["body", "attachments"]` and one grant
- **WHEN** the subject's `body` is edited
- **THEN** the grant becomes `withdrawn` with reason `basis-changed`
- **AND** the step is open again

#### Scenario: Editing outside the basis keeps the approval

- **GIVEN** the same step and grant
- **WHEN** the subject's `internalNote` is edited
- **THEN** the grant is unchanged

### Requirement: REQ-DWP-004 Intake ends with an admissibility verdict

A route step of kind `intake` SHALL yield `ontvankelijkheid`
(`ontvankelijk`, `niet-ontvankelijk`) with a `ground` reference and a
`decidedBy` actor. A `niet-ontvankelijk` verdict SHALL close the route with
outcome `ended-at-intake` and SHALL NOT advance to the next step. The
verdict SHALL be readable by the consuming case app.

Candidate C-decisions-13, `must`, one driven passer (dimpact-zac).

#### Scenario: An inadmissible request ends the route

- **GIVEN** a route whose first step is an intake step
- **WHEN** the handler records `niet-ontvankelijk` with a ground
- **THEN** the route closes with outcome `ended-at-intake`
- **AND** no later step is instantiated

#### Scenario: A verdict without a ground is refused

- **GIVEN** the same intake step
- **WHEN** `niet-ontvankelijk` is recorded with no ground
- **THEN** validation refuses it and names the missing ground

### Requirement: REQ-DWP-005 A decision is withdrawn, and by whom

A decision SHALL carry `withdrawn` with `withdrawnAt`, `withdrawnReason`
and `withdrawnBy` (`bestuursorgaan`, `belanghebbende`). Withdrawal SHALL
be an append to the decision's history, never an edit of its outcome, and a
withdrawn decision SHALL still render its original outcome beside the
withdrawal.

Candidate C-decisions-2, `must`, one driven passer (dimpact-zac).

#### Scenario: The interested party withdraws their own request

- **GIVEN** a granted decision
- **WHEN** it is withdrawn with `withdrawnBy = belanghebbende` and a reason
- **THEN** the decision reads as withdrawn, names the party, and still shows the original outcome

#### Scenario: A withdrawal without an actor kind is refused

- **GIVEN** a granted decision
- **WHEN** a withdrawal is saved with no `withdrawnBy`
- **THEN** validation refuses it

### Requirement: REQ-DWP-006 A document takes effect on a date it was given

A governing document SHALL carry `plannedEffectiveDate` and
`effectiveDate`. A daily job SHALL set `effectiveDate` on every document
whose `plannedEffectiveDate` has arrived and which is approved, SHALL write
the release to the audit trail, and SHALL leave an unapproved document
alone. A document whose `effectiveDate` is unset SHALL NOT be presented as
in force.

Candidate C-decisions-3, `must`, one driven passer (huly).

#### Scenario: A verordening takes effect on 1 January

- **GIVEN** an approved document with `plannedEffectiveDate` of 2027-01-01
- **WHEN** the job runs on 2027-01-01
- **THEN** `effectiveDate` is set to 2027-01-01 and the release is in the audit trail

#### Scenario: An unapproved document does not take effect

- **GIVEN** a document with a planned date of yesterday and no approval
- **WHEN** the job runs
- **THEN** `effectiveDate` stays unset and the document is reported as overdue

### Requirement: REQ-DWP-007 The remedy is declared per decision type

A decision type SHALL declare `legalRemedies`: for each remedy its kind
(`bezwaar`, `beroep`, `administratief-beroep`, `geen`), its term in days
and the body it is lodged with. A decision SHALL resolve its remedies from
its type at the moment it is taken and SHALL carry the resolved clause.
Publishing a decision type with no `legalRemedies` declaration SHALL be
refused.

Candidate C-decisions-25, `must`, matrix hole, two driven passers
(opencase, xxllnc-zaken).

#### Scenario: A decision prints the clause its type declares

- **GIVEN** a decision type declaring bezwaar, 6 weeks, at the college
- **WHEN** a decision of that type is taken
- **THEN** the decision carries the clause naming bezwaar, six weeks and the college

#### Scenario: A type with no remedy declaration cannot be published

- **GIVEN** a decision type with `legalRemedies` unset
- **WHEN** an administrator publishes it
- **THEN** publication is refused and names the missing declaration

The clause SHALL reach the person the decision affects, which means it SHALL
survive every hop between the decision and the public publication: the stamp on
the decision, the allow-list the payload is built from, and the field set the
anonymous harvest feed serves. A besluit that names the legal ground it rests on
and not the way to object to it fails this requirement even though every one of
those hops reports success.

#### Scenario: A published besluit reaches an anonymous reader carrying its remedy clause

- **GIVEN** a decision of a type declaring bezwaar, six weeks, at the college
- **WHEN** it is published and an anonymous caller reads the publication
- **THEN** the caller receives the clause naming bezwaar, six weeks and the college

#### Scenario: The register carries the decision-type schema the guard reads

- **GIVEN** the decidiq register as the app ships it
- **WHEN** the schemas the register carries are listed
- **THEN** `decision-template` is among them, so reading a decision's type does not throw

### Requirement: REQ-DWP-008 A risk score is computed before approval

A decision type MAY declare `riskScore`, a declarative expression over the
subject's own properties yielding a number and a band. The score SHALL be
computed when the route opens and SHALL be recomputed when a property the
expression reads changes. The score SHALL be shown to the approver with the
properties it was computed from.

Candidate C-decisions-7, `should`, one documented passer
(jira-service-management). Documented, never counted in a driven tally
(D21).

#### Scenario: An approver sees why the score is high

- **GIVEN** a decision type with a risk expression over `amount` and `category`
- **WHEN** the route opens on a decision with a high amount
- **THEN** the approver sees the band and the two properties that produced it

### Requirement: REQ-DWP-009 An applicant may name their own approver

A route step MAY carry `nomination` with an eligibility bound (a group or a
role). When set, the requester SHALL nominate an approver from within the
bound, and the nominated actor SHALL receive the action. A nomination
outside the bound SHALL be refused, and a step with `nomination` unset
SHALL ignore any nomination sent to it.

Candidate C-decisions-16, `could`, one documented passer
(jira-service-management). Documented, never counted in a driven tally
(D21).

#### Scenario: A company names its own authorised signatory

- **GIVEN** a step with `nomination` bound to the group `tekenbevoegden`
- **WHEN** the requester nominates a member of that group
- **THEN** the open action is assigned to the nominated member

#### Scenario: A nomination outside the bound is refused

- **GIVEN** the same step
- **WHEN** the requester nominates a user outside `tekenbevoegden`
- **THEN** the nomination is refused and names the bound

### Requirement: REQ-DWP-010 A case reaches a committee and the decision returns

A route step of kind `committee` SHALL reference a governance body and
SHALL place its subject on that body's agenda as an agenda item. When the
body decides, the step SHALL close with the body's outcome, the meeting
reference and the date. A committee step SHALL NOT be closable by a single
actor.

Candidate C-decisions-17, `must`, one documented passer (decos-join).
Documented, never counted in a driven tally (D21). The candidate note reads
"dossiq-only 7.9".

#### Scenario: A collegebesluit returns from the meeting

- **GIVEN** a committee step referencing the college
- **WHEN** the meeting records a decision on that agenda item
- **THEN** the step closes with the outcome, the meeting reference and the date

#### Scenario: One actor cannot close a committee step

- **GIVEN** the same committee step
- **WHEN** a single user records a grant on it
- **THEN** the grant is refused and names the body that has to decide
