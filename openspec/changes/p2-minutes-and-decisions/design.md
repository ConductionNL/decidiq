## Context

Decidesk is a thin-client Nextcloud app: all domain data is stored in OpenRegister. The three entities for this spec — Minutes, Decision, and ActionItem — are already declared as OpenRegister schemas in `decidesk_register.json` (from p1-schemas-and-data-model). This change builds the post-meeting workflow on top of those existing schemas.

OpenRegister provides full-text search, audit trails, file attachments, relation management, and workflow engine transitions for free. This change builds only the domain-specific workflow logic: lifecycle transitions for minutes, publication flagging for decisions, minutes content generation, and overdue detection for action items.

## Goals / Non-Goals

**Goals:**
- Deliver full CRUD + lifecycle views for Minutes with the `draft → review → approved → signed → published` workflow
- Enable decision recording linked to source motions and vote outcomes
- Enable ORI publication flagging of adopted decisions (`isPublished` + `publishedAt`)
- Provide action item tracking with assignment, due dates, and automated overdue status
- Generate initial minutes drafts from linked meeting agenda data (template-based)
- Extend the Dashboard with post-meeting KPI widgets
- Load seed data for Minutes, Decision, and ActionItem entities

**Non-Goals:**
- Full ORI / PLOOI webhook integration (deferred to p3-ori-publication)
- Governance body domain configuration (p3-governance-bodies)
- AI-powered meeting transcription (future, demand: 1)
- External archiving system integration (p3)
- Motion or voting workflows (completed in p2-motion-and-voting)

## Decisions

### 1. Minutes lifecycle uses OpenRegister built-in `status` field
**Decision**: Map the Minutes `lifecycle` property to OpenRegister's built-in `status` field. Lifecycle transitions (draft → review → approved → signed → published) are managed via `WorkflowEngineController`.
**Rationale**: ADR-001 requires using platform capabilities. `status` is a built-in field with workflow engine support — no custom state machine code needed.
**Alternative considered**: Custom `lifecycle` field managed in PHP service — rejected because it duplicates the platform's workflow engine.

### 2. Digital signing stores signer display names in `signedBy` array
**Decision**: `signedBy` on the Minutes object stores the `displayName` values of the chair and secretary who approved. The `approvedAt` timestamp is recorded when transitioning to `approved`. The audit trail provides full traceability.
**Rationale**: Full cryptographic PKI signing is out of scope for v1. Display name + audit trail is sufficient for legal governance traceability. Full digital signatures can be layered on in a future sprint via Nextcloud Sign integration.
**Alternative considered**: Integration with Nextcloud Sign — deferred to future sprint.

### 3. Decision publication sets `isPublished` flag via explicit user action
**Decision**: A "Publiceren" button on an adopted Decision's detail page calls `ObjectService::saveObject()` to set `isPublished: true` and `publishedAt: <now>`. The actual ORI API push is deferred to p3.
**Rationale**: Explicit publish action maintains human oversight over what is sent to ORI. The flag in the Decision object is auditable and provides a clear handoff for p3. Automatic publish-on-adopt would risk publishing decisions before they have been signed off.
**Alternative considered**: Automatic publication on `outcome: adopted` — rejected to preserve human oversight.

### 4. Template-based minutes generation (AI transcription deferred)
**Decision**: A "Concept genereren" button on the Minutes detail page calls `MinutesGenerationService::generateDraft()`, which fetches the linked Meeting's AgendaItems, Motions, VotingRounds, and Decisions and renders them into the `content` field using a structured Dutch template.
**Rationale**: Meets the high-demand "Automated minutes generation" feature (demand: 186). Template-based generation is deterministic, privacy-compliant, and does not require LLM infrastructure. AI transcription (demand: 1) is deferred.
**Alternative considered**: `ChatService` / LLM-based generation — deferred; template-based is sufficient for v1 and far safer for a legal audit record.

### 5. Overdue action items detected via daily background job
**Decision**: An `IJob` subclass (`OverdueActionItemsJob`) runs daily. It queries ActionItems where `taskStatus` is `open` or `in-progress` and `dueDate < now()`, then calls `ObjectService::saveObject()` to set `taskStatus: overdue`.
**Rationale**: OpenRegister does not have a built-in scheduled status transition. A cron-style background job (ADR-003 pattern) is correct. The frontend also calculates and shows overdue state client-side for immediate feedback — the job is a best-effort sync.
**Alternative considered**: Frontend-only overdue detection — rejected because it would not reliably update the persisted `taskStatus` field needed for filtering and reporting.

## Reuse Analysis (ADR-012)

| Capability | OpenRegister / Platform service used |
|------------|--------------------------------------|
| Minutes / Decision / ActionItem CRUD | `ObjectService` + `CnIndexPage` + `CnDetailPage` |
| Lifecycle transitions | `WorkflowEngineController` + `CnTimelineStages` |
| Version / revision history | `AuditTrailService` (built-in) + `CnObjectSidebar` → `CnAuditTrailTab` |
| Decision full-text search | `IndexService` + `CnFilterBar` + `CnFacetSidebar` |
| File attachments (signed minutes PDFs) | `FileService` + `CnObjectSidebar` → `CnFilesTab` |
| Action item overdue detection | Custom `IJob` (no platform equivalent for scheduled status transitions) |
| Minutes content generation | Custom `MinutesGenerationService` (domain-specific; no platform equivalent) |
| ORI publication flag | `ObjectService::saveObject()` (sets flag; actual webhook = p3) |

No overlap with existing specs. Minutes generation and overdue detection are domain-specific business logic with no platform equivalent.

## Seed Data

### Minutes (4 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Minutes", "slug": "notulen-raad-2025-03-20" },
    "title": "Notulen Gemeenteraadsvergadering 20 maart 2025",
    "lifecycle": "published",
    "content": "De vergadering wordt geopend door de voorzitter om 19:30 uur. Aanwezig: 33 van 37 raadsleden. De agenda wordt ongewijzigd vastgesteld. Punt 1 — opening en mededelingen: de voorzitter meldt dat de gemeente een subsidie heeft ontvangen voor verduurzaming. Punt 5 — Woningbouwplan Oost 2025-2030: na bespreking wordt het plan aangenomen met 30 stemmen voor en 3 tegen...",
    "approvedAt": "2025-04-10T19:45:00Z",
    "signedBy": ["Roos de Vries", "Jan Bakker"],
    "version": 2
  },
  {
    "@self": { "register": "decidesk", "schema": "Minutes", "slug": "notulen-commissie-wonen-2025-04-01" },
    "title": "Notulen Commissievergadering Wonen & Ruimte 1 april 2025",
    "lifecycle": "signed",
    "content": "Aanvang: 19:00 uur. Voorzitter: Henk Bakker. Commissieleden aanwezig: 7 van 9. Agendapunt 2 — bestemmingsplan Groningen-Noord: de commissie adviseert de raad het plan ongewijzigd vast te stellen. Agendapunt 3 — huurbeleid sociale woningbouw: de commissie wenst aanvullende informatie van het college...",
    "approvedAt": "2025-04-15T10:00:00Z",
    "signedBy": ["Henk Bakker"],
    "version": 1
  },
  {
    "@self": { "register": "decidesk", "schema": "Minutes", "slug": "notulen-ab-waterschap-2025-04-10" },
    "title": "Notulen Vergadering Algemeen Bestuur Waterschap Aa en Maas 10 april 2025",
    "lifecycle": "review",
    "content": "Concept-verslag ter vaststelling. De vergadering van het Algemeen Bestuur wordt geopend door de dijkgraaf om 14:00 uur. Aanwezig: 21 van 23 leden. Agendapunt 3 — Waterbeheerprogramma 2026: bespreking over de financiering van dijkversterking...",
    "version": 1
  },
  {
    "@self": { "register": "decidesk", "schema": "Minutes", "slug": "notulen-directieoverleg-2025-04-14" },
    "title": "Verslag Directieoverleg Gemeente Utrecht 14 april 2025",
    "lifecycle": "draft",
    "version": 1
  }
]
```

### Decision (5 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Decision", "slug": "besluit-woningbouwplan-oost" },
    "title": "Vaststelling Woningbouwplan Oost 2025-2030",
    "text": "De gemeenteraad van Westerkwartier stelt het Woningbouwplan Oost 2025-2030 vast en machtigt het college tot uitvoering conform bijgaande planning en budgettering.",
    "decisionDate": "2025-03-20T21:15:00Z",
    "outcome": "adopted",
    "isPublished": true,
    "publishedAt": "2025-03-21T09:00:00Z",
    "legalBasis": "Wet ruimtelijke ordening art. 3.1"
  },
  {
    "@self": { "register": "decidesk", "schema": "Decision", "slug": "besluit-duurzaamheidsagenda-2026" },
    "title": "Vaststelling Duurzaamheidsagenda 2026",
    "text": "De raad stelt de Duurzaamheidsagenda Westerkwartier 2026 vast en stelt €200.000 beschikbaar voor uitvoering van de daarin opgenomen maatregelen.",
    "decisionDate": "2025-03-20T20:45:00Z",
    "outcome": "adopted",
    "isPublished": true,
    "publishedAt": "2025-03-21T09:00:00Z",
    "legalBasis": "Gemeentewet art. 147"
  },
  {
    "@self": { "register": "decidesk", "schema": "Decision", "slug": "besluit-bezwaar-kapvergunning" },
    "title": "Besluit op bezwaar kapvergunning Dorpsstraat 12",
    "text": "De gemeenteraad verklaart het bezwaar van de bewonersvereniging gegrond en herroept de verleende kapvergunning voor de eik aan Dorpsstraat 12.",
    "decisionDate": "2025-04-01T14:30:00Z",
    "outcome": "adopted",
    "isPublished": false,
    "legalBasis": "Algemene wet bestuursrecht art. 7:11"
  },
  {
    "@self": { "register": "decidesk", "schema": "Decision", "slug": "besluit-amendement-cultuursubsidie-afgewezen" },
    "title": "Amendement verhoging cultuursubsidie — niet aanvaard",
    "text": "Het amendement tot verhoging van het cultuursubsidiebudget 2026 met €75.000 wordt niet aanvaard.",
    "decisionDate": "2025-04-10T21:00:00Z",
    "outcome": "rejected",
    "isPublished": false
  },
  {
    "@self": { "register": "decidesk", "schema": "Decision", "slug": "besluit-fusie-dorpsraden" },
    "title": "Instemming fusie dorpsraden Noord en Oost per 1 januari 2026",
    "text": "De raad stemt in met de samenvoeging van dorpsraad Noord en dorpsraad Oost tot één dorpsraad Noord-Oost, ingaande 1 januari 2026.",
    "decisionDate": "2025-04-10T20:30:00Z",
    "outcome": "adopted",
    "isPublished": true,
    "publishedAt": "2025-04-11T08:30:00Z"
  }
]
```

### ActionItem (5 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-woningbouw-uitvoeringsplan" },
    "title": "Uitvoeringsplan Woningbouwplan Oost opstellen",
    "description": "College stelt uitvoeringsplan op inclusief fasering, budgetten en planning en presenteert dit aan de raad per 1 juli 2025.",
    "assignee": "Wethouder Wonen",
    "dueDate": "2025-07-01T00:00:00Z",
    "taskStatus": "in-progress"
  },
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-duurzaamheid-offerteverzoek" },
    "title": "Offerteverzoek zonnepanelen gemeentelijke gebouwen uitzetten",
    "description": "Inkoopteam vraagt offertes op bij minimaal 3 leveranciers conform gemeentelijk aanbestedingsbeleid.",
    "assignee": "Inkoopcoördinator",
    "dueDate": "2025-05-15T00:00:00Z",
    "taskStatus": "completed",
    "completedAt": "2025-05-10T16:00:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-notulen-distribueren" },
    "title": "Definitieve notulen raadsvergadering 20 maart distribueren",
    "description": "Griffier verstuurt vastgestelde notulen aan alle raadsleden en publiceert op gemeentelijke website.",
    "assignee": "Griffier",
    "dueDate": "2025-04-11T00:00:00Z",
    "taskStatus": "completed",
    "completedAt": "2025-04-11T09:30:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-besluiten-ori-publicatie" },
    "title": "Besluiten raadsvergadering publiceren via ORI-koppeling",
    "description": "Informatiemanager controleert ORI-export en bevestigt succesvolle aanlevering bij PLOOI.",
    "assignee": "Informatiemanager",
    "dueDate": "2025-03-22T00:00:00Z",
    "taskStatus": "overdue"
  },
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-fusie-dorpsraden-convenant" },
    "title": "Convenant fusie dorpsraden Noord en Oost opstellen en laten ondertekenen",
    "description": "Gemeentelijke contactpersoon stelt convenant op in samenspraak met beide dorpsraden en legt het ter ondertekening voor.",
    "assignee": "Beleidsmedewerker Participatie",
    "dueDate": "2025-10-01T00:00:00Z",
    "taskStatus": "open"
  }
]
```

## Risks / Trade-offs

- **[Risk] Minutes generation produces incomplete or inaccurate drafts** → Mitigation: generated content is explicitly a `draft`; the clerk must review and edit before transitioning to `review`. The feature is labelled "Concept genereren" to set expectations.
- **[Risk] ORI publication flag is set but the actual ORI API webhook is not yet implemented (p3)** → Mitigation: `isPublished: true` with `publishedAt` timestamp provides an auditable record. Stakeholders are informed that publication is flagged, not yet transmitted to PLOOI. The p3 sprint reads this flag to drive the webhook.
- **[Risk] Background job for overdue detection may fail silently** → Mitigation: the frontend also calculates and displays overdue state client-side (`dueDate < today`); the background job is a best-effort status sync. Job errors are logged to the Nextcloud log.
- **[Trade-off] `signedBy` stores display names, not cryptographic signatures** → Acceptable for v1; the OpenRegister audit trail records who made the `approved` transition, providing non-repudiation. Full PKI signing deferred to a future sprint via Nextcloud Sign integration.

## Migration Plan

1. No schema changes — Minutes, Decision, and ActionItem are already registered in `decidesk_register.json` from p1-schemas-and-data-model
2. Add seed data for all 3 new entities via the existing repair step (confirm `ConfigurationService::importFromApp()` is idempotent — it upserts by slug)
3. Add new Vue Router routes and Pinia stores for Minutes, Decision, and ActionItem
4. Add `MinutesGenerationService` PHP service
5. Add `OverdueActionItemsJob` PHP background job class and register it in `appinfo/info.xml`
6. Extend `SettingsService::getSettings()` to include new register/schema slugs for the 3 stores

## Open Questions

- Should the "Concept genereren" minutes feature include a preview modal before overwriting the `content` field? (Recommendation: yes — always show a diff/preview before applying generated text to avoid data loss)
- Should ORI publication be automatic when `outcome: adopted` is recorded, or only on explicit "Publiceren" user action? (Recommendation: explicit user action to maintain human oversight; automatic publication is an opt-in configuration setting for p3)
