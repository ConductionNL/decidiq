## Context

Decidesk is a thin-client Nextcloud app: all domain data is stored in OpenRegister. The three post-meeting entities — Minutes, Decision, and ActionItem — were delivered as OpenRegister schemas in p1-schemas-and-data-model and have full CRUD, lifecycle, search, and audit capabilities from p2-minutes-and-decisions. The DigitalDocument entity is also registered in ADR-000 and available for file attachment and document typing.

This T1 change adds the highest-demand extensions identified by Specter market research: document marking and generation (permit decisions, Woo disclosures, contracts), statutory deadline tracking, urgent decision fast-track, auto-generation of a decision list from voting results, and a complete audit trail surface for all decision lifecycle events.

The Board Secretary / Company Secretary (primary persona) needs formal document generation workflows, deadline tracking, and complete audit trails to satisfy compliance obligations under the Awb (statutory deadlines), Woo (disclosure documents), and the Dutch Corporate Governance Code. The Legal Counsel / Compliance Officer needs to verify every decision is properly documented and that statutory deadlines are met. The CEO / Managing Director and Supervisory Board Chair need the urgent decision fast-track for time-critical governance actions.

## Goals / Non-Goals

**Goals:**
- Case decision document marking (DigitalDocument `documentType` + Decision relation)
- Permit decision PDF generation from Decision objects
- Woo disclosure document PDF generation
- Contract document PDF generation from award decisions
- Statutory deadline calculation and insertion into decision acknowledgements
- Statutory deadline ActionItem creation with `dueDate`
- Urgent/spoed decision fast-track via `tags` array + priority notifications
- Auto-generation of decision list from VotingRound results into Minutes content
- Complete audit trail surface for all Decision, Minutes, and ActionItem lifecycle events

**Non-Goals:**
- Full ORI / PLOOI webhook push (p3-ori-publication)
- Digital signing with PKI / Nextcloud Sign (future sprint)
- AI-powered meeting transcription or document drafting (future AI spec)
- External legal deadline database integration (future integration spec)
- Multiple-criteria decision analysis (MCDA) tooling (future analytics spec)
- Integration with external financial systems for budget impact (future integration spec)
- Ranked-choice or weighted voting UI (deferred in p2-motion-and-voting)

## Decisions

### 1. PDF generation via `DecisionDocumentService` using PHP templates
**Decision**: A new `DecisionDocumentService` renders PDF documents from Decision data using PHP string templates. The resulting PDF binary is stored as a Nextcloud file via `FileService.upload()`, and a DigitalDocument object is created referencing it. The DigitalDocument is linked to the Decision via an OpenRegister relation.
**Rationale**: ADR-001 defines "PDF/document generation with business-specific templates" as custom code apps SHOULD build. No platform service covers domain-specific Dutch legal document templates (vergunningsbesluit, Woo-openbaarmakingsbesluit, contract). Keeping templates in PHP ensures deterministic, auditable output without LLM infrastructure.
**Alternative considered**: Integration with Docudesk / external rendering — deferred; PHP templates are sufficient for v1 and avoid external dependencies.

### 2. Statutory deadline stored as ActionItem linked to Decision
**Decision**: When a decision acknowledgement is generated, `DecisionDocumentService::generateAcknowledgement()` calls `StatutoryDeadlineService::calculate(legalBasis)` to map the legal article to a deadline duration (e.g., Awb art. 4:13 → 8 weeks). The deadline date is inserted into the acknowledgement text and a new ActionItem is created with `title: "Wettelijke beslistermijn"`, `assignee` set to the secretary's display name, `dueDate` set to the calculated deadline, and `taskStatus: open`. The ActionItem is linked to the Decision via OpenRegister relation.
**Rationale**: ActionItem already has `dueDate` and overdue detection from p2-minutes-and-decisions. Reusing it avoids a new entity (ADR-012) and gives the deadline the same tracking and notification workflow. The existing `OverdueActionItemsJob` will automatically flag deadline ActionItems as `overdue` when past due.
**Alternative considered**: A new `Deadline` entity — rejected (ADR-000 is the source of truth; ActionItem covers the use case; no new entities allowed without an ADR-000 update).

### 3. Urgent decision flag via built-in `tags` array
**Decision**: Urgent/spoed decisions are flagged by adding the tag value `spoed` to the Decision object's built-in OpenRegister `tags` array via `DecisionService::flagUrgent()`. Priority notifications are sent to chair, secretary, and legal counsel display-name addresses via `NotificationService`. The frontend checks for the `spoed` tag to render an urgent indicator.
**Rationale**: No schema change needed — `tags` is built-in to every OpenRegister object. The `spoed` tag is unambiguous in Dutch governance context. Tag-based filtering is supported by `IndexService` + `CnFilterBar` out of the box.
**Alternative considered**: A new `isUrgent: boolean` field on Decision — rejected (would require an ADR-000 schema update and migration; tags are sufficient and avoid a breaking schema change).

### 4. Document type marking via DigitalDocument `documentType` field
**Decision**: The existing `documentType` field on DigitalDocument (ADR-000: `documentType: string`) is set to `case-decision`, `permit-decision`, `woo-disclosure`, or `contract` when a document is created or marked. An OpenRegister relation from DigitalDocument → Decision provides the bidirectional link. The Decision detail page queries DigitalDocuments with a relation to the Decision and renders them in the "Besluitdocumenten" panel.
**Rationale**: ADR-000 already defines `documentType: string` on DigitalDocument. No schema change needed. The OpenRegister relation mechanism handles the link. The `documentType` vocabulary is consistent with schema.org `schema:DigitalDocument` conventions.
**Alternative considered**: A new `CaseDecisionDocument` entity — rejected (ADR-000 is authoritative; DigitalDocument with typed `documentType` and a relation covers the use case; a new entity would require an ADR-000 update).

### 5. Decision list generation extends `MinutesGenerationService`
**Decision**: A new `generateDecisionList(string $minutesId): string` method is added to the existing `MinutesGenerationService` from p2-minutes-and-decisions. It reads all VotingRounds linked to the Meeting (via OpenRegister relations), fetches their associated Decisions and vote totals, and renders a formatted Dutch decision list. The output is inserted into the Minutes `content` field via a new "Besluitenlijst genereren" action, distinct from the "Concept genereren" action already on the Minutes detail page.
**Rationale**: Extending the existing service avoids creating a separate service class (ADR-012 deduplication). The decision list generation shares the same "preview before apply" pattern established in p2-minutes-and-decisions. Keeping both generation methods in one service enables future composition (e.g., a full minutes draft that includes the decision list).
**Alternative considered**: A dedicated `DecisionListService` — rejected (single responsibility is sufficient within `MinutesGenerationService`; the method is a natural extension of the generation capability already there).

## Reuse Analysis (ADR-012)

| Capability | OpenRegister service / component used | Custom code |
|---|---|---|
| Document marking (documentType) | `ObjectService.saveObject()` + OpenRegister relation | None (form field + relation) |
| PDF generation | `FileService` (upload result) | `DecisionDocumentService` (template rendering) |
| DigitalDocument list on Decision | `ObjectService.findAll()` (relations query) | `DecisionDocumentPanel.vue` (display component) |
| Statutory deadline calculation | `ObjectService.saveObject()` (ActionItem creation) | `StatutoryDeadlineService::calculate()` |
| Statutory deadline ActionItem | `ObjectService.saveObject()`, `OverdueActionItemsJob` (existing) | Called from `DecisionDocumentService` |
| Urgent flag | `tags` built-in + `NotificationService` | `DecisionService::flagUrgent()` |
| Priority notifications | `NotificationService` | Called from `DecisionService::flagUrgent()` |
| Decision list generation | `ObjectService.findAll()` (VotingRound + Decision query) | `MinutesGenerationService::generateDecisionList()` (new method) |
| Audit trail display | `AuditTrailService` (built-in) + `CnObjectSidebar` → `CnAuditTrailTab` | None (automatic) |
| Document search / filter | `IndexService` + `CnFilterBar` + `CnFacetSidebar` | None |
| File upload (PDFs) | `FileService` (built-in) | None (called from `DecisionDocumentService`) |
| Export | `ExportService` + `CnMassExportDialog` | None |

No new entities are proposed. No overlap with OpenRegister core services beyond what is listed above. `DecisionDocumentService` and `StatutoryDeadlineService` are the only net-new PHP classes.

## Seed Data

### DigitalDocument (5 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "DigitalDocument", "slug": "doc-besluit-woningbouwplan-oost-casedoc" },
    "name": "Besluit Woningbouwplan Oost 2025-2030 — Officieel besluitdocument",
    "documentType": "case-decision",
    "description": "Formeel besluitdocument voor de vaststelling van het Woningbouwplan Oost 2025-2030, vastgesteld in de gemeenteraadsvergadering van 20 maart 2025. Bevat besluitomschrijving, grondslagen en rechtsmiddelenclausule.",
    "encodingFormat": "application/pdf",
    "contentSize": "245 KB"
  },
  {
    "@self": { "register": "decidesk", "schema": "DigitalDocument", "slug": "doc-vergunningsbesluit-dorpsstraat-15" },
    "name": "Omgevingsvergunning Dorpsstraat 15 — Vergunningsbesluit",
    "documentType": "permit-decision",
    "description": "Besluit op de aanvraag omgevingsvergunning voor verbouwing van het pand Dorpsstraat 15 te Groningen. Bevat toetsing aan het bestemmingsplan, motivering en de wettelijke beslistermijn conform Awb art. 3.9 Wabo.",
    "encodingFormat": "application/pdf",
    "contentSize": "178 KB"
  },
  {
    "@self": { "register": "decidesk", "schema": "DigitalDocument", "slug": "doc-woo-openbaarmakingsbesluit-q1-2025" },
    "name": "Woo-openbaarmakingsbesluit Raadsnotulen Q1 2025",
    "documentType": "woo-disclosure",
    "description": "Formeel Woo-openbaarmakingsbesluit voor de verstrekking van raadsnotulen over het eerste kwartaal 2025 op verzoek van de heer J. de Vries. Besluit tot integrale openbaarmaking zonder uitzonderingen.",
    "encodingFormat": "application/pdf",
    "contentSize": "89 KB"
  },
  {
    "@self": { "register": "decidesk", "schema": "DigitalDocument", "slug": "doc-contract-aanbesteding-ict-infrastructuur" },
    "name": "Contract ICT-infrastructuur Gemeente Westerkwartier 2025",
    "documentType": "contract",
    "description": "Overeenkomst ICT-infrastructuurbeheer 2025-2028 gegenereerd vanuit het gunningsbesluit d.d. 10 april 2025. Partijen: Gemeente Westerkwartier en TechNed BV.",
    "encodingFormat": "application/pdf",
    "contentSize": "412 KB"
  },
  {
    "@self": { "register": "decidesk", "schema": "DigitalDocument", "slug": "doc-besluit-fusie-dorpsraden-casedoc" },
    "name": "Instemming fusie dorpsraden Noord en Oost — Besluitdocument",
    "documentType": "case-decision",
    "description": "Officieel besluitdocument voor de instemming met de fusie van dorpsraad Noord en dorpsraad Oost per 1 januari 2026. Vastgesteld in de gemeenteraadsvergadering van 10 april 2025.",
    "encodingFormat": "application/pdf",
    "contentSize": "134 KB"
  }
]
```

### ActionItem — statutory deadline examples (3 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-beslistermijn-vergunning-dorpsstraat-15" },
    "title": "Wettelijke beslistermijn omgevingsvergunning Dorpsstraat 15",
    "description": "Awb art. 3.9 Wabo: beslistermijn 8 weken na ontvangst volledige aanvraag d.d. 14 februari 2025. Uiterste beslisdatum: 11 april 2025.",
    "assignee": "Vergunningverlener",
    "dueDate": "2025-04-11T00:00:00Z",
    "taskStatus": "completed",
    "completedAt": "2025-04-10T14:00:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-beslistermijn-woo-verzoek-de-vries" },
    "title": "Wettelijke beslistermijn Woo-verzoek J. de Vries",
    "description": "Woo art. 4.1 lid 1: beslistermijn 4 weken na ontvangst verzoek d.d. 17 maart 2025. Uiterste beslisdatum: 14 april 2025.",
    "assignee": "Juridisch medewerker",
    "dueDate": "2025-04-14T00:00:00Z",
    "taskStatus": "overdue"
  },
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-beslistermijn-bezwaar-parkeervergunning" },
    "title": "Wettelijke beslistermijn bezwaar parkeervergunning Hoofdstraat 8",
    "description": "Awb art. 7:10 lid 1: beslistermijn op bezwaar 6 weken na ontvangst bezwaarschrift d.d. 1 april 2025. Uiterste beslisdatum: 13 mei 2025.",
    "assignee": "Bezwaarcommissie secretaris",
    "dueDate": "2025-05-13T00:00:00Z",
    "taskStatus": "open"
  }
]
```

## Risks / Trade-offs

- **[Risk] PDF template output may not meet formal legal formatting requirements** → Mitigation: generated PDFs are explicitly labelled "concept" in the filename and footer until a legal administrator reviews and marks them final; the PDF carries no official stamp and is accompanied by a review notice in the Nextcloud notification
- **[Risk] Statutory deadline calculation depends on `legalBasis` text matching known article identifiers** → Mitigation: `StatutoryDeadlineService` uses a configurable article-to-duration mapping maintained in app settings via `IAppConfig`; unknown articles return `null` deadline (no ActionItem created) and a warning notification to the secretary; the mapping is documented and extensible
- **[Risk] Urgent `spoed` tag could be applied incorrectly, triggering unnecessary priority notifications** → Mitigation: the "Spoedbesluit" action is restricted to chair and secretary roles (enforced in `DecisionService::flagUrgent()` via `AuthorizationService`); the action requires a confirmation dialog; the tag is removable by the same roles with a reason note added to the audit trail
- **[Risk] Decision list generation may produce incorrect output if VotingRound–Decision relations are incomplete** → Mitigation: `MinutesGenerationService::generateDecisionList()` shows a warning in the preview if Decisions without linked VotingRounds are found; the preview modal always appears before content is applied to the Minutes object
- **[Trade-off] `signedBy` stores display names, not cryptographic signatures** → Acceptable for v1 (inherited from p2-minutes-and-decisions); OpenRegister audit trail provides non-repudiation; full PKI signing deferred to Nextcloud Sign integration in a future sprint

## Migration Plan

1. No schema changes — Decision, Minutes, ActionItem, and DigitalDocument are already registered in `decidesk_register.json` from p1-schemas-and-data-model; the `spoed` tag and `documentType` field are already available
2. Add `DecisionDocumentService.php` with methods for permit decision PDF, Woo disclosure PDF, contract PDF, and acknowledgement generation with statutory deadline insertion
3. Add `StatutoryDeadlineService.php` with `calculate(string $legalBasis): ?DateTimeInterface` method and configurable article-to-duration mapping
4. Add `DecisionService.php` with `flagUrgent(string $decisionId, string $actorId): void` method and `unflagUrgent()` counterpart
5. Extend `MinutesGenerationService.php` from p2-minutes-and-decisions with `generateDecisionList(string $minutesId): string` method
6. Add `DecisionController.php` (thin, < 10 lines/method) and `MinutesController.php` route extensions for new endpoints
7. Register all new routes in `appinfo/routes.php`; specific routes before wildcard `{slug}` routes
8. Frontend: add `DecisionDocumentPanel.vue` component; extend `DecisionDetail.vue` with document panel, statutory deadline countdown, and urgent indicator; extend `MinutesDetail.vue` with "Besluitenlijst genereren" action
9. Add seed data objects via the existing repair step (idempotent upsert by slug)

## Open Questions

- Should the statutory deadline calculation mapping be configurable per governance body domain (municipal vs. water board vs. corporate), or shared across all domains? (Recommendation: shared mapping with domain-specific overrides configurable in app settings; municipal Awb deadlines are the default)
- Should the Woo disclosure document template include a section for partial disclosure decisions (where some parts are withheld under Woo art. 5.1)? (Recommendation: include a "Gedeeltelijke openbaarmaking" section as optional; the generating user fills in the withheld parts manually after generation)
- Should the "Spoedbesluit" flag be removable after it has been set, or permanent once applied? (Recommendation: removable by chair or secretary only, with mandatory reason note added to audit trail; permanent flags risk governance overreach)
- Should the decision list generated by `generateDecisionList()` overwrite the existing `content` field of the Minutes, or be appended? (Recommendation: always show preview and let the user choose to replace or append; default to append if content already exists)
