# approval-routes Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [approval-routes-on-flow-user-tasks](../../) (this delta)

## Purpose

Approval routes run as OpenRegister flows of user-task nodes instead of on decidiq's own engine (programme decision 64). Covers matrix rows rou-01, rou-04 and rou-16. Screens: DcBesluitRoute, DcBesluit, DcMijnActies, DcMijnInstellingen on the Zuiddrecht design canvas.

## ADDED Requirements

### Requirement: REQ-ARF-001 A route template compiles to a flow of user tasks

The system SHALL compile an `ApprovalRoute` into an OpenRegister flow definition with one `openregister.user-task` node per step, a manual trigger, and outcome edges. Steps that share an order MUST become parallel branches that join before the next order. The compiled flow MUST be stored through OpenRegister's flow API, keyed on the route and its version.

#### Scenario: a four-step route compiles

- GIVEN route "Raadscyclus Zuiddrecht" with steps Team Mobiliteit (advice), College van B&W (vote), Commissie Ruimte (advice) and Gemeenteraad (vote)
- WHEN the secretary saves the route
- THEN OpenRegister holds a flow with four user-task nodes in that order
- AND each node lists the outcomes its step allows

#### Scenario: two steps with the same order run side by side

- GIVEN a route where Wethouder and Concerncontroller both have order 2
- WHEN the route is compiled
- THEN both nodes follow order 1 in parallel
- AND the node for order 3 waits until both have concluded

### Requirement: REQ-ARF-002 Sending a proposal starts one flow run with the decision as its subject

Pressing Send for approval on a decision MUST start exactly one run of the route's flow with that decision as run subject. The system MUST NOT create a decidiq stage row or a decidiq task for the run.

#### Scenario: a proposal is sent

- GIVEN decision "Verordening parkeren binnenstad 2027" and the active route "Raadscyclus Zuiddrecht"
- WHEN Sanne Mulder presses Send for approval and picks that route
- THEN one flow run exists with the decision as subject
- AND the first step's approver has an open task in Mijn acties

#### Scenario: the same proposal is not sent twice

- GIVEN a decision whose route run is still open
- WHEN someone presses Send for approval again
- THEN the request is refused with a message that names the open step

### Requirement: REQ-ARF-003 Each step is a task, and its outcome moves the route

An approver SHALL conclude a step by completing its OpenRegister task with one of the step's outcomes. Approving or advising MUST move the run to the next order. Rejecting MUST conclude the route as rejected.

#### Scenario: approving moves the route on

- GIVEN step 4 of the parking proposal is open for Wouter Bakker
- WHEN Wouter completes the task with outcome approved
- THEN step 4 shows "besloten" on the decision's route tab
- AND step 5, Gemeenteraad, shows "actief"

#### Scenario: rejecting concludes the route

- GIVEN step 2 is open for College van B&W
- WHEN the college completes the task with outcome rejected
- THEN the route concludes as rejected
- AND no later step gets a task

### Requirement: REQ-ARF-004 A return re-opens an earlier step and keeps the earlier outcome

Completing a task with outcome returned MUST create a new task on the step the approver names, or on the sender when no step is named. The earlier task and its outcome MUST stay in the step's history.

#### Scenario: the committee sends the proposal back to the officials

- GIVEN step 3, Commissie Ruimte, is open
- WHEN the committee returns the proposal to step 1 with the reason "kostenraming ontbreekt"
- THEN step 1 has a new open task for Team Mobiliteit
- AND the route tab still shows step 1's first outcome "geadviseerd" with its date, and the return with its reason

### Requirement: REQ-ARF-005 A step's silence has the outcome the route declared, applied once

When a step declares what silence means, its task MUST carry the deadline and the declared outcome, and OpenRegister MUST apply that outcome once when the deadline passes. The route tab MUST show the lapse as its own entry.

#### Scenario: the advice term lapses

- GIVEN step 1 declares that silence after 10 working days means advised
- WHEN 10 working days pass without an outcome
- THEN step 1 concludes as advised, recorded as a lapse
- AND a second sweep of the timer does not record a second lapse

### Requirement: REQ-ARF-006 A substitute can act while the approver is away

While an absence that a member recorded in My settings covers a step, the step's task MUST list both the approver and the named substitute as candidates. The first to complete the task MUST close the step. The route tab MUST show the substitute line from the board.

#### Scenario: Lisa signs for Wouter

- GIVEN Wouter Bakker recorded an absence from 5 to 16 October with Lisa de Groot as substitute
- AND step 4 opens on 7 October
- WHEN Lisa completes the task with outcome approved
- THEN step 4 is concluded, recorded as done by Lisa on behalf of Wouter
- AND the route tab shows "Vervanger: Lisa de Groot."

### Requirement: REQ-ARF-007 The route tab reads the flow run

The decision's "Route en stemming" tab SHALL build its list from the flow run, its tasks and the task audit rows: one row per step with sequence, decision maker, role and method, status badge, outcome, date and label, plus the count of decided steps and the "Nog te doen" line.

#### Scenario: the route tab on a running route

- GIVEN a six-step route of which steps 1 to 3 are concluded and step 4 is open
- WHEN a member opens the decision's Route en stemming tab
- THEN the tab shows "3 van 6 fasen besloten"
- AND step 4 carries "Huidig" and "actief"
- AND steps 5 and 6 show "in afwachting"

### Requirement: REQ-ARF-008 Routes that run on the old engine finish there

Routes with an open step when this change is installed MUST finish on decidiq's old engine. Every route sent after installation MUST run as a flow. The old engine's services, background job and listener MUST be removed once no route runs on them, and the `ApprovalAction` rows MUST stay readable.

#### Scenario: a half-way route is not restarted

- GIVEN a route at step 3 on the old engine when the upgrade runs
- WHEN the approver of step 3 approves
- THEN step 4 opens on the old engine
- AND no flow run is created for that decision

#### Scenario: the old engine is gone when nothing uses it

- GIVEN `occ decidiq:approval-routes:legacy` lists no route
- WHEN the clean-up release is installed
- THEN `ApprovalRouteAdvancer`, `ApprovalStageActivator`, `ApprovalStageLapseService` and `ApprovalStageLapseJob` no longer exist
- AND the history of concluded legacy routes still shows on their decisions

### Requirement: REQ-ARF-009 A route that needs a missing OpenRegister feature is refused when saved

Saving a route MUST be refused when it uses a feature that the installed OpenRegister does not offer (return edge, deadline split, lapse outcome, reminder before the deadline, performer by rule, exclusion list, hold). The message MUST name the feature and the step. decidiq MUST NOT run that step on its own engine instead.

#### Scenario: a step names the manager rule on an OpenRegister without it

- GIVEN an OpenRegister whose task performer catalogue has no rule strategy
- WHEN the secretary saves a route whose step 2 asks for "manager of the owning organisation"
- THEN saving is refused with a message naming step 2 and the performer rule
