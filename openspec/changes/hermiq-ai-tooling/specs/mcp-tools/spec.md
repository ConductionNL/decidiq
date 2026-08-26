# Spec Delta: mcp-tools — full-action agent surface under the hermiq grant model

Extends the `mcp-tools` capability established by `decidiq-mcp-adoption` (REQ-DMCP-011..014).
That change's read surface and its single write are unchanged; this delta adds the write side
of the agent surface — one curated tool per real user action — governed by hermiq's
scope × reach grant model: default-deny writes, per-agent granular grants, human approval
gates for formal governance acts, and tamper-evident audit coverage. Requirement ids continue
the REQ-DMCP series from 015.

## ADDED Requirements

### Requirement: REQ-DMCP-015 — Every user action SHALL be dispositioned on the agent surface

Every state-changing user action reachable through decidiq's controllers/services MUST have
exactly one disposition in the action inventory (design.md D1): served by the derived read
surface, exposed as exactly one curated `#[McpTool]` on the owning service, or **withdrawn
with recorded reasons**. Reads and writes SHALL be separate tools (a tool SHALL NOT both
report and mutate: `MinutesWorkflowService::extractActionItems()` previews via
`decidesk.previewMinutesActionItems`; `saveExtractedActionItems()` writes via
`decidesk.saveMinutesActionItems`). Business logic MUST NOT move into any MCP provider class;
each tool delegates to the pre-existing owning method (`MeetingService::transition()`,
`AgendaService::publishAgenda()`, `VotingRoundOpener::openVotingRound()`,
`VotingRoundCloser::close()`, `ProxyDelegationService::grantProxy()`,
`DecisionPublicationService::publish()`, `ActionItemWriter::update()/delete()`,
`ConflictOfInterestService::declare()`, `MotionService::addCoSigner()/forwardMotion()`,
`MinutesDraftService::generate()`, `MinutesWorkflowService::submitForApproval()`) with its
domain guards intact.

#### Scenario: The inventory covers the controllers
- **GIVEN** the set of state-changing actions enumerated from `lib/Controller/`
- **WHEN** each is looked up in the action inventory
- **THEN** every action has exactly one disposition and no action is absent from the table

#### Scenario: One tool per action, on the owning service
- **GIVEN** the tools added by this change
- **WHEN** each tool's declaring class is inspected
- **THEN** it is the service that owned the action before this change, and `lib/Mcp/` contains no tool logic

#### Scenario: Reads and writes are separate tools
- **GIVEN** the minutes action-item extraction flow
- **WHEN** an agent previews extraction
- **THEN** `decidesk.previewMinutesActionItems` creates nothing
- **AND** VTODOs are created only by an explicit `decidesk.saveMinutesActionItems` call

### Requirement: REQ-DMCP-016 — Every write tool SHALL carry honest grant-model annotations

Every curated write tool MUST declare `scope` (`create`/`update`/`delete`),
`readOnlyHint: false`, an explicit `destructiveHint`, and an explicit `idempotentHint`,
matching the scope × reach matrix in design.md D2, so hermiq's
`ToolGrantResolver::requiresGrant()` classifies it as write/destructive and default-denies
it. The intended reach (`self`/`user`/`instance`/`external`) SHALL be recorded in the matrix
for every tool; until OpenRegister's `McpTool` attribute supports a `reach` parameter,
undeclared reach resolves fail-closed to `external` in hermiq's `ToolReachResolver`, and this
change SHALL NOT attempt to bypass that fail-closed default.

#### Scenario: No write tool ships unannotated
- **GIVEN** every `#[McpTool]` added by this change whose scope is not `read`
- **WHEN** its attribute arguments are inspected
- **THEN** `scope`, `readOnlyHint`, `destructiveHint` and `idempotentHint` are all explicitly declared

#### Scenario: An ungranted write tool is invisible to the agent
- **GIVEN** an agent with no grant for `decidesk.updateActionItem`
- **WHEN** hermiq builds that agent's tool catalogue
- **THEN** the tool is stripped by default-deny and the model never sees it

#### Scenario: A dry-run neutralises every write on this surface
- **GIVEN** a hermiq dry-run of a plan containing any write tool from this change
- **WHEN** the plan executes in preview
- **THEN** the write call is neutralised, not invoked

### Requirement: REQ-DMCP-017 — Formal governance acts SHALL be human-approval-gated; meeting transitions return only under the gate

The tools `decidesk.transitionMeeting`, `decidesk.publishAgenda`, `decidesk.reviseAgenda`,
`decidesk.forwardMotion`, `decidesk.openVotingRound`, `decidesk.closeVotingRound`,
`decidesk.grantProxy`, `decidesk.revokeProxy`, `decidesk.deleteActionItem` and
`decidesk.publishDecision` MUST declare `destructiveHint: true` so every invocation is
approval-gated by hermiq in addition to requiring a grant. The approval prompt SHALL identify
the resolved target object (title, date, governance body) so a human confirms the *object*,
not merely the intent — this is the wrong-object check that superseded requirement
REQ-DMCP-005 (removed by `decidiq-mcp-adoption`) correctly observed the chair guard cannot
perform. Meeting lifecycle transitions thereby return to the agent surface **only** in this
gated form; `decidesk.publishDecision` (reach `external`: public Woo/DiWoo/ORI publication)
SHALL remain approval-gated even when a grant carries a `#noapproval` waiver.

#### Scenario: Opening a meeting queues for approval
- **GIVEN** a chair whose agent holds a grant for `decidesk.transitionMeeting`
- **WHEN** the agent invokes it with `{meetingId: '<uuid>', action: 'open'}`
- **THEN** the invocation queues in hermiq's approval flow showing the resolved meeting's title, scheduled date and governance body
- **AND** `MeetingService::transition()` runs only after a human approves

#### Scenario: A hallucinated UUID dies at the approval card
- **GIVEN** the agent resolves the wrong meeting UUID
- **WHEN** the approval prompt renders a meeting the chair did not mean
- **THEN** the chair rejects, no `openedAt` is written, and no notification fan-out fires

#### Scenario: External publication ignores the waiver
- **GIVEN** a grant `decidesk.publishDecision#noapproval`
- **WHEN** the agent invokes the tool
- **THEN** the invocation still queues for approval and nothing is published without a human decision

### Requirement: REQ-DMCP-018 — Ballots and qualified signatures SHALL never be agent tools

The system SHALL NOT expose `VoteCastingService::castVote()` or any eIDAS signing action
(`EIDASSignatureService`) as an MCP tool, gated or otherwise: casting a ballot and placing a
qualified signature are personally attributable acts whose delegation to an agent no approval
flow can legitimise. Individual `Vote` objects SHALL remain absent from the derived surface
(reaffirming `decidiq-mcp-adoption` D2). The governed alternative — proxy delegation to
another human — is served by `decidesk.grantProxy`/`decidesk.revokeProxy`.

#### Scenario: No ballot tool exists
- **GIVEN** the complete MCP tool catalogue after this change
- **WHEN** tool ids and their receiving methods are inspected
- **THEN** no tool invokes `castVote()` or any `EIDASSignatureService` method

#### Scenario: The withdrawal is recorded, not accidental
- **GIVEN** the action inventory
- **WHEN** the `castVote` row is read
- **THEN** it is dispositioned "withdrawn" with the recorded reason, so a future change meets the reasoning before re-exposing it

### Requirement: REQ-DMCP-019 — Every agent write SHALL land in the tamper-evident audit chain

Every curated write tool invocation that mutates state MUST produce exactly one
`AuditLogService::append()` entry recording the tool id, the agent identity, the acting
user, and the target object ids. Where the owning service already appends for that action
(e.g. proxy grant/revocation), the tool SHALL pass agent attribution into that existing
entry rather than appending a second one — one action, one chain entry. Read tools SHALL NOT
append.

#### Scenario: An agent write is attributable in the chain
- **GIVEN** an approved `decidesk.closeVotingRound` invocation
- **WHEN** the audit chain is exported
- **THEN** exactly one new entry exists for the action, carrying the tool id and agent identity alongside the acting user

#### Scenario: No double entries for already-audited actions
- **GIVEN** `decidesk.grantProxy` (whose service already writes to the chain)
- **WHEN** the tool is invoked once
- **THEN** the chain grows by exactly one entry, and that entry carries the agent attribution

### Requirement: REQ-DMCP-020 — Draft-only creates SHALL exist and SHALL NOT fan out

`decidesk.scheduleDraftMeeting` (new `MeetingService` method) and `decidesk.addAgendaItem`
(new `AgendaService` method) SHALL create meetings pinned to `lifecycle: draft` and agenda
items on unpublished agendas only — answering `decidiq-mcp-adoption`'s Open Questions with
curated methods, since the declarative dialect cannot pin a property value on a derived
`create`. Before either tool is enabled, the `Meeting` schema's `meetingScheduled`
notification rule (which today triggers on `created` with no lifecycle filter) SHALL be
constrained to `lifecycle: scheduled`, and the register version SHALL be bumped so the rule
change deploys.

#### Scenario: A draft meeting create notifies nobody
- **GIVEN** the updated register is imported
- **WHEN** `decidesk.scheduleDraftMeeting` creates a meeting with `lifecycle: draft`
- **THEN** zero notifications are produced (measured against the notification store, before/after)

#### Scenario: Positive control — a scheduled create still notifies
- **GIVEN** the same instance
- **WHEN** a meeting is created with `lifecycle: scheduled`
- **THEN** exactly one `meetingScheduled` notification fan-out fires

#### Scenario: Adding to a published agenda is refused
- **GIVEN** a meeting whose agenda has been published via `AgendaService::publishAgenda()`
- **WHEN** the agent invokes `decidesk.addAgendaItem` against it
- **THEN** the call is refused with a domain error and no agenda item is created (the published path requires the approval-gated `decidesk.reviseAgenda`)

### Requirement: REQ-DMCP-021 — Chat SHALL be able to command decidiq end-to-end

With the granted tools, the following conversational flows MUST be executable through the
hermiq chat companion using only tools on this surface, and SHALL be covered by acceptance
tests: (1) "Open today's board meeting and put the budget overrun on next week's draft
agenda" — `decidesk.meeting.search` → `decidesk.transitionMeeting` (approval) →
`decidesk.addAgendaItem`; (2) "What's still open from last month, and mark the audit
follow-up as done" — `decidesk.action-item.search` → `decidesk.updateActionItem`; (3)
"Publish the parking-garage decision" — `decidesk.decision.search` →
`decidesk.publishDecision` (approval, waiver-proof).

#### Scenario: Command flow with a mid-flow approval
- **GIVEN** a chair chatting with a granted agent
- **WHEN** flow (1) runs
- **THEN** the meeting opens only after the approval is confirmed, the agenda item lands on the draft agenda, and the chat reports both outcomes with object citations

#### Scenario: Read-then-write flow without approval friction
- **GIVEN** a member whose agent holds `decidesk.updateActionItem`
- **WHEN** flow (2) runs
- **THEN** the search cites the open items, the named item's `taskStatus` becomes `done` via the VTODO write path, and no approval was demanded (user-reach, non-destructive)

## MODIFIED Requirements

### Requirement: REQ-DMCP-012 — Curated non-CRUD tools live on services with honest annotations

Every curated tool MUST be a `#[McpTool]`-annotated method on a real service class and MUST
declare a `scope` and explicit hints. The curated set is no longer fixed at two: it comprises
`MeetingService::getMeetingDossier()`, `ActionItemWriter::addActionItemToMeeting()` (both
unchanged from `decidiq-mcp-adoption`), and the tools enumerated in this change's action
inventory (design.md D1/D2). Business logic MUST NOT live in an MCP provider class. The
annotation table in design.md D2 is normative for scope and hints.

#### Scenario: The full curated surface is annotated
- **GIVEN** every `#[McpTool]` attribute in `lib/`
- **WHEN** its arguments are inspected
- **THEN** each declares a non-null `scope` and an explicit `readOnlyHint`
- **AND** every write additionally declares explicit `destructiveHint` and `idempotentHint`

### Requirement: REQ-DMCP-013 — Scannable-services opt-in

Decidiq MUST register an `IMcpScannableServices` implementation naming every class that
carries a `#[McpTool]`. `DecidiqScannableServices::getScannableServiceClasses()` SHALL
return exactly the owning-service classes of the curated surface — after this change:
`MeetingService`, `ActionItemWriter`, `AgendaService`, `MotionService`, `VotingRoundOpener`,
`VotingRoundCloser`, `MinutesDraftService`, `MinutesWorkflowService`,
`ConflictOfInterestService`, `ProxyDelegationService`, `DecisionPublicationService` — and
nothing else. A class in the list without a `#[McpTool]` method, or an annotated class
missing from the list, is a defect.

#### Scenario: The list and the annotations agree both ways
- **WHEN** `getScannableServiceClasses()` is compared against a scan of `#[McpTool]` in `lib/`
- **THEN** the two sets are identical

#### Scenario: All curated tools are discoverable at runtime
- **GIVEN** the app is booted
- **WHEN** the MCP tool catalogue is listed
- **THEN** every tool id from the action inventory's ✅/♻ rows is present
