# Design: decidesk-mcp-adoption

## Context

Decidesk was the ADR-034/035 MCP pilot. `lib/Mcp/DecideskToolProvider.php` (1,279 lines) hand-writes 5 tools, their input schemas, their authorization, their validators, and their citation builder. ADR-063 (merged, hydra #102) makes all of that OpenRegister's job.

Three OpenRegister chains, all verified at `origin/development` on 2026-07-13:

| Chain | Contract | Emits |
|---|---|---|
| 1. Declarative CRUD | `x-openregister-mcp` on a schema | `decidesk.{slug}.{verb}` |
| 2. Curated non-CRUD | `#[McpTool]` on a service method | `decidesk.{toolName}` |
| 3. Opt-in | `IMcpScannableServices::getScannableServiceClasses()` | tells OR which classes to scan |

**Placement (verified).** `Schema::hydrate()` folds root-level `x-openregister-*` keys into `configuration` before validation (`Schema.php`, the `ANNOTATION_VOCABULARY` allow-list — `x-openregister-mcp` is a member). `SchemaMapper` then validates `configuration['x-openregister-mcp']` with `McpAnnotationValidator`, and `SchemaDerivedToolProvider` reads it from the same place. Decidesk already places `x-openregister-lifecycle` / `-notifications` / `-calculations` / `-aggregations` at **schema root**, so the MCP block goes at schema root too — matching the file's existing convention rather than pipelinq's `configuration{}` nesting. Both land in the same column.

**Dialect grammar (from `McpAnnotationValidator`).**
- `enabled: bool` — **required**, the opt-in gate. Default (absent) is OFF.
- `tools: { <verb>: {...} }` — verbs closed to `search|get|create|update|delete`.
- Per-verb keys closed to `description`, `scope`, `filters`, `readOnlyHint`, `destructiveHint`, `idempotentHint`.
- `scope` ∈ `read|create|update|delete`.
- `filters` — **`search` only**, and every entry **MUST be a real property of that schema** or the schema is rejected at import.

## Goals / Non-Goals

**Goals**
- Zero hand-written MCP tool code left in Decidesk.
- Every agent-visible write carries an honest `scope` + hints.
- A curated, defensible read surface — not blanket exposure.

**Non-Goals**
- Not adding agent write access to governance state. This change *reduces* it.
- Not touching OpenRegister, Hermiq, or the Vue frontend.
- Not exposing the citizen-participation subsystem (a separate product surface).

## Decisions

### D1: Curate 10 of 37 schemas — read verbs only

ADR-063 targets 5–15 schemas; 10 is 27% of Decidesk's 37. Every inclusion answers a question a human would plausibly put to an assistant. **No schema declares a write verb.**

| # | Schema (slug) | Verbs | `search` filters (all verified real properties) | Why an agent needs it |
|---|---|---|---|---|
| 1 | `meeting` | search, get | `lifecycle`, `meetingType`, `governanceBody`, `chair`, `isPublic`, `scheduledDate` | The central entity — "what meetings do I have / when did the board last meet?" |
| 2 | `decision` | search, get | `decisionType`, `outcome`, `lifecycle`, `isPublished`, `proposer`, `decisionDate` | The universal decision supertype — **motions, amendments and resolutions all live here** (`decisionType` enum); "what did we decide about X?" |
| 3 | `action-item` | search, get | `taskStatus`, `assignee`, `dueDate`, `meeting`, `decision` | "What are my open action items?" — the single most common ask. Reads only (see D3). |
| 4 | `agenda-item` | search, get | `itemType`, `meeting`, `isRecurring` | "What's on Tuesday's agenda?" and cross-meeting topic lookup. |
| 5 | `minutes` | search, get | `lifecycle`, `meeting` | The official record — "get me the approved minutes of the June meeting." |
| 6 | `governance-body` | search, get | `bodyType`, `domain` | "Which committees exist? What is the audit committee's quorum rule?" |
| 7 | `person` | search, get | `name`, `email` | Resolves a proposer/chair/assignee UUID to a human name — without it every other answer is a bare UUID. |
| 8 | `membership` | search, get | `role`, `person`, `governanceBody`, `party` | The **only** place `role` lives — "who chairs the audit committee?" is unanswerable from Person + GovernanceBody alone. Popolo first-class entity, not a join table. |
| 9 | `voting-round` | search, get | `result`, `quorumMet`, `votingMethod`, `decisionStage` | "How did the vote go? Was quorum met?" Aggregate tallies only (see D4). |
| 10 | `conflict-of-interest` | search, get | `boardMember`, `agendaItem`, `declarationType`, `severity` | Core governance-compliance ask — "were any conflicts declared on this item?" Decidesk is a compliance product; this is its differentiator. |

### D2: What is left OFF, and why

27 schemas stay off. Default is OFF, so this is simply the absence of a block.

| Left off | Reason |
|---|---|
| `vote` | **Privacy.** `VotingRound.isSecret` exists — a `vote.search` tool lets an agent reconstruct individual ballots from a secret vote. Aggregates on `voting-round` are the right granularity. |
| `evaluation-response`, `board-evaluation`, `evaluation-template` | **Anonymity.** Board self-evaluation responses are anonymous by design; an agent-queryable surface risks de-anonymisation by correlation. |
| `transcript` | Consent- and retention-gated (`consent`, `retentionState`), large payloads, and `minutes` is the canonical record of what a meeting decided. |
| `decision-stage` | Internal routing machinery. `decision.lifecycle` already answers "where is this decision?" |
| `participant` | **DEPRECATED** in the schema itself — a shim superseded by Person + Membership (ADR-001 §2). |
| `public-consultation`, `consultation-reaction`, `citizen-vote`, `citizen-panel`, `participatory-budget`, `budget-proposal`, `deliberation` | The citizen-participation subsystem — a distinct product surface with a distinct (public) audience. Would double the tool count for an internal governance agent that will never be asked about it. Revisit if a citizen-facing agent is built. |
| `notification`, `notification-preference` | Internal delivery config, not governance content. |
| `publication-record`, `publication-payload` | Publishing bookkeeping internals. |
| `engagement-record` | Analytics internals (derived speech/question counts). |
| `process-template` (register.d) | Configuration object. |
| `post`, `contact-detail` | Org-structure internals / PII sub-objects; `membership` + `person` cover the answerable questions. |
| `digital-document`, `monetary-amount`, `offer`, `order`, `product`, `report` | schema.org **value-object leaves** — they exist to be embedded in a Decision, never queried standalone. |

### D3: `action-item` gets NO declarative write verb — because it structurally cannot have one

`ActionItem` carries `x-openregister-object-source: {provider: "caldav-vtodo", readOnly: true}`. It is a **read-only OpenRegister projection over CalDAV VTODOs** (ADR-002); the VTODO is the authoritative record and `ObjectService::saveObject()` **rejects** writes to the projection. A derived `create`/`update`/`delete` tool would call exactly that method and fail every time — a permanently broken tool in the catalogue.

This is precisely why `addActionItem` is **retained** as a curated tool rather than deleted as "derivable CRUD": `ActionItemWriter` is the only legal write path, so the behaviour is genuinely non-CRUD from OpenRegister's point of view.

### D4: Provider surgery — tool-by-tool classification

| # | Tool | Verdict | Destination / rationale |
|---|---|---|---|
| 1 | `decidesk.listOpenActionItems` | **(a) DELETE — derivable** | Superseded by `decidesk.action-item.search` (filters `taskStatus`, `assignee`). It is plain filtered CRUD. Its `scope=mine` argument is redundant: `assignee` is a real, declarable filter and OpenRegister's derived search is already RBAC-scoped. **Also fixes a live bug** — the handler filters `completed => false`, and `ActionItem` has **no `completed` property** (the field is `taskStatus`). |
| 2 | `decidesk.listRecentMeetings` | **(a) DELETE — derivable** | Superseded by `decidesk.meeting.search` (filters `lifecycle`, `meetingType`, …). **Also fixes a live bug** — its `statusFilter` enum offers `"in-progress"`, which is **not a member** of the `Meeting.lifecycle` enum (`draft\|scheduled\|opened\|paused\|adjourned\|closed`), so that filter can only ever return zero rows. |
| 3 | `decidesk.getMeetingDetails` | **(b) KEEP — genuine non-CRUD** | A **read aggregation** across 4 schemas (meeting + agenda items + decisions + action items) in one call. "What happened at Tuesday's board meeting?" is the canonical ask; the derived surface would need 4 round-trips. Moves to **`MeetingService::getMeetingDossier()`** with `#[McpTool(readOnlyHint: true, scope: 'read')]`. The participant guard moves with it. |
| 4 | `decidesk.startMeeting` | **(c) DELETE — write REFUSED** | Not derivable (a guarded lifecycle transition), but **deliberately not re-exposed either**. See D5. `MeetingService::transition()` survives untouched for the UI; it simply carries no `#[McpTool]`. |
| 5 | `decidesk.addActionItem` | **(b) KEEP — genuine non-CRUD** | Not derivable (D3: CalDAV write path). Moves to **`ActionItemWriter::addActionItemToMeeting()`** with `#[McpTool(scope: 'create', readOnlyHint: false, destructiveHint: false, idempotentHint: false)]`. The participant guard moves with it. |

**The provider ends with zero tools → `lib/Mcp/DecideskToolProvider.php` is DELETED.** No empty seam is left behind (ADR-063).

### D5: `startMeeting` — the write verb this change deliberately refuses

Opening a meeting is a **formal governance act**, not a convenience. It:
- writes an irreversible `openedAt` timestamp onto the official record,
- starts the `meetingCost` clock,
- fires the meeting lifecycle notification fan-out (`x-openregister-notifications` on `Meeting`) to the entire governance body.

The existing `requireChairOrAdmin` guard does **not** mitigate the actual risk. The MCP caller *is* the chair — so the guard passes on **every** meeting UUID the chair's assistant hallucinates. The guard defends against the wrong *user*; it does nothing against the wrong *object*. A single mis-resolved UUID notifies a whole body and falsely timestamps an official record, and there is no undo.

The human path is one click in the UI. **The convenience does not come close to justifying the blast radius, so the tool is withdrawn from the agent surface.** Today it is worse than merely risky: as a 2-segment curated tool with **no `scope` and no `destructiveHint`**, it is invisible to Hermiq's write/destructive classifier — never stripped by default-deny, never gated for approval. It is **failing open right now**.

Had we kept it, the honest annotation would have been `#[McpTool(scope: 'update', destructiveHint: true, idempotentHint: false, readOnlyHint: false)]` — and a `destructiveHint: true` tool that advances governance state is one no agent should hold by default. We remove it instead.

The same reasoning is why **no schema declares `create`/`update`/`delete`**: creating a Decision, advancing a Decision's lifecycle, or editing Minutes are all governance acts with notification fan-out and audit consequences.

### D6: The one write we DO allow, and why

`addActionItem` is the single write on Decidesk's whole agent surface. It is defensible where `startMeeting` is not:

- **Additive** — it creates a new object; it mutates no existing governance state.
- **Trivially reversible** — delete the VTODO.
- **Low blast radius** — notifies one assignee, not a governance body.
- **The canonical use-case** — "capture the action items from this meeting" is *the* reason a meeting app has an assistant.

Annotated honestly: `scope: 'create'`, `readOnlyHint: false`, `destructiveHint: false` (a create destroys nothing), `idempotentHint: false` (calling twice creates two items).

### D7: Alternatives considered

- **Keep the provider, just add hints.** Rejected: leaves 1,279 lines of business logic in an MCP provider (ADR-063 rule 4), keeps both schema-mismatch bugs, and leaves CRUD hand-written where the platform derives it.
- **Declare all 37 schemas.** Rejected: blanket exposure is the documented failure mode (~9.5% accuracy degradation, 30k+ tokens) and would expose secret ballots and anonymous evaluations.
- **Nest the dialect under `configuration{}` like pipelinq.** Rejected: Decidesk's register consistently uses root-level `x-openregister-*`, and `Schema::hydrate()` folds root keys into `configuration` anyway. Root placement is both correct and consistent.
- **Move `addActionItem` onto `MeetingService`.** Rejected: `ActionItemWriter` is the ADR-002 canonical write path; putting an action-item write on `MeetingService` would sprawl business logic across services.

## Risks / Trade-offs

- [Agents lose `startMeeting`] → Intentional (D5). The UI path is unchanged; only the *agent* capability is withdrawn.
- [`action-item` create could be mistakenly declared later] → D3 documents the CalDAV constraint in the spec delta so a future change cannot "helpfully" enable it without hitting the reasoning.
- [20 derived tools is more than the 5 hand-written ones] → But they are 3-segment, correctly-annotated, and individually described. Tool *count* is not the risk; unannotated write tools and undescribed tools are.
- [A `search` filter naming a non-existent property] → OpenRegister's `McpAnnotationValidator` rejects the schema at import, so this fails loudly at deploy rather than silently at query time. All 10 filter lists were cross-checked against the schemas' `properties` blocks.

## Migration Plan

1. Add the 10 `x-openregister-mcp` blocks to `lib/Settings/decidesk_register.json`; `python3 -m json.tool` after every edit.
2. Add `getMeetingDossier()` to `MeetingService` and `addActionItemToMeeting()` to `ActionItemWriter`, relocating the participant guards.
3. Add `lib/Mcp/DecideskScannableServices.php`; register it in `Application.php`; drop the provider registration.
4. Delete `lib/Mcp/DecideskToolProvider.php` and its unit/integration tests.
5. Bump the register version so the repair-step importer re-imports (schema re-import is version-gated).
6. Verify: `decidesk.meeting.search` and `decidesk.action-item.search` appear on `/api/mcp`; `decidesk.startMeeting` does **not**; `decidesk.addActionItem` reports `scope: create`.

**Rollback**: revert the commit. The dialect is additive JSON (absence = OFF) and the provider is restored verbatim. No data migration.

## Open Questions

- Should `meeting.create` be enabled later in a **draft-only** form (forcing `lifecycle: draft` so the notification fan-out cannot fire)? That would make "schedule next month's board meeting" safe. It needs an OpenRegister feature the dialect does not have today: **the ability to pin a property value on a derived `create`**. Worth raising as an OR issue.
- Same question for `agenda-item.create`.
- Should the retired `@spec` tags pointing at `openspec/changes/decidesk-mcp-tools/...` (an **archived** change dir — a gate-46 dangling reference) be repointed at `openspec/specs/mcp-tools/spec.md` fleet-wide? This change fixes them only in the files it touches.
