---
kind: code
---

# Proposal: flow-request-decision-node

## Summary

Contribute a flow node, `decidiq.request-decision`, to OpenRegister's flow
engine. It raises a Decision in decidiq about the object the flow is carrying,
suspends the run, and advances it only on what decidiq concluded. It is the
decidiq-owned successor of dossiq's `dossiq.requestDecision`.

## Motivation

Requesting a decision about an object is a decidiq capability, not a case one.
OpenRegister's own flow listener says so ("human decisions are decidiq's"), yet
the only node that can do it lives in dossiq (`DossiqRequestDecisionNode`, 615
lines). It reaches decidiq over the cross-app event bus
(`DecisionRequestedEvent`, `DecisionStateRequestedEvent`), hardcodes its
subject as the dossiq `case` schema, and is only offered where dossiq is
installed. A flow on any other register cannot ask for a decision at all.

Ruben decided that decidiq contributes the node and that dossiq's is deleted
afterwards. dossiq rewrites its flows onto this node in a separate change
(Lane E of the flow-nodes programme), so the config keys and the output shape
are a fixed contract: the same names, mapped one to one.

## Affected projects

- [x] `decidiq`: this change. The node, a flow-decision service over the
  existing integration service and outcome-read guard, a conclusion listener
  that wakes the run, and the guarded registration.
- [ ] `dossiq`: rewrites `dossiq.requestDecision` steps to
  `decidiq.request-decision` with a repair step, then deletes its node. Not in
  this change.

## Scope

### In scope

1. `DecidiqRequestDecisionNode`, id `decidiq.request-decision`, accepting the
   config keys of `dossiq.requestDecision` name for name: `question`
   (required), `decisionType`, `advisor`, `signalKey`, `heartbeatMinutes`.
2. The subject is taken from the item (`@self.register`, `@self.schema`, the
   object id and a label), not hardcoded to `dossiq`/`case`.
3. The output written onto every item under `signalKey` (default
   `decisionOutcome`) has the same fields as dossiq's node: `decision`,
   `status`, `decisionRef`, `node`, `decidedAt`, `signed`, `recovered`, plus
   whatever the wake contributed.
4. The behaviours dossiq's node earned are kept: fail closed on a raise that
   cannot happen, one decision per node per run, recovery of a missed
   conclusion on the heartbeat, and six-way reading of a decision's state
   (unreadable, refused, gone, open, decided, withdrawn).
5. Inside decidiq the node calls decidiq's services directly
   (`DecisionIntegrationService::createDecision()`,
   `DecisionIntegrationAuthorizationGuard::resolveOutcomeReadAccess()`,
   `DecisionIntegrationService::getOutcomeEnvelope()`). It does not dispatch
   decidiq's own cross-app events to itself.
6. `DecisionConcludedEvent` for a flow-raised decision wakes the waiting run
   directly, through OpenRegister's guarded signal seam. The heartbeat recovery
   stays as the safety net.
7. Registration through a `RegisterFlowNodesEvent` listener, guarded so decidiq
   boots without OpenRegister.

### Out of scope

- Deleting `dossiq.requestDecision` and rewriting dossiq's flows (dossiq).
- A config form for the flow editor. The node declares its config keys, so the
  editor's raw pane and the preflight unknown-key check both work.

## Deliberate differences from `dossiq.requestDecision`

- **Subject from the item.** `subjectRegister`/`subjectSchema` come from the
  item's `@self`, not the literals `dossiq`/`case`.
- **One decision per node per run.** dossiq's node used the case id as the
  `externalReference`, which is also decidiq's idempotency key, so two decision
  steps of the same type on one case received the SAME decision (dossiq's
  shipped flow has two `advice` steps on one case). This node uses
  `flow-run:<runUuid>:<nodeId>`, so each step gets its own decision and a
  crashed-and-retried raise finds the one it already made.
- **Source app.** Decisions carry `sourceApp = decidiq-flow`. That is what the
  conclusion listener filters on, and it keeps these decisions apart from the
  `procest` ones dossiq's listener projects into ZGW besluiten.
- **Wake payload.** The wake carries `subjectId` where dossiq's carried
  `caseId`, because the subject is no longer necessarily a case. The fields the
  decision decides are identical.
