# Proposal: decidiq-mcp-adoption

## Summary

Adopt ADR-063 (MCP as Platform Abstraction) in Decidiq: replace the hand-written `DecidiqToolProvider` (5 tools, ~1280 lines) with OpenRegister's declarative surfaces. A curated set of **10 of Decidiq's 37 schemas** opts in to schema-derived CRUD via the `x-openregister-mcp` dialect — **read verbs only, zero declarative writes**. Two of the five hand-written tools survive as genuine non-CRUD `#[McpTool]` methods on real service classes; two are deleted as derivable CRUD; and one — `startMeeting` — is **deliberately removed from the agent surface entirely** because advancing a meeting's governance lifecycle is a destructive, notification-emitting act that an LLM must not be able to misfire.

## Motivation

Decidiq was the ADR-034/035 MCP pilot. Its provider predates ADR-063 and is now the fleet's clearest example of the three failure modes the ADR exists to prevent:

1. **Business logic trapped in an MCP provider.** `DecidiqToolProvider` carries its own authorization helpers (`requireChairOrAdmin`, `requireParticipantOrAdmin`, `isAdmin`, `getCallerMeetingUuids`), its own UUID/date validators, and its own deep-link builder — none of which the rest of the app can reach. ADR-063 rule 4: business logic belongs in a service.
2. **A curated write tool with no governance annotations.** `decidesk.startMeeting` and `decidesk.addActionItem` are 2-segment curated tools that declare **no `scope` and no `destructiveHint`**. Hermiq classifies write/destructive tools from the 3-segment verb suffix; a 2-segment tool that declares no hints is never stripped by default-deny and never gated for approval. Both of Decidiq's write tools are therefore currently **failing open** — an agent can start a governance meeting with no approval gate. This is the exact governance hole ADR-063 rule 2 describes.
3. **Hand-written filters that do not match the schema.** Two of the five tools filter on properties that do not exist:
   - `listOpenActionItems` filters `completed => false`; the `ActionItem` schema has **no `completed` property** (the real field is `taskStatus`).
   - `listRecentMeetings` accepts `statusFilter: "in-progress"`; the `Meeting.lifecycle` enum is `draft|scheduled|opened|paused|adjourned|closed` — **`in-progress` is not a member**, so that filter can only ever return nothing.

   Both bugs are invisible today because the provider hand-rolls its filter dict. The declarative dialect cannot express them: OpenRegister's `McpAnnotationValidator` rejects any `search` filter that is not a real property of the schema, so adopting the dialect **structurally prevents this class of bug**.

Doing this now keeps Decidiq aligned with the fleet wave (pipelinq, hermiq, openconnector) and lets OpenRegister be the single registry for both MCP surfaces.

## Affected Projects

- [ ] Project: decidiq — declare `x-openregister-mcp` on 10 schemas; delete `DecidiqToolProvider`; move 2 curated tools onto `MeetingService` / `ActionItemWriter` with `#[McpTool]`; add `DecidiqScannableServices` opt-in; register it in `Application.php`.

## Scope

### In Scope

- Declare `x-openregister-mcp` (root-level, folded into `configuration` by `Schema::hydrate()`) on exactly 10 schemas: `meeting`, `decision`, `agenda-item`, `action-item`, `minutes`, `governance-body`, `person`, `membership`, `voting-round`, `conflict-of-interest`.
- Every opted-in schema declares **`search` + `get` only**, each with `scope: read` and `readOnlyHint: true`. No `create`, `update`, or `delete` verb is declared on any schema.
- Delete `lib/Mcp/DecidiqToolProvider.php` entirely (it ends up with zero tools).
- Move `getMeetingDetails` → `MeetingService::getMeetingDossier()` with a read-only `#[McpTool]`.
- Move `addActionItem` → `ActionItemWriter::addActionItemToMeeting()` with `#[McpTool(scope: 'create')]` and honest hints — the **only** write on Decidiq's entire agent surface.
- Add `lib/Mcp/DecidiqScannableServices.php` implementing `IMcpScannableServices`, registered in `Application.php`.
- Preserve the participant/chair authorization guards by relocating them into the receiving services — no guard is dropped.

### Out of Scope

- **`decidesk.startMeeting` is removed from the agent surface and NOT replaced.** `MeetingService::transition()` keeps working for the UI; it simply carries no `#[McpTool]`. See Risk 1.
- No declarative write verbs. Scheduling meetings, creating decisions, and editing agendas remain human-only actions for now (see Open Questions).
- The 27 non-curated schemas stay off the MCP surface (see design.md for the full leave-off table).
- No changes to OpenRegister. No changes to Hermiq.
- No changes to the Vue frontend.

## Approach

Three mechanical chains, all provided by OpenRegister at `origin/development`:

1. **Declarative CRUD** — `x-openregister-mcp` blocks in `lib/Settings/decidesk_register.json`. `Schema::hydrate()` folds root-level `x-openregister-*` keys into `configuration`; `SchemaMapper` validates them with `McpAnnotationValidator`; `SchemaDerivedToolProvider` emits `decidesk.{slug}.{verb}` tools. Decidiq already uses root-level placement for `x-openregister-lifecycle` / `-notifications` / `-calculations`, so this matches the existing file convention.
2. **Curated non-CRUD** — `#[McpTool]` on service methods, discovered via the app's `IMcpScannableServices` implementation.
3. **Deletion** — the provider class, once emptied, is removed so no dead seam remains.

## New Dependencies

None. Every contract (`McpTool`, `IMcpScannableServices`, `McpAnnotationValidator`, `SchemaDerivedToolProvider`) already ships in OpenRegister at `origin/development`.

## Impact

- `lib/Settings/decidesk_register.json` — 10 schemas gain an `x-openregister-mcp` block. No existing key is modified or removed.
- `lib/Mcp/DecidiqToolProvider.php` — **deleted**.
- `lib/Mcp/DecidiqScannableServices.php` — **new**.
- `lib/Service/MeetingService.php` — gains `getMeetingDossier()` (+ the participant guard it inherits from the provider).
- `lib/Service/ActionItemWriter.php` — gains `addActionItemToMeeting()` (+ the participant guard).
- `lib/AppInfo/Application.php` — registers the scannable-services opt-in; drops the provider registration.
- Agent-visible tool surface changes from 5 hand-written tools to 20 derived read tools + 2 curated tools.

## Cross-Project Dependencies

- **OpenRegister** (`origin/development`): supplies all three chains. Read-only dependency; nothing to change there.
- **Hermiq**: the sole agent consumer. It gains correctly-annotated tools; the previously-failing-open `startMeeting` disappears from its catalogue.

## Risks

### Risk 1: Removing `startMeeting` is a capability regression for agent users

**Severity**: Medium

An agent can no longer open a meeting on the chair's behalf.

**Mitigation**: This is intentional, not collateral. Opening a meeting writes an irreversible `openedAt` timestamp onto the official record, starts the meeting-cost clock, and fires the `meetingScheduled`/lifecycle notification fan-out to the entire governance body. The existing `requireChairOrAdmin` guard does **not** mitigate an LLM misfire — the MCP caller *is* the chair, so the guard passes on every mis-targeted UUID a chair's assistant hallucinates. The blast radius (a whole body notified, an official record falsely timestamped) is far larger than the convenience saved, and the human path is one click in the UI. `MeetingService::transition()` is untouched, so the capability is only withdrawn from *agents*.

### Risk 2: Declaring a `create` verb on `action-item` would emit a permanently broken tool

**Severity**: Medium

`ActionItem` carries `x-openregister-object-source: {provider: caldav-vtodo, readOnly: true}` — it is a read-only OpenRegister projection over CalDAV VTODOs (ADR-002). A derived `create` tool would call `ObjectService::saveObject()`, which the projection **rejects**.

**Mitigation**: `action-item` declares `search` + `get` only. Action-item creation stays on the curated `ActionItemWriter` path — the sole legal write route — which is precisely why `addActionItem` is retained as a curated tool rather than deleted as derivable CRUD.

### Risk 3: Individual `Vote` records are privacy-sensitive

**Severity**: Medium

`VotingRound.isSecret` exists; exposing a `vote.search` tool would let an agent reconstruct individual ballots from a secret vote.

**Mitigation**: `vote` is **not** opted in. Aggregate results (`votesFor` / `votesAgainst` / `votesAbstain` / `result` / `quorumMet`) are exposed on `voting-round`, which is the correct granularity for every plausible agent question. Same reasoning excludes `evaluation-response` (anonymous board self-evaluation).

### Risk 4: 10 schemas × 2 verbs = 20 derived tools may dilute LLM tool selection

**Severity**: Low

Naive tool explosion degrades accuracy (~9.5% per Specter research).

**Mitigation**: 10 of 37 schemas (27%) sits inside ADR-063's 5–15 target band, and every inclusion is justified line-by-line in design.md against a plausible agent question. Each verb carries hand-written agent-facing prose rather than OpenRegister's generic fallback description, which is what actually drives correct selection.

## Rollback Strategy

Revert the commit. The dialect is purely additive JSON (removing the `x-openregister-mcp` blocks returns every schema to `enabled: false` by omission — the default is OFF), and restoring `DecidiqToolProvider.php` + its `Application.php` registration restores the previous 5-tool surface exactly. No data migration, no schema-version bump, no persisted state.

## Open Questions

- Should `meeting.create` be enabled later in a **draft-only** form (forcing `lifecycle: draft` so the `meetingScheduled` notification fan-out does not fire)? "Schedule next month's board meeting" is a genuinely attractive agent ask; the notification blast radius is the only blocker, and a draft-only create would remove it.
- Should `agenda-item.create` be enabled for the same reason (adding an item to a not-yet-published agenda is low-risk)? Deferred pending the draft-only pattern above.
