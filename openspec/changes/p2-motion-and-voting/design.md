<!-- status: pr-created -->

## Context

Decidesk is a Nextcloud app using the **thin-client** pattern: all domain data is stored in OpenRegister; the backend provides only settings, business-rule services, and PDF generation. The `Motion`, `Amendment`, `Vote`, and `VotingRound` entities were introduced in ADR-000 as primary entities for this spec with full CRUD now available via p1-crud-operations. This change adds the full governance motion-and-voting lifecycle on top of that foundation: motion submission, co-signatory collection, amendment workflow, quorum enforcement, vote casting (in-person and email), proxy delegation, real-time tallying, and result publication to the ORI API.

Dutch governance workflows (gemeenteraden, waterschappen, provinciale staten) follow strict procedural rules. A raadslid submits a motie or amendement during debate on an agendapunt. The chair opens a stemronde when debate is closed. Members vote voor / tegen / onthouding. The griffier records results and the besluit is entered in the notulen. This spec digitises that entire flow. Corporate governance (AVA, RvC) follows analogous patterns with proxy voting and SRD II compliance requirements layered on top.

## Goals / Non-Goals

**Goals:**
- Motion CRUD with lifecycle tracking (submitted → debating → voting → adopted/rejected/withdrawn)
- Digital co-signatory collection on motions
- Budget amendment motions with financial impact rationale linked to budget lines
- Amendment submission against existing motions with conflict detection
- Voting round management: open/close, configure method, enforce quorum
- Vote casting (for/against/abstain) via UI in real-time
- Proxy voting: delegate voting right for a specific VotingRound
- Email vote reply parsing and registration
- Automatic result calculation on round close with majority threshold display
- ORI API publication of voting results
- Automatic dossier folder creation (via _files) when motion is adopted
- Complete audit trail via Nextcloud Activity for all motion and vote events

**Non-Goals:**
- Generating formal besluit/decision objects (p2-minutes-and-decisions)
- Recording and signing meeting minutes (p2-minutes-and-decisions)
- AI-assisted motion drafting (future AI spec)
- Ranked-choice preference polling for citizen panels beyond basic data capture (future participation spec)
- Full shareholder SRD II disclosure automation (future compliance spec)
- Video/webcast indexing of vote events (future media spec)

## Decisions

### 1. Motion lifecycle via OpenRegister built-in `status` field
**Decision**: Track Motion lifecycle using the OpenRegister built-in `status` field on the Motion object, with values `submitted`, `debating`, `voting`, `adopted`, `rejected`, `withdrawn`.
**Rationale**: ADR-000 defines `lifecycle` as an explicit property on Motion. The OpenRegister `status` field provides equivalent functionality without a schema divergence; the `lifecycle` property in ADR-000 is the canonical schema property — the frontend reads it as `lifecycle`. Backend transitions use `ObjectService.saveObject()`.
**Alternative considered**: A workflow engine via `WorkflowEngineController` — deferred to a future workflow spec; the linear lifecycle is simple enough to implement in `MotionService`.

### 2. Budget impact data in OpenRegister built-in notes on Motion
**Decision**: Budget amendment details (budget line reference, amount delta, policy rationale) are stored as a structured note on the Motion object, with `title: "Budget impact"` and a JSON body containing `{ "budgetLine": "...", "amountDelta": ..., "rationale": "..." }`.
**Rationale**: ADR-000 does not add a `budgetImpact` property to Motion. Notes are built-in to every OpenRegister object and support structured content. The financial controller reads the note body to compute impact. This avoids a schema migration and keeps the entity lean.
**Alternative considered**: A dedicated BudgetImpact entity — rejected (ADR-000 is the source of truth; no new entities allowed in this spec; note is sufficient at p2 scope).

### 3. Co-signatories stored in `coSigners` array on Motion
**Decision**: The `coSigners` array property on Motion (as defined in ADR-000) is used to record display names of co-signers. When a Participant confirms co-signature, their `displayName` is appended to the array and the Motion is saved via `ObjectService.saveObject()`.
**Rationale**: ADR-000 already defines `coSigners: array` on Motion. No schema change or relation needed. The proposer sends a Nextcloud notification per invitee; on confirmation the name is appended atomically in `MotionService::addCoSigner()`.
**Alternative considered**: OpenRegister relation Motion → Participant per co-signer — rejected (relation mechanism is more powerful than needed; the array in ADR-000 is the canonical field; searching co-signer names across motions works via full-text on the array).

### 4. Quorum check before opening VotingRound
**Decision**: `VotingService::openVotingRound()` calls `ObjectService.findAll()` to count Participants with an active (non-null `leftAt`) relation to the GovernanceBody, compares against `Meeting.quorumRequired`, and refuses to open the round if quorum is not met, returning a `400 Bad Request` with message "Quorum niet bereikt".
**Rationale**: Quorum enforcement is domain business logic — the one place where custom PHP is warranted. `quorumRequired` is already on the Meeting entity in ADR-000.
**Alternative considered**: Frontend-only quorum check — rejected (ADR-005: admin checks MUST be on the backend; frontend-only = security vulnerability).

### 5. Proxy voting stored as `isProxy: true` on Vote with a delegator relation
**Decision**: When a Participant casts a vote on behalf of another (proxy), the Vote object is created with `isProxy: true` and an OpenRegister relation `delegator` from Vote → Participant (the original voter). The system enforces one proxy per Participant per VotingRound in `VotingService::castVote()`.
**Rationale**: ADR-000 already defines `isProxy: boolean` on Vote. The `delegator` relation uses the built-in OpenRegister relations mechanism. No additional entity needed.
**Alternative considered**: A ProxyDelegation entity — rejected (adds complexity; Vote.isProxy + relation covers the required data; the one-proxy-per-round rule is enforced in the service layer).

### 6. Email vote reply via Nextcloud Mail hook
**Decision**: When a VotingRound opens with remote participants, `VotingService::openVotingRound()` calls `NotificationService` to send a voting invitation email containing vote instructions. A `MailReplyHandler` background job polls for replies with a recognised vote keyword ("Voor", "Tegen", "Onthouding") and calls `VotingService::castVote()` with `isProxy: false`. Confirmation is sent via `NotificationService`.
**Rationale**: Story 11 acceptance criteria require email-reply voting. OpenRegister provides `NotificationService` for outbound; a lightweight polling background job (OCP `IJob`) is the correct Nextcloud pattern for inbound.
**Alternative considered**: Webhook from Nextcloud Mail — not yet a stable API; polling job is more reliable at current platform maturity.

### 7. ORI publication via dedicated `OriPublicationService`
**Decision**: A new `OriPublicationService` handles the HTTP call to the configured ORI endpoint, sending voting round results as JSON-LD following the ORI standard. The endpoint URL is stored in app config via `IAppConfig`. The service is called from `VotingService::closeVotingRound()` if publication is configured.
**Rationale**: ORI API integration is the only external API integration in this spec — exactly the kind of custom code apps SHOULD build per ADR-001. Kept separate from `VotingService` for testability.
**Alternative considered**: Webhook via built-in `WebhookService` — rejected (ORI requires specific JSON-LD format and auth headers that the generic webhook service cannot express without custom mapping code).

### 8. Adopted motion triggers dossier folder via `_files` metadata
**Decision**: When `VotingService::closeVotingRound()` records `result: "adopted"` and updates `Motion.lifecycle` to `adopted`, it calls `FileService.createFolder()` under a naming convention `motions/{motionSlug}/` and attaches a `_files` metadata link to the Motion. Subsequent document attachments (amendments, vote result PDF) land in this folder.
**Rationale**: Story 18 acceptance criteria. `FileService` is the platform-provided mechanism — no custom file controller. Folder creation is the only additional call in the close-round flow.
**Alternative considered**: Deferred to p2-minutes-and-decisions — rejected because the dossier is motion-centric, not minutes-centric; creating it at adoption is the earliest sensible trigger.

## Reuse Analysis (ADR-012)

| Capability | OpenRegister service / component used | Custom code |
|---|---|---|
| Motion CRUD | `ObjectService`, `CnIndexPage`, `CnDetailPage` | None |
| Lifecycle display | `CnTimelineStages`, `CnStatusBadge` | `MotionService::transitionLifecycle()` |
| Co-signature notifications | `NotificationService` | `MotionService::requestCoSignature()`, `addCoSigner()` |
| Amendment conflict detection | `ObjectService.findAll()` (text overlap check) | `MotionService::detectConflicts()` |
| Voting round open/close | `ObjectService.saveObject()` | `VotingService::openVotingRound()`, `closeVotingRound()` |
| Quorum check | `ObjectService.findAll()` Participant count | `VotingService::checkQuorum()` |
| Vote casting | `ObjectService.saveObject()` | `VotingService::castVote()` |
| Proxy enforcement | `ObjectService.findAll()` (one-proxy check) | `VotingService::castVote()` proxy guard |
| Email vote | `NotificationService` (outbound) | `MailReplyHandler` background job |
| Result tally | Computed from Vote objects via `ObjectService.findAll()` | `VotingService::tallyResults()` |
| ORI publication | HTTP client (new) | `OriPublicationService` |
| Calendar deadline | `CalendarEventService` | Called from `VotingService::openVotingRound()` |
| Dossier folder | `FileService` | Called from `VotingService::closeVotingRound()` |
| Audit trail | `ActivityService` (built-in) | None (automatic via OpenRegister) |
| Export | `ExportService` + `CnMassExportDialog` | None |
| Search | `IndexService` + `CnFilterBar` | None |

No new capabilities were identified that should be moved to OpenRegister core.

## Seed Data (Dutch examples)

### Motion

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motie-duurzame-energie-2025-04-14" },
    "title": "Motie Duurzame Energie Haarlemmermeer",
    "text": "De raad van de gemeente Haarlemmermeer, gehoord de beraadslaging, overwegende dat de gemeente haar klimaatdoelstelling van 50% hernieuwbare energie in 2030 wil realiseren, verzoekt het college om voor 1 oktober 2025 een uitvoeringsplan duurzame energie aan de raad voor te leggen.",
    "motionType": "motion",
    "proposer": "J. van der Berg",
    "coSigners": ["M. de Vries", "F. el-Amrani"],
    "lifecycle": "adopted",
    "submittedAt": "2025-04-14T19:32:00+02:00",
    "status": "adopted"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "amendement-begroting-sociaal-domein-2025" },
    "title": "Amendement Begroting Sociaal Domein 2025",
    "text": "De raad besluit om de begroting van het sociaal domein 2025 te wijzigen door programma 4 (Jeugdzorg) met € 250.000 te verhogen ten laste van de algemene reserve, en de toelichting aan te passen conform het amendement.",
    "motionType": "amendment",
    "proposer": "A. Pietersen",
    "coSigners": ["S. de Jong"],
    "lifecycle": "voting",
    "submittedAt": "2025-04-14T20:15:00+02:00",
    "status": "voting"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motie-vreemd-parkeerbeleid-binnenstad" },
    "title": "Motie Vreemd aan de Orde: Parkeerbeleid Binnenstad",
    "text": "De raad verzoekt het college de parkeertarieven in de binnenstad niet te verhogen vóór een integrale mobiliteitsvisie is vastgesteld.",
    "motionType": "order",
    "proposer": "H. Bakker",
    "coSigners": [],
    "lifecycle": "rejected",
    "submittedAt": "2025-04-14T21:00:00+02:00",
    "status": "rejected"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motie-raadhuisplein-veiligheid-2025" },
    "title": "Motie Veiligheidsplan Raadhuisplein",
    "text": "De raad verzoekt het college om samen met omwonenden en politie een veiligheidsplan voor het Raadhuisplein op te stellen en dit vóór 1 juli 2025 ter besluitvorming aan de raad voor te leggen.",
    "motionType": "motion",
    "proposer": "N. Yilmaz",
    "coSigners": ["P. Ganpat"],
    "lifecycle": "debating",
    "submittedAt": "2025-04-14T20:45:00+02:00",
    "status": "debating"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "procedurele-motie-schorsing-2025-04-14" },
    "title": "Procedurele Motie: Schorsing vergadering",
    "text": "De raad besluit de vergadering gedurende 15 minuten te schorsen voor fractieberaad over agendapunt 5.",
    "motionType": "procedural",
    "proposer": "J. van der Berg",
    "coSigners": [],
    "lifecycle": "adopted",
    "submittedAt": "2025-04-14T20:58:00+02:00",
    "status": "adopted"
  }
]
```

### Amendment

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Amendment", "slug": "amendement-energie-uitvoeringsplan-termijn" },
    "title": "Amendement: Uitvoeringsplan vóór 1 juli i.p.v. 1 oktober",
    "text": "In de motie Duurzame Energie wordt '1 oktober 2025' vervangen door '1 juli 2025', zodat het college meer urgentie voelt.",
    "proposer": "F. el-Amrani",
    "lifecycle": "adopted",
    "submittedAt": "2025-04-14T19:45:00+02:00",
    "status": "adopted"
  },
  {
    "@self": { "register": "decidesk", "schema": "Amendment", "slug": "amendement-energie-participatie-toevoeging" },
    "title": "Amendement: Participatietraject toevoegen",
    "text": "Aan de motie Duurzame Energie wordt toegevoegd: 'waarbij het college bewoners en lokale bedrijven actief betrekt via een participatietraject'.",
    "proposer": "S. de Jong",
    "lifecycle": "rejected",
    "submittedAt": "2025-04-14T19:50:00+02:00",
    "status": "rejected"
  },
  {
    "@self": { "register": "decidesk", "schema": "Amendment", "slug": "amendement-begroting-bedrag-aanpassing" },
    "title": "Amendement: Verhoging jeugdzorg € 350.000 i.p.v. € 250.000",
    "text": "In het amendement Begroting Sociaal Domein wordt '€ 250.000' vervangen door '€ 350.000' om de volledige verwachte uitstroom op te vangen.",
    "proposer": "A. de Vries",
    "lifecycle": "submitted",
    "submittedAt": "2025-04-14T20:30:00+02:00",
    "status": "submitted"
  }
]
```

### VotingRound

```json
[
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "stemronde-motie-duurzame-energie-2025-04-14" },
    "votingMethod": "for-against-abstain",
    "isSecret": false,
    "openedAt": "2025-04-14T20:05:00+02:00",
    "closedAt": "2025-04-14T20:12:00+02:00",
    "quorumMet": true,
    "result": "adopted",
    "votesFor": 23,
    "votesAgainst": 8,
    "votesAbstain": 1
  },
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "stemronde-amendement-energie-termijn-2025-04-14" },
    "votingMethod": "for-against-abstain",
    "isSecret": false,
    "openedAt": "2025-04-14T19:55:00+02:00",
    "closedAt": "2025-04-14T20:02:00+02:00",
    "quorumMet": true,
    "result": "adopted",
    "votesFor": 27,
    "votesAgainst": 4,
    "votesAbstain": 1
  },
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "stemronde-parkeerbeleid-2025-04-14" },
    "votingMethod": "for-against-abstain",
    "isSecret": false,
    "openedAt": "2025-04-14T21:10:00+02:00",
    "closedAt": "2025-04-14T21:17:00+02:00",
    "quorumMet": true,
    "result": "rejected",
    "votesFor": 12,
    "votesAgainst": 19,
    "votesAbstain": 1
  },
  {
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "stemronde-begroting-sociaal-2025-04-14" },
    "votingMethod": "for-against-abstain",
    "isSecret": false,
    "openedAt": "2025-04-14T20:18:00+02:00",
    "closedAt": null,
    "quorumMet": true,
    "result": null,
    "votesFor": 0,
    "votesAgainst": 0,
    "votesAbstain": 0
  }
]
```

### Vote

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-vdberg-duurzame-energie" },
    "value": "for",
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-04-14T20:06:45+02:00"
  },
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-bakker-duurzame-energie" },
    "value": "against",
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-04-14T20:07:12+02:00"
  },
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-proxy-ganpat-voor-elamrani" },
    "value": "for",
    "weight": 1,
    "isProxy": true,
    "castAt": "2025-04-14T20:08:30+02:00"
  },
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-yilmaz-duurzame-energie" },
    "value": "abstain",
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-04-14T20:09:00+02:00"
  }
]
```

## Risks / Trade-offs

- **[Risk] Concurrent vote submissions race condition** → Two votes from the same Participant in rapid succession (double-submit) could create duplicate Vote objects. Mitigation: `VotingService::castVote()` checks for an existing Vote from the Participant in the VotingRound using `ObjectService.findAll()` with `participantId` filter before creating a new one; if found, it overwrites rather than creates.
- **[Risk] Email reply parsing ambiguity** → A member could reply with a word other than "Voor", "Tegen", or "Onthouding" (e.g., body text in Dutch). Mitigation: `MailReplyHandler` only reads the first non-empty line of the reply; unrecognised replies trigger a re-prompt notification to the member; after 3 failed attempts the email vote path is abandoned and the member is asked to vote via the UI.
- **[Risk] Quorum count is a snapshot, not a lock** → Between quorum verification and round open, a Participant could leave. Mitigation: quorum is checked at open time only (the standard legislative practice); the `quorumMet` flag is stored on VotingRound as the authoritative record for audit purposes.
- **[Risk] ORI API unavailability** → If the ORI endpoint is down when `closeVotingRound()` is called, publication fails silently. Mitigation: `OriPublicationService` uses a retry background job (`IJob`) with exponential backoff; the UI shows "Publicatie in behandeling" status until confirmed.
- **[Trade-off] No ranked-choice UI in p2** — the `ranked-choice` votingMethod is stored in VotingRound and its Vote values capture ranks, but the frontend UI for ranked-choice preference polling (Story 10 — drag-and-drop ranking, instant runoff calculation) is deferred to a future participation spec. The data model is ready; only the specialised UI is missing.
- **[Trade-off] Budget impact as a structured note** — storing budget line details in a note rather than typed properties means no automatic financial computation. The financial controller reads the note manually and applies calculations outside the system. Full integration with a financial system is deferred to a later integration spec.

## Migration Plan

1. No schema migrations required — all four entities (Motion, Amendment, Vote, VotingRound) are defined in ADR-000 and exist in the register from p1-crud-operations
2. Add `MotionService.php` with lifecycle transition, co-signatory, conflict detection, and budget-impact note methods
3. Add `VotingService.php` with quorum check, open/close, cast vote, proxy enforcement, tally, and ORI publication trigger methods
4. Add `OriPublicationService.php` for external HTTP call to ORI endpoint
5. Add `MailReplyHandler.php` background job for email vote parsing
6. Add `MotionController.php` and `VotingController.php` (thin, <10 lines/method) with all API routes
7. Register routes in `appinfo/routes.php`; specific routes before wildcard `{slug}` routes
8. Frontend: add `MotionDetail.vue`, `MotionIndex.vue`, `VotingRoundPanel.vue`, and `VoteCard.vue` components
9. Extend `AgendaItemDetail.vue` to show linked motions panel
10. Seed data objects are upserted on install — no existing data is modified

## Open Questions

- Should co-signature requests expire after a configurable number of days? (Recommendation: default 7 days, configurable in app settings via `IAppConfig`)
- Should the vote tally be visible to all Participants in real-time, or only revealed when the round closes? (Recommendation: show live tally to chair only; reveal to all on close — this mirrors standard legislative practice)
- Should proxy delegation be revocable before the round closes? (Recommendation: yes — a Participant can cancel their proxy up until the VotingRound is opened by the chair)
- Should the ORI publication format follow ORI 1.0 or the draft ORI 2.0 standard? (Recommendation: ORI 1.0 as the stable standard; add ORI 2.0 as a settings toggle when ratified)
