---
kind: code
depends_on: []
---

# Proposal: approval-routes-on-flow-user-tasks

## Summary

Run decidiq's approval routes as OpenRegister flows made of `openregister.user-task` nodes, and remove decidiq's own route engine once no route still runs on it. A route stays a template a secretary authors in decidiq. Sending a proposal along it starts one flow run with the decision as its subject. Every step is an OpenRegister task, so approvers act from the same task list as every other app, and the route timeline on the decision reads the run and its tasks instead of decidiq's own stage rows.

## Why

Ruben decided this on 5 October 2026 (programme decision 64, gate 23 question 7): approval routes are rebuilt as Flow user tasks, with no second engine in the task sequence.

Today decidiq carries a complete approval engine of its own: `ApprovalRouteService`, `ApprovalRouteAdvancer`, `ApprovalStageActivator`, `ApprovalStageLapseService` with `ApprovalStageLapseJob`, `ApprovalActorResolver`, `ApprovalThresholdCalculator`, `ApprovalStageTaskProjector` and the schemas in `lib/Settings/register.d/69-approval-routes.json`. OpenRegister already runs graph-shaped work as flows with user-task nodes (`openspec/specs/flow-user-task-node` in openregister). Two engines that both create tasks, time them out and record what an approver did will drift, and ADR-022 says an app consumes what OpenRegister owns.

## The rows this covers

Source: `openspec/parity/capabilities.json`.

- **rou-01** Send a proposal along a route of named people who must approve it before it goes to the body (building). The Send for approval dialog of `routes-send-proposal-along-route` starts a flow run instead of `ApprovalRouteService::instantiate()`.
- **rou-04** See the approval route and where it stands on the document itself (built today on decidiq's engine). The route timeline keeps its place and reads the flow run.
- **rou-16** Set a substitute who approves in someone's place while they are away (built today on decidiq's engine). The substitute becomes a candidate on the step's task.

rou-02 (approver found from the organisation) and rou-03 (declared meaning of silence) are decided-no rows. This change keeps their existing behaviour working where a route already uses it, and does not add them to the screens.

## What OpenRegister must provide first

Read on openregister development (`dd889e813f`, lane 27, recorded in the build-all `openregister/STATE.md`). Each is an OpenRegister change; decidiq builds none of it.

| decidiq needs | OpenRegister today | Size |
|---|---|---|
| A return re-opens an earlier step as a new task and keeps the earlier outcome (REQ-AR-006) | an edge back to an earlier user-task node may already work; not proven | S (verify, test) |
| One deadline for the route, split over its steps (REQ-AR-009) | a `dueAt` per node only | M |
| A declared outcome on silence, applied once and audited (REQ-AR-015, REQ-AR-017) | task `expiresAt` + `onTimeout`, applied by `FlowTimerSweep` | S (verify) |
| A reminder or the substitute asked before the deadline (REQ-AR-016) | notices on due and overdue only | M |
| A performer found by a rule, such as the manager on the organisation record (REQ-AR-012, REQ-AR-013) | pool strategies over users, groups and roles only | M |
| An exclusion list per route (REQ-AR-008) | the self-decision guard only | S to M |
| An append-only action log per step that includes return, hold and lapse (REQ-AR-002, REQ-ARE-004) | `FlowTaskBridge::record()` writes task actions; no `hold` | S to M |

Until a row is available, decidiq refuses to save a route that needs it, by name (REQ-ARF-009). It never falls back to its own engine for that step.

## What changes

1. A route compiler turns an `ApprovalRoute` template into an OpenRegister flow definition: one user-task node per step, steps that share an order become parallel branches joined before the next order, and outcome edges for approve, reject and return.
2. Sending a proposal starts a run of that flow with the decision as run subject. The decision's route fields are written by the flow, not by a decidiq service.
3. The decision's Route and voting tab (board DcBesluitRoute) reads the run, its tasks and their audit rows.
4. Approvers act in Mijn acties (board DcMijnActies), which lists OpenRegister tasks.
5. Routes that are running when this ships finish on the old engine. New routes start on flows. When no route runs on the old engine, its services, job and listener are deleted; the `ApprovalAction` rows stay readable as history.
6. Gate 23's match on `RegisterApprovalChainLeafListener` is answered: the leaf stays (it renders the route on another app's page), and nothing behind it is an engine any more.

## Out of scope

- Building the OpenRegister rows in the table above.
- New route features. The steps, roles and outcomes are the ones decidiq has today.
- Moving dossiq's parafeerroutes; that follow-up already waits on decidiq's routes and now waits on this.

## Risks

- A route that is half-way when the change ships must not be restarted from step one. The migration leaves it on the old engine until it concludes (REQ-ARF-008).
- Error texts change: the route API returned decidiq's messages and OpenRegister's task API returns its own. Tests that assert texts move with them.
