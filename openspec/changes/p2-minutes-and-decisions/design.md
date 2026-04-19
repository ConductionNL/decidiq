## Context

Decidesk is a thin-client Nextcloud app: all domain data is stored in OpenRegister. The three entities for this spec — Minutes, Decision, and ActionItem — are already declared as OpenRegister schemas in `decidesk_register.json` (from p1-schemas-and-data-model). This change builds the post-meeting workflow on top of those existing schemas.

OpenRegister provides full-text search, audit trails, file attachments, relation management, and workflow engine transitions for free. This change builds only the domain-specific workflow logic: lifecycle transitions for minutes, publication flagging for decisions, minutes content generation, and overdue detection for action items.

**Current state:** After p1-schemas-and-data-model, the Minutes, Decision, and ActionItem schemas exist in OpenRegister but have no UI views, lifecycle logic, or integrations. Clerks today manage post-meeting workflows across disconnected tools (email, Word, SharePoint), creating version-control risks, missed publication deadlines, and accountability gaps for action items never tracked to completion.

**Stakeholders:** Board Secretary / Company Secretary (primary author and approver of minutes), Chair (approver and signer), Supervisory Board Members (readers), Legal Counsel (publication compliance), CEO/Director (decision accountability)

**Depends on:** p1-schemas-and-data-model (Minutes, Decision, ActionItem schemas registered in `decidesk_register.json`), p1-dashboard-and-navigation (Dashboard exists), p1-crud-operations (app-foundation stores and routing pattern established)

## Goals / Non-Goals

**Goals:**
- Deliver full CRUD + lifecycle views for Minutes with the `draft → review → approved → signed → published` workflow
- Enable decision recording linked to source motions and vote outcomes
- Enable ORI publication flagging of adopted decisions (`isPublished` + `publishedAt`)
- Provide action item tracking with assignment, due dates, and automated overdue status
- Generate initial minutes drafts from linked meeting agenda data (template-based, Dutch language)
- Extend the Dashboard with post-meeting KPI widgets (notulen ter goedkeuring, gepubliceerde besluiten, open actiepunten)
- Load seed data for Minutes, Decision, and ActionItem entities

**Non-Goals:**
- Full ORI / PLOOI webhook integration (deferred to p3-ori-publication)
- Governance body domain configuration (p3-governance-bodies)
- AI-powered meeting transcription and summarisation (demand: 1 — deferred)
- External archiving system integration (p3)
- Motion or voting workflows (completed in p2-motion-and-voting)
- Full cryptographic PKI signing (deferred to future sprint via Nextcloud Sign integration)

## Decisions

### 1. Minutes lifecycle uses OpenRegister built-in `status` field
**Decision**: Map the Minutes `lifecycle` property to OpenRegister's built-in `status` field. Lifecycle transitions (draft → review → approved → signed → published) are managed via `WorkflowEngineController`.
**Rationale**: ADR-001 requires using platform capabilities. The built-in `status` field has workflow engine support — no custom state machine code needed, and the transition log is automatic.
**Alternative considered**: Custom `lifecycle` field managed in a PHP service — rejected because it duplicates the platform's workflow engine and adds maintenance burden.

### 2. Digital signing stores signer display names in `signedBy` array
**Decision**: `signedBy` on the Minutes object stores the `displayName` values of the chair and secretary who approved. The `approvedAt` timestamp is recorded when transitioning to `approved`. The OpenRegister audit trail provides full non-repudiation.
**Rationale**: Full cryptographic PKI signing is out of scope for v1. Display name + audit trail satisfies legal governance traceability requirements for Dutch governance bodies. Full digital signatures can be layered on in a future sprint via Nextcloud Sign integration.
**Alternative considered**: Nextcloud Sign integration — deferred; the overhead of PKI infrastructure is disproportionate to the initial release scope.

### 3. Decision publication sets `isPublished` flag via explicit user action
**Decision**: A "Publiceren" button on an adopted Decision's detail page calls `ObjectService::saveObject()` to set `isPublished: true` and `publishedAt: <now>`. The actual ORI API push is deferred to p3.
**Rationale**: Explicit publish action maintains human oversight over what is sent to ORI. The flag in the Decision object is auditable and provides a clean handoff point for p3. Automatic publish-on-adopt would risk transmitting decisions before sign-off.
**Alternative considered**: Automatic publication on `outcome: adopted` — rejected to preserve human oversight and avoid premature ORI disclosure.

### 4. Template-based minutes generation (AI transcription deferred)
**Decision**: A "Concept genereren" button on the Minutes detail page calls `MinutesGenerationService::generateDraft()`, which fetches the linked Meeting's AgendaItems, Motions, VotingRounds, and Decisions and renders them into the `content` field using a structured Dutch prose template. A preview modal is shown before the field is overwritten.
**Rationale**: Meets the high-demand "Automated minutes generation" feature (demand: 186). Template-based generation is deterministic, privacy-compliant, and requires no LLM infrastructure. The preview modal prevents data loss on an already-edited content field.
**Alternative considered**: LLM / `ChatService`-based generation — deferred; template-based is sufficient for v1 and far safer for a legal audit record. AI transcription (demand: 1) remains on the backlog.

### 5. Overdue action items detected via daily background job
**Decision**: An `IJob` subclass (`OverdueActionItemsJob`) runs daily. It queries ActionItems where `taskStatus` is `open` or `in-progress` and `dueDate < now()`, then calls `ObjectService::saveObject()` to set `taskStatus: overdue`. The frontend also computes and displays overdue state client-side for immediate feedback.
**Rationale**: OpenRegister has no built-in scheduled status transition. A cron-style background job (ADR-003 pattern) is correct for persisted-status updates needed for filtering and reporting. Client-side detection is a best-effort UX enhancement.
**Alternative considered**: Frontend-only overdue detection — rejected because it would not update the persisted `taskStatus` field required for server-side filtering, export, and dashboards.

## Reuse Analysis (ADR-012)

| Capability | OpenRegister / Platform service used |
|------------|--------------------------------------|
| Minutes / Decision / ActionItem CRUD | `ObjectService` + `CnIndexPage` + `CnDetailPage` |
| Lifecycle transitions | `WorkflowEngineController` + `CnTimelineStages` |
| Version / revision history | `AuditTrailService` (built-in) + `CnObjectSidebar` → `CnAuditTrailTab` |
| Decision full-text search | `IndexService` + `CnFilterBar` + `CnFacetSidebar` |
| File attachments (signed minutes PDFs) | `FileService` + `CnObjectSidebar` → `CnFilesTab` |
| Action item overdue detection | Custom `IJob` — no platform equivalent for scheduled status transitions |
| Minutes content generation | Custom `MinutesGenerationService` — domain-specific Dutch prose; no platform equivalent |
| ORI publication flag | `ObjectService::saveObject()` — sets flag; actual webhook deferred to p3 |
| Dashboard KPI cards | `CnStatsBlock` / `CnStatsPanel` (built-in; no custom layout code) |
| Object stores (Pinia) | `createObjectStore(name)` + files, auditTrails, relations plugins |

No overlap with existing OpenRegister services. Minutes generation and overdue detection are domain-specific business logic with no platform equivalent.

## Seed Data

All seed objects use the `@self` envelope and are imported via `ConfigurationService::importFromApp()` in the repair step. Import is idempotent — re-importing skips existing objects matched by slug. Data represents realistic Dutch municipal, waterboard, and directie governance scenarios.

### Minutes (4 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Minutes", "slug": "notulen-raad-2025-03-20" },
    "title": "Notulen Gemeenteraadsvergadering 20 maart 2025",
    "lifecycle": "published",
    "content": "De vergadering wordt geopend door de voorzitter om 19:30 uur. Aanwezig: 33 van 37 raadsleden. De agenda wordt ongewijzigd vastgesteld. Punt 1 — opening en mededelingen: de voorzitter meldt dat de gemeente een subsidie heeft ontvangen voor verduurzaming. Punt 5 — Woningbouwplan Oost 2025-2030: na bespreking wordt het plan aangenomen met 30 stemmen voor en 3 tegen. De vergadering wordt gesloten om 22:45 uur.",
    "approvedAt": "2025-04-10T19:45:00Z",
    "signedBy": ["Roos de Vries", "Jan Bakker"],
    "version": 2
  },
  {
    "@self": { "register": "decidesk", "schema": "Minutes", "slug": "notulen-commissie-wonen-2025-04-01" },
    "title": "Notulen Commissievergadering Wonen & Ruimte 1 april 2025",
    "lifecycle": "signed",
    "content": "Aanvang: 19:00 uur. Voorzitter: Henk Bakker. Commissieleden aanwezig: 7 van 9. Agendapunt 2 — bestemmingsplan Groningen-Noord: de commissie adviseert de raad het plan ongewijzigd vast te stellen. Agendapunt 3 — huurbeleid sociale woningbouw: de commissie wenst aanvullende informatie van het college voor de volgende vergadering.",
    "approvedAt": "2025-04-15T10:00:00Z",
    "signedBy": ["Henk Bakker"],
    "version": 1
  },
  {
    "@self": { "register": "decidesk", "schema": "Minutes", "slug": "notulen-ab-waterschap-2025-04-10" },
    "title": "Notulen Vergadering Algemeen Bestuur Waterschap Aa en Maas 10 april 2025",
    "lifecycle": "review",
    "content": "Concept-verslag ter vaststelling. De vergadering van het Algemeen Bestuur wordt geopend door de dijkgraaf om 14:00 uur. Aanwezig: 21 van 23 leden. Agendapunt 3 — Waterbeheerprogramma 2026: bespreking over de financiering van dijkversterking. Het bestuur besluit het programma te agenderen voor de volgende vergadering.",
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

- **[Risk] Minutes generation produces incomplete or inaccurate drafts** → Mitigation: generated content is explicitly a `draft`; the clerk must review and edit before transitioning to `review`. A preview modal is shown before overwriting the `content` field. The feature is labelled "Concept genereren" to set expectations.
- **[Risk] ORI publication flag is set but the actual ORI API webhook is not yet implemented (p3)** → Mitigation: `isPublished: true` with `publishedAt` timestamp provides an auditable record. Stakeholders are informed that publication is flagged, not yet transmitted to PLOOI. The p3 sprint reads this flag to drive the webhook.
- **[Risk] Background job for overdue detection may fail silently** → Mitigation: the frontend also calculates and displays overdue state client-side (`dueDate < today`); the background job is a best-effort status sync. Job errors are logged to the Nextcloud log via the standard logger.
- **[Trade-off] `signedBy` stores display names, not cryptographic signatures** → Acceptable for v1; the OpenRegister audit trail records who made the `approved` transition, providing non-repudiation. Full PKI signing deferred to a future sprint via Nextcloud Sign integration.

## Migration Plan

1. No schema changes — Minutes, Decision, and ActionItem are already registered in `decidesk_register.json` from p1-schemas-and-data-model
2. Add seed data for all 3 entities to `lib/Settings/decidesk_register.json` under `x-openregister.seedData`; the existing repair step calls `ConfigurationService::importFromApp()` which is idempotent (upserts by slug)
3. Add new Vue Router routes (`/minutes`, `/minutes/:id`, `/decisions`, `/decisions/:id`, `/action-items`, `/action-items/:id`) and Pinia stores for Minutes, Decision, and ActionItem
4. Extend `SettingsService::getSettings()` to include register/schema slugs for the 3 new stores
5. Add `MinutesGenerationService` PHP service and `MinutesController` with the `POST /api/minutes/{minutesId}/generate-draft` route
6. Add `OverdueActionItemsJob` PHP background job class and register it in `appinfo/info.xml`
7. Extend `src/views/Dashboard.vue` with 3 new `CnStatsBlock` KPI cards

**Rollback strategy:** All changes are additive (new routes, stores, views, backend classes). Reverting the branch removes all new code. Seed data imported to OpenRegister can be removed via the OpenRegister admin UI. No database schema migrations are required.

## Open Questions

- Should the "Concept genereren" preview modal show a diff against the current `content` field, or just the newly generated text? (Recommendation: show full preview; diff view is a later enhancement)
- Should ORI publication be available as an opt-in automatic trigger on `outcome: adopted`, configurable per governance body? (Recommendation: explicit user action for v1; configurable automatic publish is a p3 governance body setting)
