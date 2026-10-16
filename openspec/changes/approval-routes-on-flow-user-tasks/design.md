# Design: approval-routes-on-flow-user-tasks

Read at decidiq development `a13c5382` and openregister development `e3c2954612`.

## Screens

The screens follow the Zuiddrecht design canvas (https://claude.ai/artifact/5NkFW28vZUUij43xzxHg5a, page dcb81aee8d83).

| Board | What this change puts there |
|---|---|
| DcBesluitRoute | Tab "Route en stemming" on the decision. Header "Route", subline "In beraadslaging" and "3 van 6 fasen besloten". One row per step: sequence number, decision maker ("Team Mobiliteit", "College van B&W", "Wouter Bakker"), role and method ("voorbereidend · advies", "adviserend · stemming", "voorbereidend · ondertekening"), status badge ("besloten", "actief", "in afwachting"), outcome ("geadviseerd", "aangenomen"), date and a label ("Ambtelijk advies"). The current step carries "Huidig". A substitute shows as "Vervanger: Lisa de Groot." with the line "Wouter Bakker is afwezig van 5 tot en met 16 oktober. Lisa kan deze fase namens hem ondertekenen; wie het eerst tekent, sluit de fase." Below the list: "Nog te doen: fase 4 (Wouter Bakker)". |
| DcBesluit | The decision page header keeps "Wat nu?" and the stage bar; the route tab is reached from it. |
| DcMijnActies | "Alles wat op u wacht": an approval step is a task in this list, opened and completed there. |
| DcMijnInstellingen | "afwezigheid en vervanger": where a member records absence and names the substitute the route uses. |

The labels above are the board's. The status words map to task states: `besloten` is a terminal task, `actief` is an open task, `in afwachting` is a node the run has not reached.

## What exists

| Piece | Where |
|---|---|
| Route template and action log | `lib/Settings/register.d/69-approval-routes.json` (ApprovalRoute, ApprovalAction) |
| Engine | `lib/Service/ApprovalRouteService.php`, `ApprovalRouteAdvancer.php`, `ApprovalStageActivator.php`, `ApprovalStageLapseService.php`, `ApprovalActorResolver.php`, `ApprovalThresholdCalculator.php`, `ApprovalStageTaskProjector.php`, `ApprovalRouteCommandService.php`, `ApprovalRouteConclusionAnnouncer.php` |
| Lapse timer | `lib/BackgroundJob/ApprovalStageLapseJob.php` |
| Route tab | `src/components/tabs/DecisionRouteTab.vue` |
| Leaf on other apps' pages | `lib/Listener/RegisterApprovalChainLeafListener.php`, `src/integrations/registerApprovalChainLeaf.js` |
| OpenRegister user task | `lib/Service/Flow/Nodes/UserTaskNode.php`, `UserTaskConfig.php`, `FlowTaskBridge.php`, `Task/TaskPerformerResolver.php`, `Flow/Timer/FlowTimerSweep.php` |

## Approach

### 1. Compile, do not run

`ApprovalRouteFlowCompiler` (new, `lib/Service/ApprovalRouteFlowCompiler.php`) reads one `ApprovalRoute` and returns an OpenRegister flow document:

- a `openregister.trigger-manual` node, since decidiq starts the run on purpose;
- per step one `openregister.user-task` node with `title` (the step label), the performer (`assignee`, `candidateUsers`, `candidateGroups` or `candidateRole`), `outcomes` (`approved`, `rejected`, `returned`, plus `advised` for an advice step), `dueAt` from the route deadline split, `expiresAt` and `onTimeout` from the step's declared silence;
- steps with the same `order` become parallel branches; the node after them has `join: true` and a threshold read from the step (all, or N of M), which replaces `ApprovalThresholdCalculator`;
- edges by outcome: `approved` and `advised` to the next order, `rejected` to a concluding end node, `returned` to the node of the step the approver names (REQ-PRR-003: no step named returns to the sender);
- an `openregister.object-write` node per transition that writes the decision's route fields (`currentStage`, route status), so the decision record shows progress without a decidiq listener.

The compiler stores the flow through OpenRegister's flow API, keyed on the route's UUID and version. A changed route is a new flow version; a running run keeps the version it started on (`flow-definition-versioning`).

### 2. Start a run

`ApprovalRouteService::instantiate()` becomes a thin call: look up the route's flow, queue a run with the decision as subject (`flow-run-subjects`), and return the run id. The dialog from `routes-send-proposal-along-route` keeps its form and calls this.

### 3. Read progress

`DecisionRouteTab.vue` reads the run (`/api/flows/runs/{id}`), its tasks (filtered on the run) and the task audit rows. A small mapper (`src/utils/routeFromFlowRun.js`) turns them into the board's rows. Nodes the run has not reached come from the flow document.

### 4. Substitute and absence

The substitute is read from the absence a member records (DcMijnInstellingen). The compiler puts both the approver and the substitute in `candidateUsers` while the absence window covers the step, so the first to complete the task closes it. Outside the window only the approver is a candidate.

### 5. Migration

A repair step marks every route with an open stage as `engine: legacy`. The old services keep running for those, and only those. New sends always compile. A `occ decidiq:approval-routes:legacy` command lists what is left. The delete task waits for that list to be empty on the reference instances.

### 6. Refuse what OpenRegister cannot do yet

On save, the compiler checks the OpenRegister capability for every feature a route uses (return edge, deadline split, lapse outcome, pre-deadline reminder, rule performer, exclusion list, hold). A route that uses a missing one is refused with a message that names the feature and the step. The check reads OpenRegister's node catalog, not a version number.

## Declarative or imperative

The flow is data in OpenRegister. decidiq's imperative code shrinks to the compiler, the start call and the progress mapper. No decidiq class creates, times out or completes a task.

## Tests

- `ApprovalRouteFlowCompilerTest`: a four-step route with one parallel order compiles to the expected nodes, edges, join and outcomes; validated against OpenRegister's real flow document validator, not a fake.
- `ApprovalRouteServiceTest`: sending starts exactly one run with the decision as subject.
- vitest for `routeFromFlowRun.js`: a run with two done tasks, one open task and two unreached nodes maps to "3 van 6"-style rows and the right badges.
- Playwright `tests/e2e/approval-route-flow.spec.ts` on the reference instance: send a proposal, approve as the first step's user, see the next step active on the decision.
