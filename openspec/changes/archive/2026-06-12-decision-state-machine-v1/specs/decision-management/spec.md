# Spec delta: Decision Management — full state machine and detail visualization

This file contains delta specifications for the decision-state-machine-v1 change against the existing `decision-management` capability. It replaces the simplified internal/public publication-only flow with the full guarded decision lifecycle and makes the detail view's state visualization and voting results concrete.

---

## MODIFIED Requirements

### Requirement: Decision State Machine

The system MUST enforce a configurable state machine for decision lifecycle management, implemented as a guarded transition map (`DecisionTransitionGuard` in `lib/Lifecycle/` — the decidesk lifecycle pattern, no workflow-library dependency). The lifecycle MUST be stored in an additive `lifecycle` field on the `Decision` schema and MUST include the states `draft`, `proposed`, `deliberating`, `voting`, `decided`, `enacted`, `archived`. Only valid transitions MUST be allowed; an invalid transition MUST be rejected with an error naming the allowed transitions from the current state. Transition policy MUST be configurable per governance domain (quorum enforcement, chair-only transitions, decide-without-vote for operational domains) with a default-deny fallback for unknown domains. Entering `voting` in a quorum-enforced domain with a linked meeting MUST be blocked while the meeting's quorum is not met. Chair-only transitions MUST be rejected when the caller is not the resolved meeting chair, and MUST fail closed when no chair can be resolved. The `enact` transition MUST require `outcome=adopted` and MUST record the enacted date. Every transition MUST be appended to the hash-chained audit log with actor and timestamp.

**Feature tier**: MVP
**Legal reference**: Awb 3:40-3:45 (formal decision requirements), Gemeentewet 56 (council decision procedures)

#### Scenario: Transition a decision from draft to proposed

- GIVEN a decision in `draft` status with all required fields completed
- WHEN the decision owner triggers the "propose" transition
- THEN the status MUST change to `proposed`
- AND the transition MUST be recorded in the audit trail with timestamp and actor
- AND notifications MUST be sent to all members of the governing body

#### Scenario: Reject an invalid state transition

- GIVEN a decision in `draft` status
- WHEN a user attempts to transition directly to `decided`
- THEN the system MUST reject the transition with an error indicating the allowed transitions from `draft`
- AND the decision status MUST remain `draft`

#### Scenario: Transition a decision to enacted after approval

- GIVEN a decision in `decided` status with a positive voting outcome
- WHEN the decision owner triggers the "enact" transition
- THEN the status MUST change to `enacted`
- AND the system MUST generate a resolution record (see resolution-minutes spec)
- AND the enacted date MUST be recorded

#### Scenario: Available transitions are exposed for the current state

- GIVEN a decision in any lifecycle state
- WHEN the available transitions are requested for the decision
- THEN the system MUST return the current lifecycle state and exactly the actions permitted by the transition map and the domain policy

#### Scenario: Quorum gate blocks opening the vote

@e2e exclude backend quorum-guard contract — exhaustively covered by the PHPUnit guard matrix and the Newman 422 contract; the dev seed has no unmet-quorum meeting to drive a UI flow against
- GIVEN a decision in `deliberating` status linked to a meeting whose quorum is not met, in a quorum-enforced domain
- WHEN a user triggers the "openVoting" transition
- THEN the system MUST reject the transition with a quorum error
- AND the decision status MUST remain `deliberating`

#### Scenario: Chair-only transition is enforced per domain

@e2e exclude authorization contract — covered by PHPUnit (chair mismatch + unresolvable-chair fail-closed) and Newman; not a UI flow (the UI only offers actions the server allows)
- GIVEN a decision in a domain whose policy marks the transition chair-only
- WHEN an authenticated user who is not the resolved meeting chair triggers that transition
- THEN the system MUST reject the transition
- AND when no chair can be resolved at all the transition MUST also be rejected (fail closed)

---

### Requirement: Decision Detail View

The system MUST provide a detail view for each decision using the `CnDetailPage` and `CnObjectSidebar` components, declared via the manifest registry pattern. The detail view MUST show decision metadata, a **Lifecycle** sidebar tab with state machine visualization (every lifecycle state marked done/current/upcoming plus the allowed next transitions as actions), a **Voting** sidebar tab with voting results (for/against/abstain tallies from the voting-round and vote objects linked through the decision's motion), the linked agenda item/meeting, and the audit trail.

**Feature tier**: MVP

#### Scenario: View decision detail with voting results

- GIVEN a decision in `decided` status with completed voting
- WHEN the user navigates to the decision detail view
- THEN the page MUST display the decision title, body, status badge, and description
- AND the voting results MUST show for/against/abstain counts
- AND the state machine visualization MUST highlight the current state
- AND the sidebar MUST show metadata, linked meeting, and action buttons

#### Scenario: State machine visualization highlights the current state

- GIVEN a decision in any lifecycle state
- WHEN the user opens the Lifecycle tab on the decision detail view
- THEN all seven lifecycle states MUST be rendered in order
- AND the decision's current state MUST be visually highlighted
- AND the allowed next transitions MUST be presented as actions
