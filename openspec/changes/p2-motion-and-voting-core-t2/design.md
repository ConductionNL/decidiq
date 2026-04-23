## Context

Decidesk is a Nextcloud app using the **thin-client** pattern: all domain data stored in OpenRegister; the backend provides business-rule services, settings, and PDF generation. The `Motion`, `Amendment`, `Vote`, and `VotingRound` entities are fully live from p2-motion-and-voting with complete lifecycle management, vote casting, proxy delegation, and ORI publication. This change extends those capabilities with seven second-tier features driven by post-launch market demand: real-time tally visibility for all participants, per-member vote behaviour analytics, atomic roll-call close-publish-anonymise, projection screen support, voting group presets, motion forwarding controls, and amendment diff visualisation.

Dutch governance practice shapes each of these: gemeenteraden demand that stemuitslag visible on the public gallery screens (projection), that roll-call votes (hoofdelijke stemmingen) can be published and anonymised in one step per privacy regulations, and that raadsleden can see their own voting history in the raadsinformatiysysteem. Corporate governance (AVA, RvC) adds voting group presets for quorum sub-groups and motion forwarding between committees.

## Goals / Non-Goals

**Goals:**
- Live vote tally refreshed every 3 seconds during an open VotingRound; role-differentiated view (chair sees per-member, members see aggregate)
- Per-Participant vote behaviour statistics aggregated across all closed VotingRounds for a GovernanceBody
- Atomic close + publish + anonymise action on VotingRound close dialog
- Fullscreen projection route (`/projection/:votingRoundId`) displayable without Nextcloud login
- Default voting group presets configurable in admin settings and selectable when opening a VotingRound
- Role-based motion forwarding controls enforced in backend `MotionService`
- Myers diff visualisation on `AmendmentDetail.vue` "Vergelijken" tab

**Non-Goals:**
- Ranked-choice preference polling UI (deferred to participation spec)
- AI-assisted motion drafting or vote prediction (future AI spec)
- Video/webcast indexing of vote events (future media spec)
- Full SRD II shareholder disclosure automation (future compliance spec)
- WebSocket or server-sent-events real-time push (polling is sufficient at p2 scope; push deferred)
- Cross-body consolidated vote behaviour dashboards (deferred to p3 governance bodies)

## Decisions

### 1. Live tally via polling (not WebSockets)
**Decision**: `VotingRoundPanel.vue` polls `ObjectService.findAll()` for Vote objects every 3 seconds using `setInterval` cleared on component unmount, rather than WebSocket or SSE.
**Rationale**: OpenRegister does not yet expose a WebSocket event stream for object changes. A 3-second poll adds acceptable latency for governance contexts (vote rounds last minutes, not milliseconds). The implementation uses the existing `objectStore.fetchObjects()` action — no new backend code.
**Alternative considered**: Nextcloud Notify Push — rejected; requires the Notify Push app to be installed and does not cover all deployment targets; adds an operational dependency for a non-critical latency improvement.

### 2. Member voting behaviour aggregated by a dedicated service (not a stored analytics entity)
**Decision**: `VotingBehaviourService` computes statistics on-demand from existing `Vote` objects via `ObjectService.findAll()` filtered by `participantId` and `votingRoundId`. Results are returned directly; no separate analytics entity is stored.
**Rationale**: ADR-000 introduces no analytics entity; creating one would require a schema migration and violate the no-new-entities rule for this spec. On-demand aggregation over closed Vote objects is fast enough (<200ms) for the expected cardinality (≤100 rounds per body per year, ≤50 members). If performance becomes an issue, OpenRegister's built-in facet/aggregation pipeline can be used.
**Alternative considered**: A separate `VotingBehaviour` OpenRegister entity — rejected (not in ADR-000; premature optimisation at current scale).

### 3. Roll-call anonymisation nulls `Vote.value` post-close
**Decision**: When the chair checks "Anonimiseren" in the close dialog, `VotingService::closeVotingRound()` calls `ObjectService.saveObject()` for each Vote in the round, setting `value: null`. The tally counts (votesFor/Against/Abstain) and result are stored on `VotingRound` before nulling. `VotingRound.isSecret` is NOT set — anonymisation is a post-close GDPR step, distinct from a secret ballot.
**Rationale**: ADR-000 defines `Vote.value` as a non-required string, so null is valid. The tally is captured at close time before nulling — audit integrity is preserved. The ORI publication payload (sent before nulling) contains the aggregate result only, not individual values, per ORI 1.0 spec.
**Alternative considered**: Soft-delete individual Vote objects — rejected; breaks audit trail continuity and the OpenRegister built-in audit uses object history, not deletion markers.

### 4. Projection view is a public `#[PublicPage]` route
**Decision**: `ProjectionController.php` serves `ProjectionView.vue` at `/apps/decidesk/projection/{votingRoundId}` with `#[PublicPage]` and `#[NoCSRFRequired]`. The view fetches VotingRound state via a public `GET /api/voting-rounds/{id}/public-state` endpoint that returns only aggregate counts and the leading-option preselection flag — no individual vote values.
**Rationale**: In-room projector screens are displayed on devices that are not logged in to Nextcloud. The public endpoint exposes only what the gallery audience would see on a physical vote board. ADR-005 requires admin check on backend mutation endpoints; this is read-only. ADR-002 does not prohibit public read endpoints.
**Alternative considered**: Embed the projection in an authenticated iframe — rejected; requires the projector device to maintain a Nextcloud session, which is operationally fragile in meeting rooms.

### 5. Voting group presets stored in `IAppConfig` as JSON
**Decision**: Named voting group presets (name + array of Participant UUIDs) are stored under `IAppConfig` key `voting_group_presets` as a JSON-encoded array of objects. The admin settings page reads/writes this key via `GET/POST /api/settings`. When a chair opens a VotingRound and selects a preset, the frontend passes the preset's Participant UUIDs to `VotingService::openVotingRound()` which stores them as an OpenRegister relation list.
**Rationale**: Presets are governance-body-level configuration, not domain data — `IAppConfig` is the correct store per ADR-003-backend. Participant membership changes (someone leaving the body) are handled by showing a warning if a stored UUID is no longer an active Membership.
**Alternative considered**: OpenRegister entity for presets — rejected (configuration, not governance data; ADR-001-data-layer reserves OpenRegister for domain objects).

### 6. Motion forwarding enforced in `MotionService` with config-driven role check
**Decision**: Two `IAppConfig` keys control forwarding: `motion_forwarding_roles` (JSON array of role strings, default `["chair", "secretary"]`) and `motion_forwarding_requires_approval` (boolean, default `false`). `MotionService::forwardMotion()` checks the actor's Membership role against the allowed list. If approval is required, the forwarded Motion is created with `lifecycle: "submitted"` in the target body and a Nextcloud notification is sent to the target body's chair.
**Rationale**: Motion forwarding is an organisational policy that varies between governance bodies (some allow any member to forward; others restrict to chair). Storing the policy in `IAppConfig` allows admin customisation without schema changes.
**Alternative considered**: Hardcode forwarding to chair/secretary only — rejected; corporate boards and associations often allow member-initiated forwarding; config provides flexibility without complexity.

### 7. Amendment diff computed in frontend using Myers algorithm
**Decision**: `AmendmentDetail.vue`'s "Vergelijken" tab imports a pure JS `diff` utility (e.g., `diff` npm package, already listed as a transitive dependency via `@conduction/nextcloud-vue`). The diff is computed client-side by comparing `parentMotion.text` versus the inline-annotated `amendment.text`. Output is rendered as a `<pre>` block with `<ins>` (green) and `<del>` (red) spans styled with NL Design System tokens.
**Rationale**: No new PHP endpoint is needed; both texts are already fetched for the detail view. Client-side diff is instantaneous and works offline. The `diff` library handles sorted-list reordering correctly via LCS algorithm. WCAG AA: colour is supplemented by `+`/`-` prefix characters so colour is not the sole diff indicator.
**Alternative considered**: Server-side diff via a new PHP endpoint — rejected (unnecessary round-trip; no business logic on the backend for this presentation concern).

## Reuse Analysis (ADR-012)

| Capability | OpenRegister service / component used | Custom code |
|---|---|---|
| Live tally polling | `objectStore.fetchObjects()` (existing store action) | Poll interval in `VotingRoundPanel.vue` |
| Vote behaviour aggregation | `ObjectService.findAll()` with participantId filter | `VotingBehaviourService::getStats()` |
| Vote behaviour display | `CnDetailPage`, `CnDetailCard`, `CnChartWidget` | `MemberVotingHistoryView.vue` |
| Anonymisation | `ObjectService.saveObject()` per Vote | Loop in `VotingService::closeVotingRound()` |
| Projection public state | `ObjectService.findObject()` | `ProjectionController` + `VotingService::getPublicState()` |
| Voting group presets (store) | `IAppConfig` | Admin settings UI section |
| Voting group presets (apply) | `ObjectService.saveObject()` on VotingRound | `VotingService::openVotingRound()` extended |
| Motion forwarding | `ObjectService.saveObject()`, `NotificationService` | `MotionService::forwardMotion()` |
| Amendment diff | `diff` npm library (transitive dep) | `AmendmentDetail.vue` "Vergelijken" tab |
| Member history route | `CnIndexPage` patterns | `MemberVotingHistoryView.vue` |
| All export | `ExportService` + `CnMassExportDialog` | None |
| All search | `IndexService` + `CnFilterBar` | None |
| All audit | `ActivityService` (built-in) | None |

No new capabilities identified for OpenRegister core.

## Seed Data (Dutch examples)

All seed objects use the `@self` envelope and MUST be idempotent on re-import (matched by slug).
These objects extend the seed data already introduced in p2-motion-and-voting. They share the same register (`decidesk`) and schema names from ADR-000.

### Motion (forwarding scenario)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motie-doorgestuurd-commissie-wonen-2025" },
    "title": "Motie Doorgestuurd: Woonvisie 2040",
    "text": "De raad verzoekt het college de woonvisie 2040 in afstemming met de commissie Wonen en Ruimte te actualiseren en de raad uiterlijk Q3 2025 een concept voor te leggen.",
    "motionType": "motion",
    "proposer": "A. Pietersen",
    "coSigners": ["N. Yilmaz", "S. de Jong"],
    "lifecycle": "submitted",
    "submittedAt": "2025-04-15T14:00:00+02:00",
    "status": "submitted"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motie-milieu-handhaving-2025" },
    "title": "Motie Milieu en Handhaving — Urgentieaanpak",
    "text": "De raad verzoekt het college de handhavingscapaciteit op milieu-overtredingen met 20% uit te breiden en hierover kwartaalrapportages aan de raad te sturen.",
    "motionType": "motion",
    "proposer": "F. el-Amrani",
    "coSigners": ["J. van der Berg"],
    "lifecycle": "adopted",
    "submittedAt": "2025-04-15T15:30:00+02:00",
    "status": "adopted"
  }
]
```

### VotingRound (roll-call with anonymisation)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "stemronde-hoofdelijk-woonvisie-2025-04-15" },
    "votingMethod": "for-against-abstain",
    "isSecret": false,
    "openedAt": "2025-04-15T14:20:00+02:00",
    "closedAt": "2025-04-15T14:28:00+02:00",
    "quorumMet": true,
    "result": "adopted",
    "votesFor": 29,
    "votesAgainst": 3,
    "votesAbstain": 0
  },
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "stemronde-milieu-handhaving-2025-04-15" },
    "votingMethod": "for-against-abstain",
    "isSecret": false,
    "openedAt": "2025-04-15T15:45:00+02:00",
    "closedAt": "2025-04-15T15:52:00+02:00",
    "quorumMet": true,
    "result": "adopted",
    "votesFor": 26,
    "votesAgainst": 5,
    "votesAbstain": 1
  },
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "stemronde-lopend-commissie-infra-2025-04-15" },
    "votingMethod": "show-of-hands",
    "isSecret": false,
    "openedAt": "2025-04-15T16:10:00+02:00",
    "closedAt": null,
    "quorumMet": true,
    "result": null,
    "votesFor": 0,
    "votesAgainst": 0,
    "votesAbstain": 0
  }
]
```

### Vote (anonymised roll-call — value nulled post-close)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-pietersen-woonvisie-hoofdelijk" },
    "value": null,
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-04-15T14:22:10+02:00"
  },
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-elamrani-woonvisie-hoofdelijk" },
    "value": null,
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-04-15T14:23:05+02:00"
  },
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-vdberg-milieu-handhaving" },
    "value": "for",
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-04-15T15:46:30+02:00"
  },
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-bakker-milieu-handhaving" },
    "value": "against",
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-04-15T15:47:00+02:00"
  }
]
```

### Amendment (diff visualisation scenario)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Amendment", "slug": "amendement-woonvisie-horizon-aanpassing" },
    "title": "Amendement: Tijdshorizon woonvisie naar 2035",
    "text": "In de motie Woonvisie 2040 wordt '2040' vervangen door '2035' en wordt 'Q3 2025' vervangen door 'Q1 2026', zodat de planning realistischer aansluit bij de bouwcapaciteit.",
    "proposer": "H. Bakker",
    "lifecycle": "debating",
    "submittedAt": "2025-04-15T14:05:00+02:00",
    "status": "debating"
  },
  {
    "@self": { "register": "decidesk", "schema": "Amendment", "slug": "amendement-milieu-rapportagefrequentie" },
    "title": "Amendement: Kwartaalrapportage wijzigen naar halfjaarlijks",
    "text": "In de motie Milieu en Handhaving wordt 'kwartaalrapportages' vervangen door 'halfjaarlijkse rapportages', gezien de administratieve belasting van kwartaalrapportages.",
    "proposer": "S. de Jong",
    "lifecycle": "rejected",
    "submittedAt": "2025-04-15T15:32:00+02:00",
    "status": "rejected"
  }
]
```

## Risks / Trade-offs

- **[Risk] Polling overhead during active voting round** → Each open `VotingRoundPanel` polls every 3 seconds. In a large meeting with 50 members each with the panel open, that is 50 concurrent GET requests every 3 seconds. Mitigation: the public-state endpoint is read-only and cacheable (no mutations); OpenRegister's HTTP cache headers are respected by `@nextcloud/axios`. At 50 members the load is well within Nextcloud's capacity; flag for review if cardinality exceeds 200.
- **[Risk] Anonymisation window race condition** → A Vote might be read (for ORI publication) simultaneously with the anonymisation loop. Mitigation: ORI publication is triggered first inside `closeVotingRound()`; anonymisation loop runs after the HTTP response from ORI is confirmed (or timed out with the retry flag set). Sequence: tally → store result → publish → anonymise.
- **[Risk] Preset Participant UUIDs become stale** → A Participant who leaves the governance body may still be listed in a saved voting group preset. Mitigation: `VotingService::openVotingRound()` validates each preset UUID against active Memberships and excludes inactive ones, showing a warning banner in the UI listing skipped members.
- **[Risk] Projection route exposed without auth** → The `/projection/:id` endpoint returns only aggregate counts, not individual vote values. Still, it is reachable without authentication. Mitigation: The public-state endpoint enforces that the VotingRound belongs to a GovernanceBody the Nextcloud instance hosts; it does not enumerate across tenants. Logged as a risk for security review before go-live.
- **[Trade-off] Client-side diff for large motion texts** → Myers diff over very large text blobs (multi-page statuten) may block the browser main thread for 200–500ms. Mitigation: Wrap diff computation in a `setTimeout(0)` to yield to the event loop; show a loading spinner while computing. Full Web Worker offloading is deferred — acceptable for p2 scope.

## Migration Plan

1. No schema migrations — all entities (Motion, Amendment, Vote, VotingRound) are unchanged from ADR-000
2. Extend `VotingService.php`: add `anonymise` parameter to `closeVotingRound()`; add `getPublicState()` method; extend `openVotingRound()` with preset UUID list
3. Add `VotingBehaviourService.php` with `getStats(participantId, governanceBodyId)` method
4. Add `VotingBehaviourController.php` (`GET /api/voting-behaviour/{participantId}`) and `ProjectionController.php` (public page)
5. Extend `MotionService.php`: add `forwardMotion()` method with config-driven role check
6. Register two new routes in `appinfo/routes.php`; register `ProjectionController` as public page
7. Extend admin settings: add "Stemgroepen" presets section and "Doorzending" section
8. Frontend: extend `VotingRoundPanel.vue` (polling + anonymise action + preset selector); add `MemberVotingHistoryView.vue`; add `ProjectionView.vue`; add "Vergelijken" tab to `AmendmentDetail.vue`
9. Add translation keys for all new user-visible strings
10. Seed data extended with 2 Motion, 3 VotingRound, 4 Vote, 2 Amendment objects — idempotent on re-import

## Open Questions

- Should the projection preselection highlight update immediately on each vote cast, or only after a 3-second poll cycle? (Recommendation: update on poll cycle to avoid flickering on high-volume voting)
- Should voting group presets be per-GovernanceBody or global? (Recommendation: per-GovernanceBody stored as a namespaced `IAppConfig` key `voting_group_presets_{bodyId}`)
- Should anonymised Vote objects be destroyed after a configurable retention period? (Recommendation: leave to the data retention spec; document the null-value convention clearly in the audit trail)
- Should motion forwarding create a copy of the Motion or a reference? (Recommendation: a new Motion object linked via OpenRegister relation to the source Motion; the source is marked `forwarded` in its lifecycle)

## Status

status: pr-created
updated: 2026-04-21
Progress: Backend core services (VotingBehaviourService, ProjectionController, VotingService extensions, MotionService.forwardMotion) complete and tested. Frontend views (projection, voting history, amendments diff) and translations deferred to subsequent PR phases.
