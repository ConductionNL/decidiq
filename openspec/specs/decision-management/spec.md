---
status: done
status-note: Completed 2026-06-12 via decision-state-machine-v1 (guarded 7-state transition map + per-domain policy, lifecycle/voting detail tabs) on top of decision-evolution-and-cascade + p2-minutes-and-decisions.
---

# Decision Management Specification

## Purpose

Decision management is the core capability of Decidesk. A decision represents a formal choice made by a governance body, association, corporate board, or operational team. Each decision follows a configurable state machine lifecycle from proposal through deliberation, voting, and resolution. This specification covers the decision entity, status transitions, the Symfony Workflow-backed state machine, and audit trail recording.

**Standards**: Schema.org (`Action`, `VoteAction`, `ChooseAction`), Akoma Ntoso (`decision`, `judgment`), OpenRaadsinformatie (`Besluit`)
**Feature tier**: MVP

## Data Model

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for the full Decision entity definition including property tables, Schema.org mappings, Akoma Ntoso alignment, and OpenRaadsinformatie mapping.
## Requirements

---

### Requirement: Decision Creation

The system MUST support creating decision records linked to a meeting, agenda item, or body. Each decision MUST have a `title`, a `body` (governing body reference), and an initial status of `draft`. Decisions MUST be stored as OpenRegister objects in the `decidesk` register using the `decision` schema.

**Feature tier**: MVP

#### Scenario: Create a decision from a meeting agenda item

- GIVEN a user with decision-making access and an active meeting with agenda items
- WHEN they create a new decision linked to agenda item "Budget Approval 2026"
- THEN the system MUST create an OpenRegister object in the `decidesk` register with the `decision` schema
- AND the object MUST have `@type` set to `schema:ChooseAction`
- AND the `status` MUST be set to `draft`
- AND the decision MUST reference the agenda item and meeting

#### Scenario: Create a standalone decision outside a meeting

- GIVEN a user with decision-making access
- WHEN they create a decision with title "Appoint new treasurer" and body "Board of Directors"
- THEN the system MUST create the decision with status `draft`
- AND the decision MUST NOT require a meeting or agenda item reference
- AND the decision MUST reference the body "Board of Directors"

#### Scenario: Fail to create a decision without a title

- GIVEN a user with decision-making access
- WHEN they submit a new decision form without a title
- THEN the system MUST reject the request with a validation error
- AND no OpenRegister object MUST be created

---

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

### Requirement: Decision Audit Trail

The system MUST maintain a complete audit trail for every decision, recording all state transitions, modifications, votes, and comments with timestamps and actor identities. The audit trail MUST be immutable.

**Feature tier**: MVP
**Legal reference**: WBTR (Wet bestuur en toezicht rechtspersonen) documentation requirements

#### Scenario: View the complete history of a decision

- GIVEN a decision that has moved through draft, proposed, deliberating, voting, and decided
- WHEN a user views the decision's audit trail
- THEN the system MUST display all transitions in chronological order
- AND each entry MUST show the timestamp, actor name, previous state, new state, and optional comment

#### Scenario: Audit trail entries are immutable

- GIVEN a decision with audit trail entries
- WHEN any user (including admin) attempts to modify or delete an audit trail entry
- THEN the system MUST reject the modification
- AND the original entry MUST remain unchanged

---

### Requirement: Decision List and Search

The system MUST provide a list view of all decisions with search, sort, and filter capabilities. Users MUST be able to filter by status, body, date range, and decision type.

**Feature tier**: MVP

#### Scenario: Filter decisions by status

- GIVEN the decision list contains decisions in various statuses
- WHEN the user filters by status "voting"
- THEN only decisions currently in the `voting` state MUST be displayed
- AND the result count MUST be shown

#### Scenario: Search decisions by title

- GIVEN decisions exist with titles "Budget 2026", "New parking policy", "Staff expansion"
- WHEN the user searches for "budget"
- THEN the decision "Budget 2026" MUST appear in the results
- AND the search MUST be case-insensitive

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

## User Stories

1. **Board secretary creating a structured decision**: As a board secretary, I want to create a structured decision proposal with options analysis, risk assessment, and financial impact, so that the board can make well-informed strategic decisions. (Source: intelligence DB #15)

2. **Supervisory board reviewing proposals**: As a supervisory board chair, I want to review strategic proposals with full context and approve or reject them digitally, so that governance oversight is exercised efficiently. (Source: intelligence DB #16)

3. **Secretary recording decisions in real-time**: As a secretary, I want to record decisions in real-time during the MT meeting with a structured format (decision text, type, vote, conditions), so that there is immediate clarity on what was decided. (Source: intelligence DB #89)

4. **Chair circulating written resolution**: As chair, I want to circulate a proposal for written decision to all board members and collect their votes electronically so that urgent decisions can be made between meetings per BW 2:40. (Source: intelligence DB #68)

5. **Member tracking decision implementation**: As chair, I want to track the implementation status of ALV decisions with responsible persons and deadlines so that I can report progress at the next ALV. (Source: intelligence DB #77)

## Acceptance Criteria

- Decisions are stored as OpenRegister objects with `@type` of `schema:ChooseAction`
- State machine enforces valid transitions only (Symfony Workflow Component)
- All transitions are recorded in an immutable audit trail
- Decision list supports search, sort, and filter by status/body/date
- Detail view uses CnDetailPage + CnObjectSidebar with state machine visualization
- OpenRaadsinformatie `Besluit` mapping is available for each decision
