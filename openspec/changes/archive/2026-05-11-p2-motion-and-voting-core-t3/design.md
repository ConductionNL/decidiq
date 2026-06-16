## Context

Decidesk is a thin-client Nextcloud app: all domain data is stored in OpenRegister. The `Motion`, `Amendment`, `Vote`, and `VotingRound` entities were delivered in ADR-000 and have full CRUD, lifecycle, vote casting, proxy voting, and ORI publication capabilities from p2-motion-and-voting.

This T3 change adds the next highest-demand extensions identified by Specter market research: motion execution tracking (closing the adoption-to-execution loop), vote anonymisation (GDPR/AVG compliance), an interactive quorum calculator (secretary pre-meeting tooling), and written/circular resolution approval (Dutch corporate governance law requirement under BW 2:238 and the Dutch Corporate Governance Code).

The primary personas are: the **Board Secretary / Company Secretary** (needs execution tracking and written resolution workflows), the **Griffier / Council Clerk** (needs vote anonymisation after sessions and execution status updates), the **Supervisory Board Chair** and **CEO / Managing Director** (need written circular resolutions for time-sensitive corporate decisions), and the **Legal Counsel / Compliance Officer** (needs vote anonymisation audit trails and written resolution documentation).

## Goals / Non-Goals

**Goals:**
- Motion execution lifecycle: `execution-pending`, `executing`, `executed` states on adopted Motions
- Execution ActionItem auto-creation with configurable deadline
- "Uitvoering" panel on Motion detail page surfacing execution status and ActionItems
- Vote anonymisation: nullify voter identity on Vote objects in closed VotingRounds
- VotingRound tagged `votes-anonymized`; aggregate counts preserved
- Audit trail entry for anonymisation operation
- Quorum calculator: `QuorumCalculatorService` + `GET /api/governance-bodies/{id}/quorum-preview`
- Interactive `QuorumCalculatorPanel.vue` on GovernanceBody detail and VotingRound creation
- Written/circular resolution: `written-resolution` votingMethod value
- Bulk notification to all active GovernanceBody members when circular round opens
- Standard vote casting, tallying, and ORI publication for written resolutions

**Non-Goals:**
- Full shareholder SRD II disclosure automation (future compliance spec)
- Digital signing of written resolutions with PKI / Nextcloud Sign (future sprint)
- AI-assisted anonymisation of meeting speech transcripts (future AI spec)
- Ranked-choice preference polling beyond basic data capture (future participation spec)
- PLOOI publication of anonymised voting records (p3-ori-publication)
- Execution budget impact tracking linked to financial systems (future integration spec)

## Decisions

### 1. Execution status via extended Motion lifecycle values
**Decision**: Motion execution states (`execution-pending`, `executing`, `executed`) are implemented as additional lifecycle values on the existing `lifecycle` field of the Motion entity, following the same pattern as the base states (`submitted`, `debating`, `voting`, `adopted`, `rejected`, `withdrawn`). Transitions are managed by `MotionService::transitionLifecycle()` extended with the new allowed transitions: `adopted → execution-pending`, `execution-pending → executing`, `executing → executed`. An execution note is stored as an OpenRegister built-in note on the Motion object with `title: "Uitvoering"`.
**Rationale**: ADR-000 defines `lifecycle: string` on Motion. Adding new valid values to an enum is non-breaking per ADR-011 schema versioning rules. Using the same `transitionLifecycle()` method avoids a separate execution service for state management. Built-in notes cover the execution narrative without a schema change.
**Alternative considered**: A new `executionStatus: string` field on Motion — rejected (ADR-000 is the source of truth; adding a field requires an ADR-000 update and a migration; lifecycle extension covers the same semantic need without schema drift).

### 2. Execution ActionItems created automatically on transition to `execution-pending`
**Decision**: When `MotionService::transitionLifecycle()` moves a Motion to `execution-pending`, it automatically creates an ActionItem (using OpenRegister `saveObject()`) with `title: "Uitvoering motie: {motionTitle}"`, `taskStatus: open`, and `dueDate` set to `now() + executionDeadlineDays` (configurable via `IAppConfig` key `motion_execution_deadline_days`, default 90). The ActionItem is linked to the Motion via an OpenRegister relation. Completion of the ActionItem does NOT automatically transition Motion to `executed` — the clerk does that explicitly via the UI.
**Rationale**: ActionItem already has `dueDate`, `taskStatus`, and overdue detection from p2-minutes-and-decisions. Reusing it avoids a new entity. Decoupling ActionItem completion from lifecycle transition preserves the clerk's discretion — a motion can be executed without every sub-task being complete.
**Alternative considered**: A dedicated `ExecutionRecord` entity — rejected (ADR-000 is authoritative; ActionItem covers the use case with existing fields; no new entities without an ADR-000 update).

### 3. Vote anonymisation deletes person relations from Vote objects
**Decision**: `VotingAnonymizationService::anonymize(string $votingRoundId, string $actorId)` fetches all Vote objects for the VotingRound via `ObjectService.findAll()` and updates each Vote by removing its `person` relation (setting the relation reference to null via `ObjectService.saveObject()`). The voter's display name is not stored on the Vote object — only the relation to Person — so nullifying the relation is sufficient to remove personal data. After all votes are anonymised, the VotingRound is updated to add tag `votes-anonymized` and an audit entry is logged via `ActivityService`. The operation is irreversible.
**Rationale**: Vote objects reference the voter via an OpenRegister relation (Vote → Person). Nullifying the relation satisfies GDPR Art. 17 (right to erasure) without deleting the Vote record or altering the aggregate counts on VotingRound. The `votes-anonymized` tag on VotingRound is visible via `CnStatusBadge` and filterable via `CnFilterBar`. Irreversibility is a deliberate safeguard — confirmed via a `CnDeleteDialog`-style confirmation in the frontend.
**Alternative considered**: Soft-delete voter identity via a new `isAnonymized: boolean` field on Vote — rejected (requires schema change; nullifying the relation achieves the same privacy outcome using built-in mechanisms).

### 4. Quorum calculator as a dedicated service and API endpoint
**Decision**: A new `QuorumCalculatorService` computes the required quorum for a GovernanceBody from its `quorumRule` field and an `expectedAttendance` parameter. It returns: `requiredVotes` (the minimum number of votes to constitute quorum), `requiredMajority` (threshold for the configured voting method), `isQuorumMet` (boolean given the expected attendance), and `memberCount` (total active members). The result is exposed via `GET /api/governance-bodies/{id}/quorum-preview?expectedAttendance=N`. The `quorumRule` field is a simple expression like `"majority"`, `"two-thirds"`, or `"absolute:N"` — parsing is handled in `QuorumCalculatorService::parseRule()`.
**Rationale**: ADR-002 requires apps to expose API endpoints following URL pattern rules. The calculator is stateless and read-only — no persistence needed. The same service is called by `VotingService::checkQuorum()` (from p2-motion-and-voting), so T3 is extracting and formalising existing quorum logic into a dedicated service rather than duplicating it.
**Alternative considered**: Frontend-only quorum calculation — rejected (ADR-005: business-rule calculations must be on the backend; frontend-only = security vulnerability if quorum enforcement relies on it; calculator must be consistent with `VotingService::checkQuorum()`).

### 5. Written/circular resolution uses existing VotingRound with extended `votingMethod`
**Decision**: Circular/written resolutions add `written-resolution` as a new valid value for `VotingRound.votingMethod`. When `VotingService::openVotingRound()` receives `votingMethod: written-resolution`, it additionally: fetches all active Members of the GovernanceBody via `ObjectService.findAll()`, sends each a Nextcloud notification via `NotificationService` containing the Motion title, text, and a direct vote-cast URL, and creates an ActionItem with `title: "Termijn schriftelijke stemming: {motionTitle}"` and `dueDate: closedAt`. Vote casting, tallying, and result publication are identical to regular rounds.
**Rationale**: `votingMethod` is already an enum on VotingRound. Adding a new value is non-breaking. Reusing the standard vote-cast endpoint avoids a parallel code path. The only extension is the bulk notification dispatch, which uses existing `NotificationService`. BW 2:238 requires all members to be notified — the `NotificationService` call satisfies this requirement.
**Alternative considered**: A separate `CircularResolution` entity — rejected (ADR-000 is authoritative; VotingRound already has all required fields; no new entity needed; the distinction is a voting method, not a different entity type).

## Reuse Analysis (ADR-012)

| Capability | OpenRegister service / component used | Custom code |
|---|---|---|
| Motion execution lifecycle transitions | `ObjectService.saveObject()` | `MotionService::transitionLifecycle()` extended |
| Execution note | Built-in OpenRegister notes + `ObjectService.saveObject()` | Called from `MotionService::updateExecutionNote()` |
| Execution ActionItem creation | `ObjectService.saveObject()`, `OverdueActionItemsJob` (existing) | Called from `MotionService::transitionLifecycle()` |
| Execution panel display | `ObjectService.findAll()` (relations query) | `MotionExecutionPanel.vue` |
| Vote anonymisation | `ObjectService.findAll()` + `ObjectService.saveObject()` | `VotingAnonymizationService::anonymize()` |
| Anonymisation audit trail | `ActivityService` (built-in) | Called from `VotingAnonymizationService` |
| Anonymisation tag on VotingRound | Built-in `tags` array | Called from `VotingAnonymizationService` |
| Quorum calculation | `ObjectService.findAll()` (member count) | `QuorumCalculatorService::calculate()` |
| Quorum preview API endpoint | Controller + `QuorumCalculatorService` | `QuorumController::preview()` |
| Quorum calculator panel | `CnDetailCard` + `CnStatsBlock` | `QuorumCalculatorPanel.vue` |
| Written resolution notifications | `NotificationService` | Called from `VotingService::openVotingRound()` |
| Written resolution ActionItem | `ObjectService.saveObject()` | Called from `VotingService::openVotingRound()` |
| Vote casting, tallying, ORI publish | `ObjectService.saveObject()`, `OriPublicationService` (existing) | None — reuses p2-motion-and-voting |
| Search / filter | `IndexService` + `CnFilterBar` | None |
| Export | `ExportService` + `CnMassExportDialog` | None |

No new capabilities should be moved to OpenRegister core. `VotingAnonymizationService` and `QuorumCalculatorService` are the only net-new PHP classes; `MotionService` and `VotingService` are extended with new methods.

## Seed Data

### Motion (5 objects — demonstrating execution lifecycle states)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motion-woningbouwplan-oost-adopted" },
    "title": "Motie Woningbouwplan Oost 2025–2030",
    "text": "De raad van de gemeente Westerhaven, gehoord de beraadslagingen, verzoekt het college van burgemeester en wethouders het Woningbouwplan Oost 2025–2030 vast te stellen en voor 1 oktober 2025 te starten met de uitvoering van fase 1.",
    "motionType": "motion",
    "proposer": "Drs. M.A. van den Berg",
    "coSigners": ["Ing. J.W. de Vries", "Prof. dr. A.C. Smits"],
    "lifecycle": "execution-pending",
    "submittedAt": "2025-03-20T14:00:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motion-duurzaamheid-executing" },
    "title": "Motie Duurzaamheidsplan Gemeentelijke Gebouwen 2026",
    "text": "De raad verzoekt het college om voor 1 februari 2026 een uitvoeringsplan op te stellen voor de verduurzaming van alle gemeentelijke gebouwen, met aandacht voor zonnepanelen, isolatie en warmtepompen, en dit plan ter goedkeuring voor te leggen aan de raad.",
    "motionType": "motion",
    "proposer": "Ir. S.L. Bakker",
    "coSigners": ["Drs. E.M. Hofman"],
    "lifecycle": "executing",
    "submittedAt": "2025-01-15T10:30:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motion-parkeerbeleid-executed" },
    "title": "Motie Herziening Parkeerbeleid Binnenstad",
    "text": "De raad verzoekt het college om het parkeerbeleid in de binnenstad te herzien en daarbij de belangen van bewoners, bezoekers en ondernemers evenwichtig af te wegen. Het college rapporteert uiterlijk 1 juni 2025 over de bevindingen.",
    "motionType": "motion",
    "proposer": "Mw. drs. C.J. Vermeulen",
    "coSigners": [],
    "lifecycle": "executed",
    "submittedAt": "2024-11-05T09:00:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motion-written-resolution-ava" },
    "title": "Schriftelijk Besluit: Goedkeuring Jaarrekening 2024",
    "text": "De aandeelhouders van Westerhaven Holding B.V., handelend op grond van artikel 2:238 BW, besluiten buiten vergadering tot goedkeuring van de jaarrekening 2024 zoals vastgesteld door het bestuur op 28 februari 2025, met verlening van décharge aan de directie.",
    "motionType": "written-resolution",
    "proposer": "Mr. R.F. van Houten (Directeur)",
    "coSigners": [],
    "lifecycle": "voting",
    "submittedAt": "2025-04-01T08:00:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motion-amendement-jeugdzorg" },
    "title": "Amendement op Begroting: Extra Middelen Jeugdzorg",
    "text": "De raad besluit de begroting 2026 te wijzigen door € 500.000 extra beschikbaar te stellen voor jeugdzorg, gedekt door een verlaging van de post 'Communicatie en PR' met eenzelfde bedrag.",
    "motionType": "motion",
    "proposer": "Drs. P.T. Janssen",
    "coSigners": ["Mw. ir. F.A. de Groot", "Dhr. H.B. Kuipers"],
    "lifecycle": "adopted",
    "submittedAt": "2025-02-18T13:00:00Z"
  }
]
```

### VotingRound (4 objects — demonstrating anonymisation and written resolution)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "votinground-woningbouw-nominaal" },
    "votingMethod": "for-against-abstain",
    "isSecret": false,
    "openedAt": "2025-03-20T15:10:00Z",
    "closedAt": "2025-03-20T15:25:00Z",
    "quorumMet": true,
    "result": "adopted",
    "votesFor": 28,
    "votesAgainst": 5,
    "votesAbstain": 2
  },
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "votinground-duurzaamheid-geanonimiseerd" },
    "votingMethod": "for-against-abstain",
    "isSecret": false,
    "openedAt": "2025-01-15T16:00:00Z",
    "closedAt": "2025-01-15T16:15:00Z",
    "quorumMet": true,
    "result": "adopted",
    "votesFor": 22,
    "votesAgainst": 8,
    "votesAbstain": 1
  },
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "votinground-written-resolution-ava" },
    "votingMethod": "written-resolution",
    "isSecret": false,
    "openedAt": "2025-04-01T08:00:00Z",
    "closedAt": "2025-04-14T23:59:00Z",
    "quorumMet": true,
    "result": "adopted",
    "votesFor": 3,
    "votesAgainst": 0,
    "votesAbstain": 0
  },
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "votinground-parkeerbeleid-open" },
    "votingMethod": "show-of-hands",
    "isSecret": false,
    "openedAt": "2024-11-05T10:30:00Z",
    "closedAt": "2024-11-05T10:45:00Z",
    "quorumMet": true,
    "result": "adopted",
    "votesFor": 19,
    "votesAgainst": 12,
    "votesAbstain": 3
  }
]
```

### Vote (4 objects — individual votes cast in the above rounds)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "vote-woningbouw-vdb-voor" },
    "value": "for",
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-03-20T15:12:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "vote-woningbouw-smits-tegen" },
    "value": "against",
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-03-20T15:13:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "vote-ava-vanhouten-voor" },
    "value": "for",
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-04-03T09:15:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "vote-duurzaamheid-proxy-voor" },
    "value": "for",
    "weight": 1,
    "isProxy": true,
    "castAt": "2025-01-15T16:05:00Z"
  }
]
```

### ActionItem (3 objects — execution tracking items)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actionitem-uitvoering-woningbouw" },
    "title": "Uitvoering motie: Woningbouwplan Oost 2025–2030",
    "description": "Zorg dat het college het Woningbouwplan Oost 2025–2030 vaststelt en voor 1 oktober 2025 start met uitvoering van fase 1. Rapporteer voortgang aan de raad.",
    "assignee": "Drs. M.J. Koopmans (Wethouder Wonen)",
    "dueDate": "2025-07-01",
    "taskStatus": "open"
  },
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actionitem-uitvoering-duurzaamheid" },
    "title": "Uitvoering motie: Duurzaamheidsplan Gemeentelijke Gebouwen 2026",
    "description": "Stel een uitvoeringsplan op voor verduurzaming van alle gemeentelijke gebouwen. Leg dit plan uiterlijk 1 februari 2026 ter goedkeuring voor aan de raad.",
    "assignee": "Ir. T.B. Lindeman (Wethouder Duurzaamheid)",
    "dueDate": "2026-02-01",
    "taskStatus": "open"
  },
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actionitem-termijn-written-resolution" },
    "title": "Termijn schriftelijke stemming: Goedkeuring Jaarrekening 2024",
    "description": "Alle aandeelhouders dienen hun stem uit te brengen voor het sluiten van de stemmingstermijn. Terugkoppeling via notificatie na sluiting.",
    "assignee": "Mr. R.F. van Houten",
    "dueDate": "2025-04-14",
    "taskStatus": "open"
  }
]
```
