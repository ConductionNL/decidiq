# Proposal: Decision state machine v1 (full lifecycle + detail visualization)

## Why

The decision-management spec (status: partial) promises a full configurable decision lifecycle — `draft → proposed → deliberating → voting → decided → enacted → archived` — but the code only ships a simplified publication flag (`isPublished` internal/public via `DecisionController::publish` and `LiveDecisionService`). The 2026-06-12 audit flags exactly four gaps: the 7-state machine, domain-configurable transition policy, state visualization on the detail view, and voting results on the detail view. Without the state machine, decidesk cannot answer "where is this decision in its formal procedure?" (Awb 3:40-3:45, Gemeentewet 56) and the detail view cannot show what happened in the vote that produced the decision.

## What Changes

- **Lifecycle field**: the `Decision` schema gains an additive `lifecycle` enum (`draft|proposed|deliberating|voting|decided|enacted|archived`, default `draft`) and an `enactedAt` timestamp. `isPublished` and `outcome` are untouched — publication remains an orthogonal axis (a decision can be `enacted` and `internal`).
- **Guarded transition map** (decidesk pattern, NOT a Symfony Workflow dependency): a pure `DecisionTransitionGuard` in `lib/Lifecycle/` holding the transition map and per-domain policy (quorum enforcement, chair-only transitions, decide-without-vote for operational domains), mirroring `MeetingTransitionGuard`/`WorkflowService`. The spec's "Symfony Workflow Component" wording is MODIFIED to the guarded-transition-map mechanism — the behavioural contract (only valid transitions, configurable per domain) is unchanged.
- **Transition orchestration**: `DecisionLifecycleService::transition()` loads the decision via OpenRegister ObjectService (per-object RBAC), validates the action against the guard, enforces chair-only transitions (meeting chair Participant → NC UID resolution, fail-closed), blocks entering `voting` when the linked meeting's quorum is not met, requires `outcome=adopted` before `enact`, persists the new lifecycle, and appends a hash-chained `decision-transition` audit entry via the existing `AuditLogService`.
- **Endpoints**: `POST /api/decisions/{decisionId}/transition` and `GET /api/decisions/{decisionId}/transitions` (current state + allowed next actions), both `#[NoAdminRequired]` with per-object authorization through ObjectService RBAC (approved ADR-005 pattern, same as `MeetingController::lifecycle`).
- **Detail view**: two new declarative sidebar tabs on `DecisionDetail` (manifest + registry pattern): a **Lifecycle** tab visualizing the state flow (done/current/upcoming) with allowed-transition action buttons, and a **Voting** tab tallying for/against/abstain from the voting-round/vote objects linked via the decision's motion. The Decisions index gains a `lifecycle` badge column so status filtering works on the new field.
- **Notification**: a declarative ADR-031 `x-openregister-notifications` rule notifies governing-body members when a decision enters `proposed`.

## Capabilities

### New Capabilities

_None — this closes gaps in an existing capability._

### Modified Capabilities

- `decision-management`: MODIFIED requirements — "Decision State Machine" (guarded transition map instead of Symfony Workflow, per-domain policy, quorum gate, chair-only gates, audit per transition) and "Decision Detail View" (state visualization + voting results tabs now concrete).

## Impact

- **Schema** (`lib/Settings/decidesk_register.json`): additive `lifecycle` + `enactedAt` on `Decision` (version bump), `decision-transition` added to the `BoardAuditLogEntry` action enum, one new notification rule. No renames, no breaking changes; existing seeds gain explicit lifecycle values.
- **Backend**: new `lib/Lifecycle/DecisionTransitionGuard.php`, new `lib/Service/DecisionLifecycleService.php`, two methods on `DecisionController`, two routes, DI registrations in `Application.php`.
- **Frontend**: `DecisionLifecycleTab.vue` + `DecisionVotingTab.vue` in `src/components/tabs/`, a pure `decisionLifecycle.js` helper, registry + manifest wiring. All object reads via the shared object store.
- **Tests**: PHPUnit (full allowed/forbidden transition matrix on the guard — mandatory; service orchestration; controller auth), vitest (lifecycle helper), Newman (API contract incl. 401/422), Playwright (UI-only, lifecycle tab + voting tab).
- **Out of scope**: decision-to-decision relations (separate `decision-relations` change), publication flow changes, retroactive lifecycle backfill of historic objects beyond seeds.
