# Design: Decision state machine v1

## Context

decidesk already has two proven lifecycle implementations: the meeting lifecycle (`MeetingService` transition map + `WorkflowService` per-domain policy + `MeetingTransitionGuard` quorum read) and the resolution lifecycle (`ResolutionLifecycleGuard`). Decisions get the same architecture — not a Symfony Workflow dependency. The original spec wording named the Symfony Workflow Component; this change MODIFIES that requirement to the guarded-transition-map mechanism because (a) the decidesk codebase has standardized on guard classes in `lib/Lifecycle/`, (b) a workflow library adds a composer dependency for a 7-node linear graph, and (c) the behavioural contract (valid transitions only, configurable, audited) is fully preserved.

## Decisions

### D1 — Transition map (single source of truth)

```
propose      draft         → proposed
deliberate   proposed      → deliberating
openVoting   deliberating  → voting        [quorum gate, chair-only where domain says]
decide       voting        → decided
decide       deliberating  → decided       [only in domains with allowDecideWithoutVote]
enact        decided       → enacted       [requires outcome=adopted; records enactedAt]
archive      decided|enacted → archived
```

The map lives as a const in `DecisionTransitionGuard` (pure, no DI — exhaustively unit-testable). Every state/action pair not in the map is forbidden; error messages name the allowed actions from the current state (spec scenario "Reject an invalid state transition").

### D2 — Per-domain policy (configurable transitions)

Mirrors `WorkflowService::DOMAIN_WORKFLOWS` including the #314 default-deny fallback for unknown domains:

| domain      | quorumEnforced | chairOnlyTransitions                          | allowDecideWithoutVote |
|-------------|----------------|-----------------------------------------------|------------------------|
| legislative | true           | deliberating:voting, voting:decided           | false                  |
| association | true           | deliberating:voting                           | false                  |
| corporate   | true           | deliberating:voting                           | false                  |
| operations  | false          | —                                             | true                   |
| citizen     | false          | —                                             | true                   |
| _unknown_   | true           | deliberating:voting, voting:decided           | false (restricted)     |

Domain resolution: `decision.domain ?? linkedMeeting.domain ?? 'operations'` — same chain the meeting service uses. `allowDecideWithoutVote` covers operational MT decisions recorded without a formal voting round (user story 3) and is the concrete per-domain transition-graph configurability the spec requires.

### D3 — Quorum gate before `voting`

Entering `voting` in a quorum-enforced domain with a linked meeting requires the meeting's declaratively computed `quorumMet === true` (same field `MeetingTransitionGuard` reads; computed by `x-openregister-calculations`). Standalone decisions (no linked meeting — explicitly supported by the creation requirement and the BW 2:40 written-resolution story) skip the meeting-quorum gate; their voting legitimacy is carried by the voting-round's own `quorumMet`.

### D4 — Chair-only enforcement (fail closed)

When the domain marks a transition chair-only: resolve `meeting.chair` (Participant UUID) → Participant → `nextcloudUserId`, compare with the session UID. If a chair-only transition is requested and no chair can be resolved (no linked meeting, missing participant), the transition is REJECTED — never fail open (hydra unsafe-auth-resolver rule).

### D5 — Authorization model

Endpoints are `#[NoAdminRequired]`; per-object authorization is OpenRegister ObjectService RBAC (find() returns null without read access; saveObject() throws without write access) — the approved ADR-005 pattern documented on `MeetingController`, because chairs/clerks are not NC admins. The chair gate (D4) layers role semantics on top. All auth methods introduced are invoked from the transition path (no orphan auth).

### D6 — Audit

Every successful transition appends a `decision-transition` entry to the existing hash-chained `AuditLogService` (actor, decision UUID, payload `{action, from, to, comment}`). The action value is added to `AuditLogService::ACTIONS` and the `BoardAuditLogEntry` schema enum (additive). A failed audit append does not roll back the transition but is logged loudly — the OR object's own audit trail still records the field change (belt-and-braces, consistent with how minutes transitions behave).

### D7 — Schema is additive

`lifecycle` (enum, default `draft`) + `enactedAt` (date-time) added to `Decision`; `isPublished` (publication axis) and `outcome` (result axis) keep their exact shapes. Objects created before this change have no `lifecycle` value and are treated as `draft` by the guard's `?? 'draft'` read — no migration required. Seeds get explicit lifecycle values so lists/dashboards demo sensibly.

### D8 — Detail view = declarative tabs

`DecisionDetail` keeps its manifest `type: "detail"` page; the two new surfaces are sidebar-tab components registered in `registry.js` (kind: page), exactly like `MotionVotesTab`. The Voting tab walks decision → `motion` relation → voting-rounds → votes (tallies are first-class fields on voting-round: `votesFor/votesAgainst/votesAbstain`). The Lifecycle tab calls `GET .../transitions` for truth (never recomputes policy client-side) and `POST .../transition` for actions.

## Risks / Trade-offs

- **Two lifecycle-ish fields** (`lifecycle` vs `outcome`/`isPublished`): mitigated by docs in the schema descriptions — lifecycle = procedure position, outcome = result, isPublished = visibility.
- **Historic decisions show `draft`**: acceptable; secretaries can transition them or leave them; seeds are updated.
- **Notification on propose** relies on the ADR-031 `updated`-trigger dialect; if the engine build lags, the rule is inert (declarative, no imperative fallback by design).
