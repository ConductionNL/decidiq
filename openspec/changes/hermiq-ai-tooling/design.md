# Design: hermiq-ai-tooling

Extension of `decidesk-mcp-adoption` (read there first: its D1–D7 remain in force except
where D5 is explicitly superseded below). This document owns the **action inventory** and the
**scope × reach matrix**.

## Context: the hermiq grant model (verified surfaces)

- **Default-deny writes**: `OCA\Hermiq\Service\Engine\ToolGrantResolver::requiresGrant()` —
  hints-first (`scope`/`destructiveHint`/`readOnlyHint`, forwarded from OpenRegister's MCP
  annotations), then `{app}.{schema}.{verb}` suffix fallback, then **fail-closed**: anything
  not positively classified as a low-reach read requires a grant.
- **Reach axis**: `ToolReachResolver` — `self` | `user` | `instance` | `external`
  (`REACH_*` constants), read from a descriptor `reach` key when declared, resolved
  fail-closed to `external` otherwise. Default-deny and the approval gate key off reach **in
  union** with the write/destructive rule (`agent-capability-reach` spec) — a low-reach label
  can never un-gate a destructive tool.
- **Grants**: per agent, per tool id, optionally argument-scoped (`tool?arg=constraint`),
  with an explicit `#noapproval` waiver fragment; a wildcard cannot be argument-scoped.
- **Approval**: `ApprovalService` queues gated invocations for a human decision.
- **Dry-run**: `ToolClassificationService::isSideEffecting()` neutralises write/destructive
  and high-reach calls in previews (the `webFetch` lesson: even a `scope: read` tool is
  neutralised when its reach is external).
- **OpenRegister attribute**: `lib/Mcp/Attribute/McpTool.php` constructor —
  `name`, `description`, `readOnlyHint`, `destructiveHint`, `idempotentHint`, `scope`.
  **No `reach` parameter exists yet**; intended reach is documented here and resolved
  fail-closed (= `external`, maximally gated) until the attribute grows it.

## D1: Action inventory — every user action, dispositioned

Sources: `lib/Controller/` (39 controllers) and the owning services. Reads are already served
by decidesk-mcp-adoption's derived surface and dossier tool and are listed only where a
disposition needs stating. **Legend**: ✅ = new curated tool (this change), ♻ = existing
method gains the attribute, ✋ = withdrawn, never a tool.

| # | User action | Owning method (verified) | Disposition | Tool id |
|---|---|---|---|---|
| 1 | Schedule a meeting (draft) | `MeetingService` (new `scheduleDraftMeeting()`, pins `lifecycle: draft`) | ✅ new method | `decidesk.scheduleDraftMeeting` |
| 2 | Open / pause / adjourn / close a meeting | `MeetingService::transition(meetingId, action, userId)` | ♻ reinstated, approval-gated (supersedes mcp-adoption D5 — see D3) | `decidesk.transitionMeeting` |
| 3 | Add an item to a draft agenda | `AgendaService` (new `addItemToDraftAgenda()`; refuses published agendas) | ✅ new method | `decidesk.addAgendaItem` |
| 4 | Reorder agenda items | `AgendaService::reorderItems(meetingId, orderedIds)` | ♻ | `decidesk.reorderAgendaItems` |
| 5 | Publish the agenda | `AgendaService::publishAgenda(meetingId)` | ♻ approval-gated | `decidesk.publishAgenda` |
| 6 | Revise a published agenda | `AgendaService::reviseAgenda(meetingId)` | ♻ approval-gated | `decidesk.reviseAgenda` |
| 7 | Add a motion co-signer | `MotionService::addCoSigner(motionId, coSignerName)` | ♻ | `decidesk.addMotionCoSigner` |
| 8 | Forward a motion to another body | `MotionService::forwardMotion(motionId, targetBodyId, actorId, justification)` | ♻ approval-gated | `decidesk.forwardMotion` |
| 9 | Open a voting round | `VotingRoundOpener::openVotingRound(...)` (quorum via `checkQuorum()`) | ♻ approval-gated | `decidesk.openVotingRound` |
| 10 | Close a voting round (records official tally) | `VotingRoundCloser::close(votingRoundId, anonymise, tally)` | ♻ approval-gated | `decidesk.closeVotingRound` |
| 11 | Cast a ballot | `VoteCastingService::castVote(...)` | ✋ **withdrawn forever** (D4) | — |
| 12 | Generate a minutes draft from a transcript | `MinutesDraftService::generate(transcriptId)` | ♻ | `decidesk.generateMinutesDraft` |
| 13 | Submit minutes for approval | `MinutesWorkflowService::submitForApproval(minutesId, actorId)` | ♻ | `decidesk.submitMinutesForApproval` |
| 14 | Extract action items from minutes | `MinutesWorkflowService::extractActionItems(minutesId)` (read/propose) + `saveExtractedActionItems(minutesId, confirmed)` (write) | ✅ two tools, read and write separated | `decidesk.previewMinutesActionItems`, `decidesk.saveMinutesActionItems` |
| 15 | Add an action item | `ActionItemWriter::addActionItemToMeeting()` | already shipped by decidesk-mcp-adoption — untouched | `decidesk.addActionItem` |
| 16 | Update / complete an action item | `ActionItemWriter::update(uid, changes)` | ♻ | `decidesk.updateActionItem` |
| 17 | Delete an action item | `ActionItemWriter::delete(uid)` | ♻ approval-gated (`destructiveHint: true`) | `decidesk.deleteActionItem` |
| 18 | Declare a conflict of interest (own) | `ConflictOfInterestService::declare(...)` | ♻ | `decidesk.declareConflictOfInterest` |
| 19 | Grant a proxy vote | `ProxyDelegationService::grantProxy(votingRoundId, fromParticipantId, toParticipantId)` | ♻ approval-gated (transfers voting power) | `decidesk.grantProxy` |
| 20 | Revoke a proxy vote | `ProxyDelegationService::revokeProxy(votingRoundId, fromParticipantId)` | ♻ approval-gated | `decidesk.revokeProxy` |
| 21 | Publish a decision (Woo/ORI/public) | `DecisionPublicationService::publish(decisionId, actorUid)` | ♻ approval-gated, reach `external` | `decidesk.publishDecision` |
| 22 | Check quorum for a meeting | `VotingRoundOpener::checkQuorum(meetingId)` | ✅ genuine non-CRUD read (aggregation over participants/weights) | `decidesk.checkQuorum` |
| — | Citizen-participation writes (moderation, budget rounds, panels) | `ParticipationController`, `ReactionIntakeService`, … | ✋ out of scope — separate product surface (mcp-adoption D2) | — |
| — | eIDAS signatures, regulator exports, member import | `EIDASSignatureController`, `RegulatorExportController`, `MemberImportController` | ✋ v1 refusal: legally-binding or bulk-destructive admin acts; revisit per-action with their owners | — |

Completeness rule: any state-changing controller action not in this table must be added with
a disposition before the change ships — an unlisted action is an unmeasured one, and the
verification phase greps the controllers against the table.

## D2: Scope × reach matrix for every write

`scope` and hints go in the attribute today; `reach` is the documented intent, enforced
fail-closed as `external` until OpenRegister's `McpTool` learns `reach` (proposal, Approach).

| Tool | scope | destructive | idempotent | intended reach | why that reach | approval |
|---|---|---|---|---|---|---|
| `scheduleDraftMeeting` | create | false | false | `user` | draft visible to organisers only; **no fan-out once the `meetingScheduled` rule is lifecycle-filtered (D5)** | grant only |
| `transitionMeeting` | update | **true** | false | `instance` | lifecycle fan-out notifies the whole body; irreversible `openedAt`/`closedAt` on the official record | **always** |
| `addAgendaItem` | create | false | false | `user` | draft agenda, pre-publication | grant only |
| `reorderAgendaItems` | update | false | true | `user` | draft ordering | grant only |
| `publishAgenda` / `reviseAgenda` | update | true | false | `instance` | publication notifies participants; revision touches the published record | **always** |
| `addMotionCoSigner` | update | false | false | `user` | affects the named co-signer | grant only |
| `forwardMotion` | update | true | false | `instance` | moves a motion across governance bodies | **always** |
| `openVotingRound` | create | true | false | `instance` | starts a formal vote; notifies eligible voters | **always** |
| `closeVotingRound` | update | true | false | `instance` | fixes the official tally; feeds decision outcome | **always** |
| `generateMinutesDraft` | create | false | false | `self` | produces a draft for the invoking clerk | grant only |
| `submitMinutesForApproval` | update | false | false | `user` | routes to approvers | grant only |
| `saveMinutesActionItems` | create | false | false | `user` | creates VTODOs for assignees | grant only |
| `updateActionItem` | update | false | true | `user` | edits one assignee's item; reversible | grant only |
| `deleteActionItem` | delete | true | true | `user` | destructive but single-item, restorable from CalDAV trash where enabled | **always** |
| `declareConflictOfInterest` | create | false | false | `self` | records the **caller's own** declaration; argument-scoped grant pins `boardMemberId` to the caller | grant only |
| `grantProxy` / `revokeProxy` | update | true | false | `user` | transfers/returns one member's voting power for one round | **always** |
| `publishDecision` | update | true | false | **`external`** | leaves the instance: public Woo/DiWoo/ORI publication | **always, waiver-proof** (D6) |

Reads (`checkQuorum`, `previewMinutesActionItems`, the existing dossier): `scope: read`,
`readOnlyHint: true`, intended reach `user`.

## D3: Superseding D5 — the meeting transition returns, gated

decidesk-mcp-adoption D5 withdrew `startMeeting` for three named reasons. Each is now
structurally addressed rather than re-argued:

| D5 reason | Then | Now |
|---|---|---|
| Failing open: no `scope`, no `destructiveHint`, never stripped by default-deny, never approval-gated | true of the legacy 2-segment tool | `scope: update`, `destructiveHint: true` → `requiresGrant()` = true; default-deny until the chair grants it; approval gate on every invocation |
| Chair guard passes on every hallucinated UUID — defends the wrong user, not the wrong object | no mitigation existed | the **approval card is the wrong-object check**: it renders the resolved meeting (title, scheduledDate, governance body) before a human confirms; dry-run neutralises the call entirely |
| Blast radius: irreversible timestamp + body-wide fan-out for one click of saved convenience | correct — with no gate, the convenience never justified it | the calculus inverts: the click is no longer the alternative to *nothing*, it is the approval itself — one click, now with the object shown |

`MeetingService::transition()` already validates the lifecycle state machine and the chair
guard; the tool adds no second business-logic path (ADR-063 rule 4).

## D4: What stays withdrawn — and why no gate cures it

- **`castVote`**: a ballot is a personal democratic act by a mandated member. An agent
  casting it — even approval-gated — breaks attributability (was the judgement the
  member's?) and collides with `VotingRound.isSecret` handling and
  `VoteCastGuard`/`QuorumVerificationService` semantics built around a human actor. Proxy
  *delegation* (a formal, auditable transfer to another human) is the governed alternative
  and is on the surface.
- **Individual `Vote` reads**: unchanged from mcp-adoption D2 (secret-ballot
  reconstruction).
- **eIDAS signing** (`EIDASSignatureService`): a qualified signature is legally personal;
  same category as casting a vote.

## D5: The draft-create fan-out bug the Open Question hid

mcp-adoption's Open Question assumed a draft-only meeting create avoids the notification
blast. Measured against `decidesk_register.json`, it does not: the `meetingScheduled` rule
fires on `trigger: {type: created}` with **no lifecycle filter** (unlike `meetingReminder` /
`meetingStartingSoon`, which filter `lifecycle: scheduled`). Any create — UI, importer, or
agent — notifies today. Before `scheduleDraftMeeting` is enabled, the rule gains a
`lifecycle: scheduled` condition (same dialect as the Decision `voteRequested` rule's
`condition {field, operator, value}`), with a before/after notification count as acceptance
— positive control: a `lifecycle: scheduled` create still produces exactly one.

This also fixes the UI path: creating a draft meeting from the frontend stops notifying the
body prematurely — a pre-existing behaviour bug this change fixes rather than inherits.

## D6: Approval gates and the waiver

Hermiq grants may carry a `#noapproval` waiver. For this surface:

- Tools marked **always** in D2 SHALL be treated as approval-required; for `publishDecision`
  (reach `external`, leaves the instance irrevocably) the spec requires the gate to hold
  **even when a waiver is present** — hermiq's union rule already refuses to let a low-reach
  label un-gate a destructive tool, and the fail-closed `external` resolution keeps
  `publishDecision` in the gated set regardless of descriptor drift.
- Grant-only tools rely on default-deny + the explicit per-agent grant; an org can still
  tighten them to `confirm` via hermiq's guardrail policy (org-configurable), which decidesk
  neither reads nor overrides.

## D7: Audit trail

`AuditLogService::append()` is decidesk's tamper-evident hash chain for governance actions
(votes, conflicts, proxies, …). Requirement: **every curated write tool appends one entry**
recording `{toolId, agentId, actingUserId, objectIds, arguments-digest}` — through the same
`append()` path (which, per change `audit-log-chain-tail-hash`, resolves the previous hash
via a bounded query, so per-write cost is O(1)). Reads are not audited (they are
RBAC-scoped and would flood the chain). Where the underlying service already appends (e.g.
proxy grant/revocation per the class docblock), the tool passes agent attribution into the
existing entry instead of writing a second one — one action, one chain entry.

## D8: Alternatives considered

- **Declarative `create`/`update` verbs on schemas instead of curated tools.** Rejected:
  every write in D1 runs domain guards (`MeetingRoleGate`, `AgendaAuthorizationGuard`,
  `VotingRoundGuard`, `VoteCastGuard`, `MinutesAccessGuard`) that a derived
  `ObjectService::saveObject()` write would bypass. The dialect stays read-only, exactly as
  mcp-adoption left it.
- **One coarse `decidesk.execute(action, args)` tool.** Rejected: collapses the grant model —
  scope/reach/approval become invisible to hermiq, recreating the failing-open pattern with
  extra steps.
- **Waiting for the OR `reach` attribute before shipping.** Rejected: fail-closed resolution
  already over-gates correctly; waiting costs the capability and buys no safety.
- **Keeping `transitionMeeting` withdrawn.** Rejected as a permanent posture: the product
  vision is command-by-chat, and the named failure modes are now individually mitigated
  (D3). The withdrawal stays for actions where mitigation is impossible in principle (D4).

## Migration Plan

1. Fix the `meetingScheduled` rule condition; bump register version; measure the draft/no-notification and scheduled/one-notification pair.
2. Add the two new service methods (`scheduleDraftMeeting`, `addItemToDraftAgenda`) with guards.
3. Annotate the existing methods per D2, thin-wrapping where signatures need an agent-facing shape.
4. Extend `DecideskScannableServices::getScannableServiceClasses()`.
5. Wire audit attribution (D7).
6. Verify the live catalogue on `/api/mcp`; verify grant classification against hermiq on a dev instance (an ungranted `transitionMeeting` is stripped; a granted one queues for approval).
7. File the OpenRegister `reach` issue and link it in the PR.

**Rollback**: revert; surface returns to decidesk-mcp-adoption's exactly (see proposal).
