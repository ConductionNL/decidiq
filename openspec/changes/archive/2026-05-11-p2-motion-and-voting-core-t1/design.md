## Context

Decidesk is a Nextcloud app using the **thin-client** pattern: all domain data is stored in OpenRegister; the backend provides only settings, business-rule services, and document generation. The `Motion`, `Amendment`, `Vote`, and `VotingRound` entities were declared in ADR-000 and are registered in `decidesk_register.json` from p1-schemas-and-data-model. This change adds the full governance motion-and-voting lifecycle on top of that foundation.

Dutch governance workflows (gemeenteraden, waterschappen, provinciale staten) follow strict procedural rules. A raadslid submits a motie or amendement during debate on an agendapunt. The chair opens a stemronde when debate is closed. Members vote voor / tegen / onthouding. The griffier records results. This spec digitalises that entire flow. Corporate governance (AVA, RvC) follows analogous patterns with proxy voting and SRD II compliance requirements layered on top (demand: 437 — Institutional Investor Meeting Voting Support).

The three highest-demand use cases driving this spec are:
1. **Proxy voting** (demand: 1662) — absent members must be able to delegate their vote digitally rather than via paper volmacht
2. **Amendment workflow** (demand: 1046/771/758) — fiscal impact must be attached to amendments; proposers must be notified of conflicts
3. **Decentralised voting security** (demand: 611) — votes must be cryptographically attributed to individual Participants via Nextcloud authentication (no anonymous voting)

## Goals / Non-Goals

**Goals:**
- Motion CRUD with lifecycle tracking and role-enforced transitions
- Digital co-signatory collection on motions
- Amendment submission against existing motions with conflict detection and notification
- Fiscal impact review: structured budget-impact note on amendment-type Motions
- Proxy voting: delegate and revoke per VotingRound, one-proxy-per-round enforcement
- Voting round management: open/close, configure method, enforce quorum
- Vote casting (for/against/abstain) via UI in real-time
- Email vote reply parsing and registration for remote Participants
- Voting schedule configuration with calendar event integration
- Automatic result tally on round close with majority threshold display
- Per-party vote aggregation display for political councils
- ORI API publication of voting results
- Complete audit trail via Nextcloud Activity for all motion and vote events
- Seed data for all four entities

**Non-Goals:**
- Generating formal besluit/decision objects (p2-minutes-and-decisions handles this)
- Recording and signing meeting minutes (p2-minutes-and-decisions)
- AI-assisted motion drafting (future AI spec)
- Full ranked-choice UI with drag-and-drop preference ordering (data model supports ranked-choice; UI deferred)
- Full shareholder SRD II disclosure automation (future compliance spec)
- Video/webcast indexing of vote events (future media spec)
- Constituency consultation workflow (demand: 1108 — deferred to participation spec p3+)

## Decisions

### 1. Motion lifecycle via OpenRegister built-in `status` field
**Decision**: Track Motion lifecycle using the ADR-000 `lifecycle` property on the Motion object, stored as the OpenRegister built-in `status` field with values `submitted`, `debating`, `voting`, `adopted`, `rejected`, `withdrawn`. Backend transitions via `ObjectService.saveObject()`.
**Rationale**: ADR-001 requires using platform capabilities. The linear lifecycle is simple enough to implement in `MotionService` without a full workflow engine. `WorkflowEngineController` is available for future complexity.
**Alternative considered**: `WorkflowEngineController` — deferred; the linear lifecycle does not warrant the overhead.

### 2. Budget impact data in OpenRegister built-in notes on Motion
**Decision**: Budget amendment details (budget line reference, amount delta, policy rationale) are stored as a structured note on the Motion object with `title: "Budget impact"` and a JSON body containing `{ "budgetLine": "...", "amountDelta": ..., "rationale": "..." }`.
**Rationale**: ADR-000 does not add a `budgetImpact` property to Motion. Notes are built-in to every OpenRegister object. This avoids a schema migration and keeps the entity lean. The CFO and financial controller read the note body to compute impact.
**Alternative considered**: A dedicated `BudgetImpact` entity — rejected (ADR-000 is the source of truth; no new entities in this spec; note is sufficient at p2 scope).

### 3. Co-signatories stored in `coSigners` array on Motion
**Decision**: The `coSigners` array property on Motion (ADR-000) records display names of confirmed co-signers. When a Participant confirms, their `displayName` is appended via `MotionService::addCoSigner()`. Invitations are sent via `NotificationService`.
**Rationale**: ADR-000 already defines `coSigners: array`. No relation or schema change needed. Full-text search on the array works via OpenRegister.
**Alternative considered**: OpenRegister relation Motion → Participant per co-signer — rejected (the array in ADR-000 is canonical; relation mechanism is more powerful than needed here).

### 4. Quorum check before opening VotingRound
**Decision**: `VotingService::openVotingRound()` counts active Participants (non-null `leftAt`) related to the GovernanceBody via `ObjectService.findAll()`, compares against `Meeting.quorumRequired`, and returns `400 Bad Request` with "Quorum niet bereikt" if not met.
**Rationale**: Quorum enforcement is domain business logic requiring a backend check. ADR-005 prohibits frontend-only security checks. `quorumRequired` is already on Meeting in ADR-000.
**Alternative considered**: Frontend-only quorum indicator — rejected (ADR-005: admin checks MUST be on the backend).

### 5. Proxy voting stored as `isProxy: true` on Vote with delegator relation
**Decision**: When a Participant casts a vote on behalf of another, the Vote object is created with `isProxy: true` and an OpenRegister relation `delegator` from Vote → Participant (the original voter). One proxy per Participant per VotingRound is enforced in `VotingService::castVote()`.
**Rationale**: ADR-000 already defines `isProxy: boolean` on Vote. The `delegator` relation uses the built-in OpenRegister relations mechanism. No additional entity needed.
**Alternative considered**: A ProxyDelegation entity — rejected (Vote.isProxy + relation covers the required data; the one-proxy-per-round rule is enforced in the service layer).

### 6. Email vote reply via Nextcloud Mail polling job
**Decision**: When a VotingRound opens and remote Participants are present, `VotingService::openVotingRound()` calls `NotificationService` to send a voting invitation. A `MailReplyHandler` background job polls for replies with recognised vote keywords ("Voor", "Tegen", "Onthouding") and calls `VotingService::castVote()` on match.
**Rationale**: Story-11 / feature "Cast vote by email reply" (demand: 418) and "Cast vote remotely during digital/hybrid ALV" (demand: 410). OpenRegister provides `NotificationService` for outbound; a polling `IJob` is the correct Nextcloud pattern for inbound.
**Alternative considered**: Webhook from Nextcloud Mail — not yet a stable API; polling job is more reliable.

### 7. ORI publication via dedicated `OriPublicationService`
**Decision**: A new `OriPublicationService` handles the HTTP call to the configured ORI endpoint, sending voting round results as JSON-LD following the ORI 1.0 standard. The endpoint URL is stored via `IAppConfig`.
**Rationale**: ORI API integration is the only external API integration in this spec — exactly the custom code apps SHOULD build per ADR-001.
**Alternative considered**: Generic `WebhookService` — rejected (ORI requires specific JSON-LD format and auth headers that the generic webhook service cannot express without custom mapping code).

## Reuse Analysis (ADR-012)

| Capability | OpenRegister service / component used | Custom code |
|---|---|---|
| Motion CRUD | `ObjectService`, `CnIndexPage`, `CnDetailPage` | None |
| Lifecycle transitions | `ObjectService.saveObject()`, `CnTimelineStages`, `CnStatusBadge` | `MotionService::transitionLifecycle()` |
| Co-signature notifications | `NotificationService` | `MotionService::requestCoSignature()`, `addCoSigner()` |
| Amendment conflict detection | `ObjectService.findAll()` (text overlap) | `MotionService::detectConflicts()` |
| Voting round open/close | `ObjectService.saveObject()` | `VotingService::openVotingRound()`, `closeVotingRound()` |
| Quorum check | `ObjectService.findAll()` Participant count | `VotingService::checkQuorum()` |
| Vote casting | `ObjectService.saveObject()` | `VotingService::castVote()` |
| Proxy enforcement | `ObjectService.findAll()` (one-proxy check) | `VotingService::castVote()` proxy guard |
| Email vote | `NotificationService` (outbound) | `MailReplyHandler` background job |
| Result tally | `ObjectService.findAll()` over Vote objects | `VotingService::tallyResults()` |
| ORI publication | HTTP client (new) | `OriPublicationService` |
| Calendar deadline | `CalendarEventService` | Called from `VotingService::openVotingRound()` |
| Audit trail | `ActivityService` (built-in) | None (automatic via OpenRegister) |
| Search / filter | `IndexService` + `CnFilterBar` | None |
| Export | `ExportService` + `CnMassExportDialog` | None |

No new capabilities identified that should be moved to OpenRegister core.

## Seed Data

### Motion (5 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motie-duurzame-energie-haarlemmermeer" },
    "title": "Motie Duurzame Energie Haarlemmermeer",
    "text": "De raad van de gemeente Haarlemmermeer, gehoord de beraadslaging, overwegende dat de gemeente haar klimaatdoelstelling van 50% hernieuwbare energie in 2030 wil realiseren, verzoekt het college om voor 1 oktober 2025 een uitvoeringsplan duurzame energie aan de raad voor te leggen.",
    "motionType": "motion",
    "proposer": "J. van der Berg",
    "coSigners": ["M. de Vries", "F. el-Amrani"],
    "lifecycle": "adopted",
    "submittedAt": "2025-04-14T19:32:00+02:00"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "amendement-begroting-sociaal-domein-2025" },
    "title": "Amendement Begroting Sociaal Domein 2025",
    "text": "De raad besluit om de begroting van het sociaal domein 2025 te wijzigen door programma 4 (Jeugdzorg) met € 250.000 te verhogen ten laste van de algemene reserve, en de toelichting aan te passen conform het amendement.",
    "motionType": "amendment",
    "proposer": "A. Pietersen",
    "coSigners": ["S. de Jong"],
    "lifecycle": "voting",
    "submittedAt": "2025-04-14T20:15:00+02:00"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motie-vreemd-parkeerbeleid-binnenstad" },
    "title": "Motie Vreemd aan de Orde: Parkeerbeleid Binnenstad",
    "text": "De raad verzoekt het college de parkeertarieven in de binnenstad niet te verhogen vóór een integrale mobiliteitsvisie is vastgesteld.",
    "motionType": "order",
    "proposer": "H. Bakker",
    "coSigners": [],
    "lifecycle": "rejected",
    "submittedAt": "2025-04-14T21:00:00+02:00"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motie-raadhuisplein-veiligheid-2025" },
    "title": "Motie Veiligheidsplan Raadhuisplein",
    "text": "De raad verzoekt het college om samen met omwonenden en politie een veiligheidsplan voor het Raadhuisplein op te stellen en dit vóór 1 juli 2025 ter besluitvorming aan de raad voor te leggen.",
    "motionType": "motion",
    "proposer": "N. Yilmaz",
    "coSigners": ["P. Ganpat"],
    "lifecycle": "debating",
    "submittedAt": "2025-04-14T20:45:00+02:00"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "procedurele-motie-schorsing-ab-waterschap" },
    "title": "Procedurele Motie: Schorsing Vergadering Waterschap",
    "text": "Het Algemeen Bestuur besluit de vergadering gedurende 20 minuten te schorsen voor fractieberaad over het waterbeheerprogramma 2026.",
    "motionType": "procedural",
    "proposer": "Dijkgraaf R. Smits",
    "coSigners": [],
    "lifecycle": "adopted",
    "submittedAt": "2025-04-10T15:20:00+02:00"
  }
]
```

### Amendment (4 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Amendment", "slug": "amendement-energie-uitvoeringsplan-termijn" },
    "title": "Amendement: Uitvoeringsplan vóór 1 juli i.p.v. 1 oktober",
    "text": "In de motie Duurzame Energie wordt '1 oktober 2025' vervangen door '1 juli 2025', zodat het college meer urgentie voelt.",
    "proposer": "F. el-Amrani",
    "lifecycle": "adopted",
    "submittedAt": "2025-04-14T19:45:00+02:00"
  },
  {
    "@self": { "register": "decidesk", "schema": "Amendment", "slug": "amendement-energie-participatietraject" },
    "title": "Amendement: Participatietraject toevoegen",
    "text": "Aan de motie Duurzame Energie wordt toegevoegd: 'waarbij het college bewoners en lokale bedrijven actief betrekt via een participatietraject'.",
    "proposer": "S. de Jong",
    "lifecycle": "rejected",
    "submittedAt": "2025-04-14T19:50:00+02:00"
  },
  {
    "@self": { "register": "decidesk", "schema": "Amendment", "slug": "amendement-begroting-bedrag-aanpassing" },
    "title": "Amendement: Verhoging jeugdzorg € 350.000 i.p.v. € 250.000",
    "text": "In het amendement Begroting Sociaal Domein wordt '€ 250.000' vervangen door '€ 350.000' om de volledige verwachte uitstroom op te vangen.",
    "proposer": "A. de Vries",
    "lifecycle": "submitted",
    "submittedAt": "2025-04-14T20:30:00+02:00"
  },
  {
    "@self": { "register": "decidesk", "schema": "Amendment", "slug": "amendement-waterschapsbegroting-dijkversterking" },
    "title": "Amendement Waterschapsbegroting: Extra krediet dijkversterking",
    "text": "De begroting van het waterschap voor 2026 wordt gewijzigd door het krediet voor dijkversterking programma Noord te verhogen met € 1.200.000 ten laste van de algemene reserve.",
    "proposer": "AB-lid T. van Wijk",
    "lifecycle": "debating",
    "submittedAt": "2025-04-10T14:45:00+02:00"
  }
]
```

### VotingRound (4 objects)

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
    "@self": { "register": "decidesk", "schema": "VotingRound", "slug": "stemronde-amendement-energie-termijn" },
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

### Vote (5 objects)

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
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-yilmaz-duurzame-energie-onthouding" },
    "value": "abstain",
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-04-14T20:09:00+02:00"
  },
  {
    "@self": { "register": "decidesk", "schema": "Vote", "slug": "stem-pietersen-parkeerbeleid-voor" },
    "value": "for",
    "weight": 1,
    "isProxy": false,
    "castAt": "2025-04-14T21:11:20+02:00"
  }
]
```

## Risks / Trade-offs

- **[Risk] Concurrent vote submissions race condition** → Two votes from the same Participant in rapid succession could create duplicate Vote objects. Mitigation: `VotingService::castVote()` checks for an existing Vote from the Participant in the round via `ObjectService.findAll()` before creating a new one; if found, it overwrites rather than inserts.
- **[Risk] Email reply parsing ambiguity** → A member may reply with a non-keyword body. Mitigation: `MailReplyHandler` reads only the first non-empty line; unrecognised replies trigger a re-prompt notification; after 3 failed attempts the email vote path is abandoned and the member is directed to the UI.
- **[Risk] Quorum count is a snapshot, not a lock** → A Participant could leave between quorum verification and round opening. Mitigation: quorum is checked at open time only (standard legislative practice); the `quorumMet` flag on VotingRound is the authoritative record for audit purposes.
- **[Risk] ORI API unavailability** → Publication may fail silently. Mitigation: `OriPublicationService` uses a retry `IJob` with exponential backoff; the UI shows "Publicatie in behandeling" until confirmed.
- **[Trade-off] No ranked-choice UI in T1** → The `ranked-choice` votingMethod is storable and its Vote values capture ranks, but the frontend preference-ordering UI (drag-and-drop, instant runoff calculation) is deferred to a future participation spec. The data model is ready.
- **[Trade-off] Budget impact in notes, not typed properties** → Storing budget line details in a note means no automatic financial computation in the app. The financial controller reads the note manually. Full integration with a financial system is deferred to a later integration spec.

## Migration Plan

1. No schema migrations required — all four entities (Motion, Amendment, Vote, VotingRound) are defined in ADR-000 and registered in `decidesk_register.json` from p1-schemas-and-data-model
2. Add `MotionService.php` with lifecycle transition, co-signatory, conflict detection, and budget-impact note methods
3. Add `VotingService.php` with quorum check, open/close, cast, proxy enforcement, tally, and ORI trigger methods
4. Add `OriPublicationService.php` for external HTTP call to ORI endpoint
5. Add `MailReplyHandler.php` background job for email vote parsing
6. Add `MotionController.php` and `VotingController.php` (thin, <10 lines/method) with all API routes
7. Register all routes in `appinfo/routes.php` — specific routes before wildcard `{slug}` routes
8. Add `MotionIndex.vue`, `MotionDetail.vue`, `AmendmentDetail.vue`, `VotingRoundPanel.vue`, `VoteCard.vue` to frontend
9. Extend `AgendaItemDetail.vue` with linked motions panel and "Motie indienen" action
10. Add Pinia object stores for Motion, Amendment, Vote, VotingRound; extend `initializeStores()`
11. Add ORI endpoint and email voting configuration fields to admin settings page
12. Seed data objects are upserted on install — no existing data is modified

## Open Questions

- Should co-signature requests expire after a configurable number of days? (Recommendation: default 7 days, configurable in app settings via `IAppConfig`)
- Should the vote tally be visible to all Participants in real-time, or only revealed when the round closes? (Recommendation: show live count to chair only; reveal full breakdown to all on close — mirrors standard legislative practice)
- Should proxy delegation be revocable before or after the round opens? (Recommendation: revocable only before the round opens; once the chair opens the round the delegation is locked)
- Should per-faction/party vote aggregation require explicit party configuration, or derive from Participant.party? (Recommendation: derive from `Participant.party`; no additional configuration needed)
