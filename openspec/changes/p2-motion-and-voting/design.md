## Context

Decidesk uses the **thin-client** pattern: the app owns no database tables. All domain data is stored as JSON objects in OpenRegister. The frontend communicates with the OpenRegister API directly via Pinia object stores created with `createObjectStore`. The backend is minimal — a `SettingsController` for configuration and a repair step for register import.

The p1-crud-operations change established the app scaffold, register configuration, and CRUD views for the four foundational entities (GovernanceBody, Meeting, Participant, AgendaItem). This change (p2) extends the register with four entities that are the primary spec: Motion, VotingRound, Vote, and Amendment.

Governance bodies across all five domains (legislative, association, corporate board, operational, citizen participation) need to:
1. Submit and track formal motions through a lifecycle workflow
2. Manage amendments to motions
3. Open voting rounds with configurable method and quorum enforcement
4. Capture individual votes with proxy support
5. Publish results to ORI API and linked dossier folders

## Goals / Non-Goals

**Goals:**
- Full lifecycle management for Motion (submitted → debating → voting → adopted/rejected/withdrawn)
- Amendment submission, conflict detection, and voting
- VotingRound management: open/close, voting method selection, quorum check
- Vote capture per participant (for/against/abstain, proxy flag)
- Real-time tally display and result calculation
- Proxy vote delegation between participants
- Budget amendment motions with policy rationale field
- Digital co-signatory collection for motions
- Voting result publication to ORI API
- Automatic dossier folder via `_files` metadata on decision
- Calendar deadline sync via `_calendar` metadata
- Activity stream audit for all lifecycle events

**Non-Goals:**
- Custom workflow engine — use OpenRegister `WorkflowEngineController`
- Custom file upload/storage — use OpenRegister FileService + `_files` metadata
- Custom calendar integration — use OpenRegister `CalendarEventService` + `_calendar` metadata
- Custom audit logging — use OpenRegister AuditTrailService + `ActivityService`
- Citizen participation referendum flows (p4)
- ORI API publication connector (p3 — this spec only prepares the data and triggers the hook)
- Governance body domain configuration (p3)

## Decisions

### 1. Motion lifecycle as OpenRegister workflow
**Decision**: Motion lifecycle transitions (submitted → debating → voting → adopted/rejected/withdrawn) are implemented as OpenRegister `WorkflowEngineController` rules, triggered by status field changes on the Motion object. The lifecycle is visualised in the Motion detail view with `CnTimelineStages`.
**Rationale**: ADR-001 prohibits custom workflow systems. OpenRegister provides `WorkflowEngineController` + `WorkflowEngineRegistry` for free. Governance bodies can configure their own workflow templates via `GovernanceBody.workflowTemplate`.
**Alternative considered**: Custom PHP service with state machine — rejected because the platform provides this for free.

### 2. VotingRound quorum check before opening
**Decision**: When a user clicks "Open Vote" on a VotingRound, the frontend fetches the present-participant count from the linked Meeting/GovernanceBody and compares it against `GovernanceBody.quorumRule`. If quorum is not met, the open action is blocked with an inline warning.
**Rationale**: Quorum checking is a mandatory governance rule. It must happen before voting opens, not after. The check is performed client-side using data already available via OpenRegister relations.
**Alternative considered**: Backend quorum validation in a controller — unnecessary; the check is a simple count comparison and OpenRegister relation traversal.

### 3. Proxy voting via Vote.isProxy + Participant relation
**Decision**: Proxy voting is modelled as a Vote object with `isProxy: true` and the Participant relation pointing to the proxy holder (person casting on behalf of another). A separate proxy delegation record is stored as a Note on the VotingRound for auditability.
**Rationale**: ADR-000 Vote entity already includes `isProxy` and `weight` fields. No schema changes needed. The audit trail captures who delegated and who cast.
**Alternative considered**: Separate ProxyDelegation entity — rejected because it is not in ADR-000 and would require a schema migration.

### 4. Amendment conflict detection client-side
**Decision**: When a new Amendment is submitted, the frontend checks existing amendments on the same Motion for overlapping text passages (simple substring match on `Amendment.text`). A warning dialog is shown to the griffier when a potential conflict is detected.
**Rationale**: Story 9 requires the griffier to be alerted about conflicting amendments. A client-side check using already-loaded amendment objects is sufficient without a custom backend endpoint.
**Alternative considered**: Server-side NLP diff analysis — over-engineered for the initial spec; can be added in a later phase.

### 5. Voting result publication via notification hook
**Decision**: When a VotingRound closes and `result` is set, a `NotificationService` event is dispatched. An ORI publication webhook (configured in Settings) is triggered to push the result data. The `isPublished` flag on the linked Decision object is set to `true`.
**Rationale**: Publication is a side-effect of a VotingRound closing, not a primary operation. Using `WebhookService` CloudEvents format decouples the publication concern from the voting flow.
**Alternative considered**: Immediate synchronous publish call — rejected because ORI API may be unavailable; async webhook with retry is more resilient.

### 6. `CnTimelineStages` for motion lifecycle display
**Decision**: Motion detail view uses `CnTimelineStages` component to show the lifecycle progression. Each stage is colour-coded: grey (future), blue (current), green (done), red (rejected/withdrawn).
**Rationale**: ADR-004 recommends `CnTimelineStages` for workflow progression. No custom progress indicator.

### 7. Budget amendment motions use `motionType: motion` with policy rationale in `text`
**Decision**: Budget amendment motions are standard Motion objects with `motionType: motion`. The budget impact figures and policy rationale are embedded in the `text` field as structured prose. No separate schema field is added.
**Rationale**: ADR-000 Motion schema does not have a `budgetImpact` field. Adding one requires an ADR-000 amendment (breaking change). For p2, the `text` field is sufficient; a structured budget field can be proposed for a later ADR revision.

## Reuse Analysis

| Capability | OpenRegister service used | Custom code needed? |
|---|---|---|
| Motion CRUD | `ObjectService.saveObject()` / `deleteObject()` | No |
| Motion list/search | `ObjectService.findAll()` + `CnIndexPage` | No |
| VotingRound open/close | `ObjectService.saveObject()` (status field) | No — lifecycle via WorkflowEngineController |
| Quorum check | Client-side count vs `GovernanceBody.quorumRule` | Yes — simple JS check |
| Vote capture | `ObjectService.saveObject()` per Vote | No |
| Real-time tally | Computed from loaded Vote objects | Yes — derived count in component |
| Proxy delegation | `Vote.isProxy` + relation to Participant | No |
| Amendment submission | `ObjectService.saveObject()` | No |
| Amendment conflict detection | Client-side substring check | Yes — simple JS |
| File dossier | `FileService` + `_files` metadata | No |
| Calendar deadlines | `CalendarEventService` + `_calendar` metadata | No |
| Activity audit | `ActivityService` (automatic via AuditTrailService) | No |
| ORI publication | `WebhookService` CloudEvents | No — configure webhook in Settings |

## Seed Data (Dutch examples)

### Motion

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motie-duurzaamheid-amsterdam-2025" },
    "title": "Motie: Versnelling duurzame energietransitie",
    "text": "De gemeenteraad van Amsterdam, gehoord de beraadslaging,\n\nOverwegende dat de klimaatdoelstellingen van Parijs om versnelde actie vragen;\nVerzoekt het college een plan op te stellen voor 100% hernieuwbare energie in gemeentelijke gebouwen vóór 2030;\n\nEn gaat over tot de orde van de dag.",
    "motionType": "motion",
    "proposer": "Marie Janssen",
    "coSigners": ["Pieter de Groot", "Yasmine El-Amrani"],
    "lifecycle": "adopted",
    "submittedAt": "2025-01-15T19:45:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motie-begrotingswijziging-jeugdzorg-2025" },
    "title": "Motie: Begrotingswijziging jeugdzorg 2025",
    "text": "De gemeenteraad van Utrecht, gehoord de beraadslaging,\n\nConstaterende dat de wachtlijsten in de jeugdzorg in 2024 met 18% zijn toegenomen;\nVerzoekt het college een aanvullend budget van €1.200.000 beschikbaar te stellen voor extra jeugdzorgcapaciteit in de begroting 2025;\n\nEn gaat over tot de orde van de dag.",
    "motionType": "motion",
    "proposer": "Ans Rutten",
    "coSigners": ["Johan Vermeer"],
    "lifecycle": "voting",
    "submittedAt": "2025-04-14T10:30:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "ordemotion-vergaderorde-2025" },
    "title": "Ordemotion: Schorsing vergadering",
    "text": "De voorzitter wordt verzocht de vergadering voor 15 minuten te schorsen voor fractieberaad over agendapunt 5.",
    "motionType": "procedural",
    "proposer": "Femke Halsema",
    "coSigners": [],
    "lifecycle": "submitted",
    "submittedAt": "2025-02-05T11:20:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motie-verkeersveiligheid-fietspad-2025" },
    "title": "Motie: Aanleg vrijliggend fietspad Koninginneweg",
    "text": "De gemeenteraad, gehoord de beraadslaging,\n\nConstaterende dat de Koninginneweg als onveilig voor fietsers wordt beoordeeld in het gemeentelijke verkeersveiligheidsplan;\nVerzoekt het college de aanleg van een vrijliggend fietspad op de Koninginneweg op te nemen in het investeringsprogramma 2026;\n\nEn gaat over tot de orde van de dag.",
    "motionType": "motion",
    "proposer": "Pieter Bakker",
    "coSigners": ["Lena Visser", "Omar Khalid", "Ingrid Bosman"],
    "lifecycle": "debating",
    "submittedAt": "2025-03-12T14:00:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motie-transparantie-subsidies-2025" },
    "title": "Motie: Transparantieregister subsidies",
    "text": "De gemeenteraad van Amsterdam, gehoord de beraadslaging,\n\nOverwegende dat transparantie van subsidieverstrekking bijdraagt aan het vertrouwen in de overheid;\nVerzoekt het college een openbaar register van alle verstrekte subsidies boven €10.000 te publiceren op de gemeentelijke website;\n\nEn gaat over tot de orde van de dag.",
    "motionType": "motion",
    "proposer": "Jan de Vries",
    "coSigners": [],
    "lifecycle": "rejected",
    "submittedAt": "2025-01-15T20:10:00Z"
  }
]
```

### VotingRound

```json
[
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "stemronde-motie-duurzaamheid-2025" },
    "votingMethod": "for-against-abstain",
    "isSecret": false,
    "openedAt": "2025-01-15T21:00:00Z",
    "closedAt": "2025-01-15T21:08:00Z",
    "quorumMet": true,
    "result": "adopted",
    "votesFor": 28,
    "votesAgainst": 9,
    "votesAbstain": 3
  },
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "stemronde-begrotingswijziging-jeugdzorg-2025" },
    "votingMethod": "for-against-abstain",
    "isSecret": false,
    "openedAt": "2025-04-14T11:00:00Z",
    "closedAt": null,
    "quorumMet": true,
    "result": null,
    "votesFor": 12,
    "votesAgainst": 8,
    "votesAbstain": 2
  },
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "stemronde-statuten-vng-2025" },
    "votingMethod": "weighted",
    "isSecret": false,
    "openedAt": "2025-06-18T15:30:00Z",
    "closedAt": "2025-06-18T15:45:00Z",
    "quorumMet": true,
    "result": "adopted",
    "votesFor": 312,
    "votesAgainst": 48,
    "votesAbstain": 15
  },
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "stemronde-geheim-rvc-2025" },
    "votingMethod": "for-against-abstain",
    "isSecret": true,
    "openedAt": "2025-02-05T12:00:00Z",
    "closedAt": "2025-02-05T12:15:00Z",
    "quorumMet": true,
    "result": "rejected",
    "votesFor": 3,
    "votesAgainst": 5,
    "votesAbstain": 1
  }
]
```

### Vote

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-janssen-duurzaamheid-2025" },
    "value": "for",
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-01-15T21:02:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-devries-duurzaamheid-proxy-2025" },
    "value": "for",
    "weight": 1,
    "isProxy": true,
    "castAt": "2025-01-15T21:03:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-bakker-verkeer-2025" },
    "value": "against",
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-03-12T15:30:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-halsema-transparantie-2025" },
    "value": "abstain",
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-01-15T21:05:00Z"
  }
]
```

### Amendment

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Amendment", "slug": "amendement-duurzaamheid-deadline-2025" },
    "title": "Amendement: Aanpassing streefdatum energietransitie",
    "text": "In de laatste overwegende, vervang '2030' door '2028', zodat de tekst luidt: '100% hernieuwbare energie in gemeentelijke gebouwen vóór 2028'.",
    "proposer": "Pieter de Groot",
    "lifecycle": "adopted",
    "submittedAt": "2025-01-15T20:30:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Amendment", "slug": "amendement-jeugdzorg-bedrag-2025" },
    "title": "Amendement: Verhoging gevraagd budget jeugdzorg",
    "text": "In het verzoekende gedeelte, vervang '€1.200.000' door '€1.500.000', gelet op de actuele prognose van de jeugdzorginstelling.",
    "proposer": "Johan Vermeer",
    "lifecycle": "debating",
    "submittedAt": "2025-04-14T10:45:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Amendment", "slug": "amendement-fietspad-route-2025" },
    "title": "Amendement: Uitbreiding naar aangrenzende straten",
    "text": "Na het verzoek aan het college, voeg toe: 'en tevens de verkeersveiligheid op de Prinses Irenestraat en de Wilhelminastraat in de studie te betrekken.'",
    "proposer": "Lena Visser",
    "lifecycle": "submitted",
    "submittedAt": "2025-03-12T14:20:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "Amendment", "slug": "amendement-subsidies-drempel-2025" },
    "title": "Amendement: Verlaging drempelbedrag transparantieregister",
    "text": "In het verzoekende gedeelte, vervang '€10.000' door '€5.000'.",
    "proposer": "Yasmine El-Amrani",
    "lifecycle": "rejected",
    "submittedAt": "2025-01-15T20:20:00Z"
  }
]
```

## Risks / Trade-offs

- **[Risk] Concurrent vote submissions may cause tally race conditions** → Mitigation: VotingRound `votesFor`/`votesAgainst`/`votesAbstain` are recomputed client-side from loaded Vote objects, not maintained as mutable counters. The OpenRegister object lock prevents concurrent edits to the VotingRound object.
- **[Risk] Proxy vote abuse if delegation is not properly audited** → Mitigation: proxy delegation is recorded as a Note on the VotingRound via `ObjectService` with actor and timestamp. AuditTrailService logs all Vote creations automatically.
- **[Risk] ORI API webhook may fail if endpoint is unreachable** → Mitigation: `WebhookService` implements retry with exponential backoff. The `isPublished` flag remains `false` until the webhook succeeds, enabling manual retry from the Decision detail view.
- **[Risk] Amendment conflict detection may produce false positives for common words** → Mitigation: the client-side substring check targets the operative text clause (the `verzoekveld`) rather than the full amendment text. A warning is advisory — the griffier must confirm whether a conflict is real.
- **[Trade-off] Budget impact figures stored in `text` field** — structured budget impact data is not yet in ADR-000. Prose-in-text is an accepted short-term trade-off; a formal `budgetImpact` property can be proposed in a future ADR-000 revision.
- **[Trade-off] No real-time push for vote tally** — tally is refreshed on a polling interval (every 5 seconds during an open VotingRound) via `useListView` re-fetch. Full WebSocket push is deferred to a later phase.

## Migration Plan

1. The p1 register JSON already defines Motion, VotingRound, Vote, and Amendment schemas (they are in ADR-000 with `primary spec: p2-motion-and-voting`)
2. No new migration steps needed — schemas exist; this change adds frontend views and workflow configuration
3. Seed data objects are created via `ObjectService::saveObjects()` using deterministic slugs; upsert on slug conflict
4. WorkflowEngineController rules for Motion lifecycle are added to the register JSON under the `x-openregister.workflows` key
5. ORI publication webhook URL is configured via the Settings page under a new "Publication" section

## Open Questions

- Should the vote tally display be anonymous (totals only) or show per-participant votes for non-secret ballots? (Recommendation: configurable per GovernanceBody; default is totals-only to protect deliberative secrecy)
- Should email-reply voting (Story 11) be in scope for p2 or deferred to p3? (Recommendation: defer to p3 — requires inbound email processing integration beyond the scope of this change)
