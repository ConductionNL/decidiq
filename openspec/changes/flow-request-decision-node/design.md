# Design: flow-request-decision-node

## Shape

```
flow run ──► DecidiqRequestDecisionNode ──► FlowDecisionService ──► DecisionIntegrationService::createDecision()
                    │                              │                 DecisionIntegrationAuthorizationGuard::resolveOutcomeReadAccess()
                    │                              │                 DecisionIntegrationService::getOutcomeEnvelope()
                    │ FlowSuspension (heartbeat)
                    ▼
decision concludes ─► DecisionLifecycleService emits DecisionConcludedEvent(sourceApp = decidiq-flow)
                    ─► FlowDecisionConcludedListener ─► FlowRunSignalService::signalRunAs() ─► run due now
                    ─► node re-enters, READS the decision, advances on decidiq's word
```

## Decisions

### D1. Services, not events, inside decidiq

dossiq reached decidiq through `DecisionRequestedEvent` and
`DecisionStateRequestedEvent` because it had to. decidiq dispatching its own
events to itself would add a bus hop and a second place where "unhandled"
must be interpreted. `FlowDecisionService` calls the same service and guard the
two listeners call, so the event path and the node path cannot disagree about
what a decision's state is or who may read it.

### D2. The six states are kept

`FlowDecisionService::readState()` answers `unreadable`, `refused`, `gone`,
`open`, `decided` or `withdrawn`, the same words
`ContractDecisionDelegationService` uses. The mapping from the guard:
`READ_UNRESOLVED` is unreadable, `READ_DENIED` is refused, a null envelope is
gone, and the envelope's `status` maps `approved`/`rejected` to decided,
`withdrawn` to withdrawn, `pending` to open, and an unknown word to open.

The read is scoped to the identity that raised the decision (`raisedBy` in the
slot, falling back to the run's `runAs`), as dossiq's was: decidiq stamps the
owner from the uid that saved the decision, and the guard is the same one the
HTTP outcome endpoint enforces. A run naming nobody re-suspends and logs.

### D3. The external reference names the run and the node

`externalReference = flow-run:<runUuid>:<nodeId>`. It is decidiq's idempotency
key together with `sourceApp` and the subject, so:

- two decision steps on one object in one run get two decisions;
- a raise retried after a crash between save and slot write finds the decision
  it already made instead of convening people twice;
- the conclusion listener can name the run without a subject scan.

When the engine hands no `runUuid` (an older OpenRegister), the reference falls
back to the subject id, which is dossiq's behaviour, and the listener falls back
to `FlowRunMapper::findSuspendedBySubject()`.

### D4. The conclusion wakes the run; the decision decides

`FlowDecisionConcludedListener` handles `DecisionConcludedEvent` only when
`sourceApp` is `decidiq-flow`. It resolves the run, and signals it only when one
of the run's node slots records this very `decisionRef`. It signals through
`FlowRunSignalService::signalRunAs()` (the guarded seam OpenRegister asks
consumers to use), addressed to the node that asked. The payload is a wake: the
node re-reads the decision and routes on decidiq's state, so a wake can never
answer for a decision that is still open.

A failure here is logged and swallowed: the decision is concluded whether or
not a run was listening, and the heartbeat recovers the run.

### D5. Guarded registration

`FlowNodeRegistrar` runs from `Application::register()`. It calls
`OpenRegisterAutoloader::register()` (OpenRegister sorts after decidiq, so its
classes are not autoloadable yet during register) and registers the node
listener and the conclusion listener only when
`OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent` exists. Without
OpenRegister, decidiq boots and simply offers no node.

## Risks

- **Stubs drift from OpenRegister.** Unit tests run against declaration stubs
  of OpenRegister's flow classes. The value classes the node actually drives
  (`FlowResumeState`, `FlowNodeResumeState`, `FlowSuspension`) are copied from
  OpenRegister `development` with their behaviour, so the resume slot a test
  hands the node is built the way the engine builds it.
- **`sourceApp` is frozen once decisions exist.** Renaming `decidiq-flow` would
  orphan every in-flight flow decision from its wake (the heartbeat would
  still recover them).
