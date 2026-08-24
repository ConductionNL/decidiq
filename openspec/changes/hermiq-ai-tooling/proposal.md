---
kind: code
---

# Proposal: hermiq-ai-tooling

**Extends `decidiq-mcp-adoption` and MUST land after it.** That change gives decidiq a
clean, curated, read-mostly agent surface (20 derived read tools + a dossier read + one
create) and deletes the legacy `DecidiqToolProvider`. This change does not reopen any of it
— it builds the next layer on top: the write side of the product vision, made safe by the
hermiq grant model that did not exist when the legacy tools were written.

## Summary

Product framing: **every user action an app offers should also exist as an MCP tool, so any
action can in principle be automated by an AI agent — and chat becomes a way of commanding
the app.** A chair should be able to say "open today's board meeting and put the budget
overrun on next week's draft agenda" and have decidiq execute it, with exactly the same
guards a click would hit, plus the agent-specific ones a click does not need.

What makes that sane now — and made it insane before — is hermiq's grant model: every tool
is classified on **scope** (`read`/`create`/`update`/`delete`, from OpenRegister's MCP
annotations) × **reach** (`self`/`user`/`instance`/`external`, `ToolReachResolver`), writes
are **default-deny** (`ToolGrantResolver::requiresGrant()`, fail-closed on anything it cannot
positively classify as a low-reach read), users grant tools **per agent, per tool, optionally
argument-scoped**, high-impact invocations pass a **human approval gate**
(`ApprovalService`), and dry-runs **neutralise** side-effecting calls
(`ToolClassificationService::isSideEffecting()`). `decidiq-mcp-adoption` withdrew
`startMeeting` precisely because, pre-model, it was failing open. This change enumerates
decidiq's real user actions from its controllers/services, ships **one curated `#[McpTool]`
per action** on the owning service, annotates every write honestly for the grant model, and
**reinstates the meeting-lifecycle capability behind the approval gate** — the safety layer
whose absence justified the withdrawal.

## Motivation

After `decidiq-mcp-adoption`, an agent can answer nearly any question about decidiq but can
*do* almost nothing: of the dozens of state-changing user actions reachable through
`lib/Controller/` (39 controllers) and `lib/Service/`, exactly one — add an action item — has
a tool. The product promise "chat away while your apps execute your commands" needs the
verbs. Doing this per-action, on the owning services, with per-tool grant annotations is the
opposite of the old provider's failure mode: instead of five hand-rolled tools with no
governance metadata, every write declares what it touches and how far its effects travel,
and hermiq decides — per agent, per grant, per invocation — whether it runs, queues for
approval, or is denied.

## Affected Projects

- [x] Project: `decidiq` — new `#[McpTool]` methods on existing owning services
  (`MeetingService`, `AgendaService`, `MotionService`, `VotingRoundOpener`,
  `VotingRoundCloser`, `MinutesDraftService`, `MinutesWorkflowService`, `ActionItemWriter`,
  `ConflictOfInterestService`, `ProxyDelegationService`, `DecisionPublicationService`),
  extension of `DecidiqScannableServices`, one notification-rule fix, audit-trail hook.
- [ ] Project: `openregister` — **issue only, not a blocker**: add a `reach` parameter to the
  `McpTool` attribute (it has `scope`/hints but no `reach` today; see Approach).

## Scope

### In Scope

- An **action inventory** (design.md) of every state-changing user action in decidiq's
  controllers/services, each dispositioned: derived-read (already covered), curated tool
  (this change), or **withdrawn with reasons** (never a tool).
- Curated write tools, one per action, on the owning service, each annotated
  `#[McpTool(scope: ..., readOnlyHint: false, destructiveHint: ..., idempotentHint: ...)]`
  with its intended **reach** documented in the descriptor table and enforced fail-closed by
  hermiq until the attribute can carry it.
- Reinstating meeting lifecycle transitions (`MeetingService::transition()`) as approval-gated
  agent tools — explicitly superseding the "withdrawn, not replaced" posture of
  decidiq-mcp-adoption D5 *only* under the grant model's protections.
- Answering decidiq-mcp-adoption's Open Questions: draft-only `scheduleDraftMeeting` and
  `addAgendaItem` as curated tools (a curated method can pin `lifecycle: draft`; the
  declarative dialect cannot pin values on a derived `create`).
- Fixing the `meetingScheduled` notification rule so a draft-only create genuinely does not
  fan out (measured: today it triggers on `created` with **no** lifecycle filter).
- Audit-trail coverage: every curated agent write appends to the tamper-evident chain via
  `AuditLogService::append()`, recording the agent identity and tool id.
- 2–3 end-to-end chat scenarios in decidiq's domain as acceptance material.

### Out of Scope

- Any change to the 10-schema derived read surface, the dossier tool, or
  `addActionItemToMeeting` — decidiq-mcp-adoption owns those.
- `VoteCastingService::castVote()` — **withdrawn forever**, not approval-gated: casting a
  ballot is a personal democratic act; an agent voting "on behalf of" a member is a
  legitimacy problem no approval flow cures. Same for any read of individual `Vote` objects
  (secret-ballot reconstruction, mcp-adoption D2).
- Declarative write verbs on schemas (`x-openregister-mcp` `create`/`update`/`delete`) — all
  writes here are curated, because every one carries domain guards a derived CRUD write
  would bypass.
- Hermiq-side changes: the grant model, approval UI and classifiers ship in hermiq already.
- The citizen-participation subsystem's write actions (moderation, budget rounds) — a
  citizen-facing agent is a separate product surface (consistent with mcp-adoption D2).

## Approach

Same two mechanical chains as decidiq-mcp-adoption, plus governance annotations:

1. **Curated tools** — `#[McpTool]` methods on the owning services (never a provider class,
   ADR-063 rule 4), discovered via `DecidiqScannableServices`, which grows from 2 classes to
   the full owning-service list.
2. **Grant-model annotations** — `scope` + hints come from the `McpTool` attribute
   (verified parameters: `name`, `description`, `readOnlyHint`, `destructiveHint`,
   `idempotentHint`, `scope`). The attribute has **no `reach` parameter today**;
   `ToolReachResolver` reads a descriptor `reach` key when present and otherwise resolves
   **fail-closed to `external`** — which over-gates but never under-gates. We therefore ship
   with documented intended reach + fail-closed behaviour, and file an OpenRegister issue to
   add `reach` to the attribute; when it lands, decidiq declares the documented values.
3. **Approval gating** — hermiq gates write/destructive and high-reach tools for approval by
   default (`agent-capability-reach`: default-deny and the approval gate key off reach in
   union with the write/destructive rule). decidiq's job is honest annotation; the spec adds
   scenarios asserting the gate actually engages for the formal governance acts.

## New Dependencies

None at build time. Runtime governance is hermiq's (the sole agent consumer, as in
decidiq-mcp-adoption). One OpenRegister enhancement is requested (attribute `reach`) but not
depended on — fail-closed resolution covers the gap.

## Impact

- `lib/Service/*` — ~14 new thin `#[McpTool]` methods delegating to existing logic (the
  methods largely exist: `transition`, `publishAgenda`, `reorderItems`, `addCoSigner`,
  `forwardMotion`, `openVotingRound`, `close`, `generate`, `submitForApproval`, `update`,
  `declare`, `grantProxy`, `revokeProxy`, `publish`).
- `lib/Mcp/DecidiqScannableServices.php` — service-class list grows.
- `lib/Settings/decidesk_register.json` — `meetingScheduled` rule gains a lifecycle
  condition; register version bump.
- `lib/Service/AuditLogService.php` callers — agent writes append audit entries.
- Agent-visible surface: 22 tools (post-mcp-adoption) → ~36, of which ~15 are writes, every
  write default-denied until granted.

## Cross-Project Dependencies

- **decidiq-mcp-adoption** (this repo): must be merged first — this change extends its
  scannable-services opt-in and its spec capability (`mcp-tools`).
- **hermiq**: `ToolGrantResolver` / `ToolReachResolver` / `ApprovalService` /
  `ToolClassificationService` at `origin/development` — consumed, not changed.
- **OpenRegister**: `McpTool` attribute + `AttributeToolProvider` (already a dependency);
  issue to be filed for the `reach` parameter.

## Risks

### Risk 1: Reinstating meeting transitions contradicts decidiq-mcp-adoption D5
**Severity**: High — this is the change's central judgement call.
**Mitigation**: D5's stated reason was concrete: as a tool with no `scope`, no
`destructiveHint` and no approval gate, `startMeeting` was *failing open*, and the chair
guard could not stop a hallucinated UUID. Every element of that reasoning is addressed, not
argued away: the tool is annotated `scope: update, destructiveHint: true`; hermiq
default-denies it until the chair grants it; every invocation queues for human approval
showing the resolved meeting (title, date, body — the approval card is exactly the
"wrong-object" check the guard could not perform); dry-run neutralises it; and the audit
chain records it. The capability returns only *because* the failure mode it was withdrawn
for no longer exists. The spec keeps the withdrawal for `castVote`, where no gate cures the
problem.

### Risk 2: The `reach` axis is documented but not yet machine-declared
**Severity**: Medium.
**Mitigation**: Hermiq's resolver is fail-closed: an undeclared reach resolves to
`external`, the *most* gated class. Until the OpenRegister attribute gains `reach`, decidiq
tools are over-gated, never under-gated. The spec requires the documented reach table so the
values are ready the day the attribute lands.

### Risk 3: Draft-only creates still fan out notifications
**Severity**: Medium — and real, not hypothetical: the `Meeting` schema's `meetingScheduled`
rule triggers on `created` with **no lifecycle filter** (verified in
`decidesk_register.json`), so the mcp-adoption Open Question's assumption ("a draft create
avoids the blast") is false today.
**Mitigation**: This change constrains the rule to `lifecycle: scheduled` *before* enabling
`scheduleDraftMeeting`, and the task's acceptance measures notifications before/after a draft
create (positive control: a scheduled create still notifies).

### Risk 4: Tool-count dilution (~36 tools)
**Severity**: Low.
**Mitigation**: Reads stay at mcp-adoption's curated 10-schema surface; the additions are
writes an agent only sees once granted. Hermiq strips ungranted write tools from the
catalogue it offers the model, so the *effective* per-agent surface stays small by
construction.

## Rollback Strategy

Revert the commit: the `#[McpTool]` attributes and scannable-service entries disappear and
the surface returns exactly to decidiq-mcp-adoption's. The notification-rule condition is
the only register change; reverting it restores the previous rule (bump version again).
Guards and service logic are untouched by rollback since tools delegate to pre-existing
methods. No data migration; audit entries already written are ordinary chain entries and
remain valid.

## Open Questions

- Should approval for `openVotingRound`/`closeVotingRound` require a *different* human than
  the invoking chair (four-eyes), using hermiq's approval routing? Deferred to a governance
  decision per deployment.
- Should `publishDecision` (reach `external` — Woo/ORI publication) be grantable at all by
  default policy, or only via per-deployment opt-in? Spec default: grantable, always
  approval-gated, waiver ignored.
- When OpenRegister's `McpTool` gains `reach`, should hermiq treat a decidiq-declared
  `reach` as authoritative or clamp it to its own resolver's floor? To raise on the OR issue.
