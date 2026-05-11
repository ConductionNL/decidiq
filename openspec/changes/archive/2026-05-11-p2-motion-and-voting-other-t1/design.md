<!-- status: proposed -->

## Context

Decidesk is a Nextcloud app using the thin-client pattern: all domain data is stored in OpenRegister; the backend provides only settings, business-rule services, and PDF generation. The `Motion`, `VotingRound`, and `Vote` entities were introduced in ADR-000 and the full motion-and-voting lifecycle was delivered in p2-motion-and-voting. This change adds five advanced capabilities on top of that foundation, all driven by market demand from tender specifications and open-source governance tooling.

Dutch governance procedures impose strict legal requirements on secret ballots: the identity of a voter must be irrevocably separated from their vote value. Corporate board elections (bestuurskiesrecht) and water board elections (dijkgraafverkiezing) routinely require secret ballots. Municipal council motions can carry configurable approval thresholds (simple majority, absolute majority, two-thirds). These domain-specific requirements call for: (1) server-enforced ballot anonymity, (2) flexible workflow configuration per governance body, (3) a post-vote challenge and recount mechanism, (4) instant notification to all eligible voters, and (5) ranked-choice voting for elections with multiple candidates.

## Goals / Non-Goals

**Goals:**
- Backend-enforced secret ballot anonymisation for VotingRounds with `isSecret: true`
- Per-GovernanceBody configuration of permitted motion types and lifecycle transition rules via the existing `workflowTemplate` field
- Post-close recount workflow with discrepancy detection and `"disputed"` result state
- Read-only auditor access to vote data on non-secret rounds
- Automatic Nextcloud notification to all eligible Participants on VotingRound open, with live distribution tracking
- Ranked-choice (preferential) ballot with Borda count tallying and ranking table display

**Non-Goals:**
- AI-assisted motion drafting (future AI spec)
- External electronic voting platforms (e-voting via separate IdP — future Internet voting spec)
- Full SRD II proxy voting disclosure automation (future compliance spec)
- Video/webcast indexing of vote events (future media spec)
- Blockchain-based ballot immutability (future trust spec)
- Deliberative polling / structured citizen participation (future participation spec)

## Decisions

### 1. Secret ballot masking at the controller layer via `SecretBallotGuard`
**Decision**: Anonymisation for secret VotingRounds is enforced by a `SecretBallotGuard` class injected into `VotingController`. When a `GET /api/votes` or `GET /api/votes/{id}` request targets a secret VotingRound, the guard replaces the `value` field with `"anonymous"` and removes the relation to the voting Participant before the response is serialised. The `VotingRound` aggregate counts (`votesFor`, `votesAgainst`, `votesAbstain`) remain fully visible.
**Rationale**: Masking at the controller layer — not the service or storage layer — keeps Vote objects intact for legitimate audit access by the system (recount logic reads actual values). Masking in the guard is stateless and easy to unit-test. ADR-005 mandates backend enforcement; frontend-only masking is explicitly a security vulnerability.
**Alternative considered**: Storing votes in a separate encrypted table — rejected (adds infrastructure complexity; OpenRegister object storage with controller-layer masking achieves the same security guarantee without a schema migration or new storage layer).

### 2. Motion workflow configuration stored as JSON in `GovernanceBody.workflowTemplate`
**Decision**: Per-GovernanceBody motion configuration (permitted `motionType` values, allowed lifecycle transition pairs, majority threshold rule) is serialised as a JSON object and stored in `GovernanceBody.workflowTemplate`. The admin settings page provides a visual editor that reads and writes this field. `MotionService::transitionLifecycle()` deserialises the template at runtime and validates the requested transition against it, falling back to the platform default if the field is empty.
**Rationale**: `workflowTemplate` is already defined as a `string` field on `GovernanceBody` in ADR-000 and was explicitly described as "State machine workflow config". No new entity, no schema migration. The JSON encoding is equivalent to what a workflow engine would store but without the overhead of a full engine at this phase.
**Alternative considered**: A dedicated WorkflowConfig entity — rejected (ADR-000 is the source of truth; the existing field is sufficient; a dedicated entity adds a new schema and one-to-one join for no benefit at p2 scope).

### 3. Recount stores comparison in a structured note on VotingRound
**Decision**: `VotingService::recount()` re-tallies all `Vote` objects related to the VotingRound, compares the new counts to the stored `votesFor`/`votesAgainst`/`votesAbstain`, and if any count differs by more than the configured tolerance (default: 0), creates a structured note on the VotingRound with `title: "Hertelverzoek"` and a JSON body containing `{ "originalFor": N, "recountFor": N, ... }` and sets `VotingRound.result` to `"disputed"`. The chair/secretary then resolves the discrepancy and sets the final result via a new `POST /api/voting-rounds/{id}/recount-resolve` endpoint.
**Rationale**: Notes are built-in to every OpenRegister object and support structured content. A dedicated ReCountRequest entity would add schema complexity without adding capability. The `"disputed"` result value requires no new field — `VotingRound.result` is already a free-text string in ADR-000.
**Alternative considered**: A separate ReCountRequest entity — rejected (over-engineered for the use case; the note stores all required data; the structured JSON body allows UI rendering without a migration).

### 4. Ballot distribution via `NotificationService` triggered from `openVotingRound()`
**Decision**: When `VotingService::openVotingRound()` completes successfully, it calls `NotificationService::sendToParticipants()` for every Participant with an active Membership in the GovernanceBody (non-null `startDate`, null or future `endDate`). Each notification includes the VotingRound title, the motion title, and a deep-link URL to the vote-casting screen. The live "Uitgenodigd: X / Gestemd: Y" counter in `VotingRoundPanel` polls `GET /api/voting-rounds/{id}/distribution` every 5 seconds; this endpoint counts notified Participants and cast Vote objects without exposing vote values.
**Rationale**: `NotificationService` is platform-provided (ADR-001); no custom notification controller is needed. The distribution endpoint is a simple count query — no PII leakage. The 5-second poll matches the existing tally poll in `VotingRoundPanel` from p2-motion-and-voting.
**Alternative considered**: WebSocket push for the distribution counter — rejected (no stable WebSocket API in Nextcloud at this scope; polling at 5-second intervals is acceptable for meeting-room usage; WebSocket deferred to future real-time spec).

### 5. Preferential ballot: `Vote.value` stores JSON-encoded ranked list; Borda count tally
**Decision**: For `VotingRound.votingMethod: "ranked-choice"`, `Vote.value` stores a JSON-encoded array of candidate identifiers ordered by the voter's preference (e.g. `'["candidate-a","candidate-c","candidate-b"]'`). `VotingService::tallyResults()` deserialises each vote, applies Borda count (n−1 points for first choice, n−2 for second, … 0 for last), and writes the sorted ranking to a structured note on the VotingRound. `VotingRound.result` stores the identifier of the winning candidate. The existing for/against/abstain tally path is unchanged.
**Rationale**: `Vote.value` is defined as a `string` in ADR-000 with no constraint on format. JSON encoding is a valid and reversible use of this field. Borda count is deterministic and implementable in pure PHP without external dependencies. A separate `RankedVoteChoice` entity would require a schema migration and one-to-many join per vote — the JSON encoding avoids both.
**Alternative considered**: Instant-runoff voting (IRV) — considered but rejected for T1; Borda count is simpler to implement and explain; IRV deferred to a later phase if demand emerges.

## Reuse Analysis (ADR-012)

| Capability | OpenRegister service / component used | Custom code |
|---|---|---|
| Secret ballot masking | `ObjectService.findAll()` (reads Vote objects), `CnStatusBadge` | `SecretBallotGuard` (controller middleware) |
| Motion config editor | `ObjectService.saveObject()` (saves GovernanceBody), `CnFormDialog`, `CnSettingsCard` | `WorkflowConfigService::serialize()`, `deserialize()` |
| Transition validation | `ObjectService.saveObject()` (existing in MotionService) | `MotionService::transitionLifecycle()` extended |
| Recount tally | `ObjectService.findAll()` (Vote count), notes built-in | `VotingService::recount()`, `resolveRecount()` |
| Auditor access | `AuthorizationService` (read-only role) | None (RBAC via OpenRegister built-in) |
| Ballot distribution notification | `NotificationService` (platform) | `VotingService::openVotingRound()` extended |
| Distribution counter endpoint | `ObjectService.findAll()` (count query) | `VotingController::getDistribution()` |
| Distribution progress UI | `CnStatsBlock` (or inline counter) | `VotingRoundPanel` extended |
| Ranked-choice vote entry | `CnFormDialog` (extended with drag-rank input) | `RankInput.vue` component |
| Ranked-choice tally | `ObjectService.findAll()` (Vote fetch) | `VotingService::tallyResults()` extended |
| Ranked results display | `CnDetailCard` with ranking table | `RankedResultsCard.vue` component |
| Audit trail | `ActivityService` (built-in) | None (automatic) |

No new capabilities were identified that should be moved to OpenRegister core.

## Seed Data (Dutch examples)

### VotingRound (secret ballot)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "geheime-stemming-bestuursverkiezing-2025" },
    "votingMethod": "show-of-hands",
    "isSecret": true,
    "openedAt": "2025-04-14T20:00:00+02:00",
    "closedAt": "2025-04-14T20:15:00+02:00",
    "quorumMet": true,
    "result": "adopted",
    "votesFor": 18,
    "votesAgainst": 4,
    "votesAbstain": 2
  },
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "geheime-stemming-amendement-begroting-2025" },
    "votingMethod": "for-against-abstain",
    "isSecret": true,
    "openedAt": "2025-04-14T20:20:00+02:00",
    "closedAt": null,
    "quorumMet": true,
    "result": null,
    "votesFor": 0,
    "votesAgainst": 0,
    "votesAbstain": 0
  },
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "open-stemming-motie-energie-2025" },
    "votingMethod": "for-against-abstain",
    "isSecret": false,
    "openedAt": "2025-04-14T19:45:00+02:00",
    "closedAt": "2025-04-14T19:58:00+02:00",
    "quorumMet": true,
    "result": "adopted",
    "votesFor": 25,
    "votesAgainst": 8,
    "votesAbstain": 3
  },
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "geheime-stemming-dijkgraaf-kandidaat-2025" },
    "votingMethod": "ranked-choice",
    "isSecret": true,
    "openedAt": "2025-05-20T14:00:00+02:00",
    "closedAt": "2025-05-20T14:30:00+02:00",
    "quorumMet": true,
    "result": "candidate-vandermeer",
    "votesFor": 0,
    "votesAgainst": 0,
    "votesAbstain": 0
  },
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "stemming-betwist-hertel-2025" },
    "votingMethod": "for-against-abstain",
    "isSecret": false,
    "openedAt": "2025-03-11T19:30:00+01:00",
    "closedAt": "2025-03-11T19:45:00+01:00",
    "quorumMet": true,
    "result": "disputed",
    "votesFor": 16,
    "votesAgainst": 16,
    "votesAbstain": 2
  }
]
```

### Vote (non-secret, open for audit)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-vandenberg-motie-energie-2025" },
    "value": "for",
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-04-14T19:47:00+02:00"
  },
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-pietersen-motie-energie-2025" },
    "value": "against",
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-04-14T19:49:00+02:00"
  },
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-bakker-motie-energie-proxy-2025" },
    "value": "for",
    "weight": 1,
    "isProxy": true,
    "castAt": "2025-04-14T19:51:00+02:00"
  },
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-yilmaz-ranked-dijkgraaf-2025" },
    "value": "[\"candidate-vandermeer\",\"candidate-hoekstra\",\"candidate-devries\"]",
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-05-20T14:08:00+02:00"
  },
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-ganpat-motie-parkeer-2025" },
    "value": "abstain",
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-04-14T21:02:00+02:00"
  }
]
```

### GovernanceBody (with workflowTemplate)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "GovernanceBody", "slug": "gemeenteraad-haarlemmermeer" },
    "name": "Gemeenteraad Haarlemmermeer",
    "bodyType": "municipal-council",
    "domain": "municipality",
    "workflowTemplate": "{\"permittedMotionTypes\":[\"motion\",\"amendment\",\"order\",\"procedural\"],\"transitions\":{\"submitted\":[\"debating\",\"withdrawn\"],\"debating\":[\"voting\",\"withdrawn\"],\"voting\":[\"adopted\",\"rejected\"]},\"majorityRule\":\"simple\"}",
    "quorumRule": "absolute-majority",
    "votingDefault": "for-against-abstain",
    "termStart": "2022-03-30",
    "termEnd": "2026-03-30"
  },
  {
    "@self": { "register": "decidesk", "schema": "GovernanceBody", "slug": "waterschap-hollands-noorderkwartier" },
    "name": "Algemeen Bestuur Waterschap Hollands Noorderkwartier",
    "bodyType": "water-board-assembly",
    "domain": "water-board",
    "workflowTemplate": "{\"permittedMotionTypes\":[\"motion\",\"amendment\"],\"transitions\":{\"submitted\":[\"debating\",\"withdrawn\"],\"debating\":[\"voting\",\"withdrawn\"],\"voting\":[\"adopted\",\"rejected\"]},\"majorityRule\":\"absolute\"}",
    "quorumRule": "two-thirds",
    "votingDefault": "for-against-abstain",
    "termStart": "2023-01-01",
    "termEnd": "2027-01-01"
  },
  {
    "@self": { "register": "decidesk", "schema": "GovernanceBody", "slug": "raad-van-commissarissen-bouwgroep-nl" },
    "name": "Raad van Commissarissen Bouwgroep Nederland B.V.",
    "bodyType": "supervisory-board",
    "domain": "corporate",
    "workflowTemplate": "{\"permittedMotionTypes\":[\"motion\",\"resolution\"],\"transitions\":{\"submitted\":[\"voting\",\"withdrawn\"],\"voting\":[\"adopted\",\"rejected\"]},\"majorityRule\":\"qualified-two-thirds\"}",
    "quorumRule": "simple-majority",
    "votingDefault": "for-against-abstain",
    "termStart": "2024-01-01",
    "termEnd": "2028-01-01"
  }
]
```
