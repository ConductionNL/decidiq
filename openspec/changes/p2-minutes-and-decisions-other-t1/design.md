## Context

Decidesk is a thin-client Nextcloud app: all domain data is stored in OpenRegister. The Decision, Motion, ActionItem, and Minutes entities were delivered as OpenRegister schemas in p1-schemas-and-data-model and have full CRUD, lifecycle, search, and audit capabilities from p2-minutes-and-decisions. The p2-minutes-and-decisions-core-t1 change added document generation (permit decisions, Woo disclosures), statutory deadline tracking, urgent decision flagging, and `DecisionService` + `MinutesGenerationService` as the primary custom service layer.

This "Other T1" change addresses the highest-demand governance features in the "other" category: digital approval workflows (demand 293, 97 tender mentions), decision analytics (demand 145), strategic decision review (demand 60), decision outcome tracking (demand 57), steering committee management (demand 45), enhanced action item follow-up (demand 44), auto-record creation from adopted Motions (demand 39), and weekly email digest notifications (demand 33).

The Board Secretary / Company Secretary (primary persona) needs a structured, auditable decision lifecycle from initial draft through multi-stage approval to publication. The CEO / Managing Director and Supervisory Board Chair need analytics visibility and the approval workflow to manage major decisions. The Legal Counsel / Compliance Officer needs outcome tracking and auto-created Decision records from formal motions to ensure every adopted motion has a corresponding traceable decision record.

## Goals / Non-Goals

**Goals:**
- Multi-stage digital approval workflow (`draft → legal-review → committee-review → board-approved / board-rejected`)
- Decision analytics dashboard with KPI cards and trend/distribution charts
- Strategic decision review: assign reviewers, capture sign-offs with notes
- Decision outcome tracking via implementation status tags
- Auto-creation of Decision records when a Motion is adopted (idempotent)
- Enhanced action item follow-up: overdue escalation notifications + "Mijn actiepunten" dashboard widget
- Weekly email digest of upcoming deadlines, overdue action items, and pending approvals

**Non-Goals:**
- AI-powered decision recommendations or scoring (future AI spec)
- Integration with external ERP or financial systems for budget-linked decisions (future integration spec)
- Qualified electronic signatures on approval sign-offs (future PKI / Nextcloud Sign spec)
- DMN (Decision Model and Notation) process engine (future BPMN/DMN spec)
- Ranked-choice or multi-criteria approval scoring (future analytics spec)
- Real-time push analytics (websocket streaming); analytics are polled on page load
- Harvesting to national ORI aggregator (p3-ori-publication)

## Decisions

### 1. Approval workflow uses existing Decision `lifecycle` field with extended states

**Decision**: The multi-stage approval workflow extends the Decision `lifecycle` state machine to include: `draft → legal-review → committee-review → board-approved → board-rejected → published`. These values extend the existing `lifecycle: string` field from ADR-000 — a non-breaking additive change. `DecisionApprovalService` validates each transition, checks that the current user holds the required role for that step (legal counsel for `legal-review`, committee chair for `committee-review`, board chair or secretary for `board-approved`/`board-rejected`), sends `NotificationService` notifications to the next reviewer group, and records every transition in `AuditTrailService`. The approval state is rendered as `CnTimelineStages` in the Decision detail header.

**Rationale**: `lifecycle: string` already exists on Decision (ADR-000); adding allowed values is non-breaking. `DecisionApprovalService` is exactly the custom business logic apps SHOULD build per ADR-012. No new entity is needed. Consistent with the `spoed` tag approach in core-t1: reuse existing fields before proposing schema changes.

**Alternative considered**: A separate `ApprovalRequest` entity per review step — rejected (would require an ADR-000 update; lifecycle extension covers the use case; adding a new entity for a simple state machine violates ADR-012 deduplication).

### 2. Strategic reviewer sign-offs stored as Decision relations + notes

**Decision**: Reviewer assignments are stored as OpenRegister relations from the Decision to Person objects with relation label `reviewer`. When a reviewer submits their sign-off, `DecisionApprovalService::submitReview(decisionId, personId, value, note)` creates a structured note on the Decision object (format: `[REVIEW] {personDisplayName}: {approved|rejected} — {note} — {timestamp}`) and updates the relation metadata. `DecisionApprovalService::allReviewsComplete(decisionId)` checks that all assigned reviewers have a corresponding note entry before allowing the lifecycle to advance. The reviewer panel (`DecisionReviewerPanel.vue`) reads Decision relations and notes to render the sign-off list.

**Rationale**: OpenRegister `relations` and `notes` are built-in to every object — no schema change needed. Storing sign-offs as structured notes provides a human-readable audit trail (visible in `CnObjectSidebar → CnNotesCard`) without a custom audit entity. The relation from Decision → Person reuses the standard OpenRegister relational query engine.

**Alternative considered**: A new `ReviewSignOff` entity — rejected (ADR-000 is the source of truth; no new entities without an ADR-000 update; notes + relations cover the use case; ADR-012 requires justifying new entities).

### 3. Analytics aggregate endpoint uses ObjectService.findAll() grouping

**Decision**: `DecisionAnalyticsController` provides `GET /api/decisions/analytics?governanceBodyId={id}` that runs four aggregate queries via `ObjectService.findAll()`:
- Decisions grouped by month (`decisionDate`) for the last 12 months
- Decisions grouped by `outcome` (adopted, rejected)
- Count of Decisions in `legal-review` or `committee-review` (pending approvals)
- Count of ActionItems with `taskStatus: overdue` linked to any Decision

The frontend `DecisionAnalyticsDashboard.vue` uses `CnDashboardPage` + `CnKpiGrid` (four `CnStatsBlock` cards) + two `CnChartWidget` instances (bar chart for monthly trend, donut for outcome distribution). The `governanceBodyId` param allows per-body filtering; omitting it returns aggregate across all accessible bodies.

**Rationale**: ADR-012 mandates use of `CnDashboardPage`, `CnChartWidget`, `CnStatsBlock` — no custom chart components. `ObjectService.findAll()` with filter params provides the needed data without a custom query layer. A dedicated controller endpoint avoids over-fetching via the standard list endpoint and enables server-side aggregation.

**Alternative considered**: Client-side aggregation by fetching all Decisions — rejected (unbounded fetch for large archives; server-side aggregation is more efficient and scales; dedicated endpoint follows the pattern established in other Conduction analytics changes).

### 4. Auto-record creation via MotionService lifecycle hook (idempotent)

**Decision**: `DecisionAutoRecordService::createFromAdoptedMotion(string $motionId): ?string` is called from `MotionService` when `transitionLifecycle()` moves a Motion to `adopted`. The service:
1. Checks if a Decision linked to this Motion already exists via `ObjectService.findAll()` with `relations` filter — if so, returns the existing Decision UUID (idempotent)
2. Creates a Decision with: `title` from Motion.`title`, `text` from Motion.`decisionText` (or Motion.`text` if `decisionText` is empty), `decisionDate` set to today, `outcome: adopted`, `legalBasis` from Motion.`legalBasis`
3. Creates an OpenRegister relation from Decision → Motion with label `source-motion`
4. Returns the new Decision UUID

The returned UUID is logged by `MotionService` to `ActivityService`. The created Decision enters the standard `draft` lifecycle state (not immediately published).

**Rationale**: Eliminates manual record creation (demand 39, 13 tender mentions). The `MotionService.transitionLifecycle()` lifecycle hook is the correct trigger point — it already owns the state transition logic. Idempotency via relation check prevents duplicates on retry. Decisions start as `draft` so secretaries can review before publishing — this matches the approval workflow.

**Alternative considered**: Webhook-based trigger — rejected (requires external callback infrastructure; lifecycle hook in `MotionService` is contained within the app; simpler and more reliable).

### 5. Weekly digest uses TimedJob + IMailer (one email per governance body)

**Decision**: `DecisionDigestJob` extends `TimedJob` and is configured with interval 604800 seconds (7 days). On each run it:
1. Fetches all GovernanceBodies the current Nextcloud system has
2. For each body, queries: (a) ActionItems linked to Decisions of that body with `dueDate` within the next 14 days and `taskStatus != completed`; (b) ActionItems with `taskStatus: overdue`; (c) Decisions in `legal-review` or `committee-review` state
3. Skips the body if the digest is disabled in `IAppConfig` key `digest_enabled_{bodyId}` (default: true)
4. Assembles a plain-text + HTML email and sends via `IMailer` to the email addresses of Person records with `role: chair` or `role: secretary` in the body's Membership records
5. Logs send result to the Nextcloud logger

**Rationale**: `TimedJob` + `IMailer` are standard Nextcloud APIs; no third-party mail library needed. Per-body emails prevent spam in large multi-body deployments. Plain-text + HTML dual format ensures deliverability across email clients. Configurable opt-out respects user preferences.

**Alternative considered**: Nextcloud push notifications only — rejected (stakeholders explicitly requested email digest; push notifications are supplementary; email is more accessible for non-daily Nextcloud users).

### 6. Outcome tracking uses built-in `tags` array (consistent with core-t1)

**Decision**: Implementation outcome is tracked by adding one of three mutually exclusive tags to the Decision `tags` array: `geimplementeerd`, `implementatie-lopend`, `implementatie-uitgesteld`. `DecisionService::setOutcomeTag(string $decisionId, string $tag, string $actorId)` removes any existing outcome tag before adding the new one (ensuring mutual exclusivity), saves via `ObjectService.saveObject()`, and logs to `ActivityService`. The Decision index page includes an "Implementatiestatus" facet via `CnFacetSidebar` for tag-based filtering via `IndexService`.

**Rationale**: Consistent with the `spoed` tag approach from core-t1. `tags` is built-in to every OpenRegister object — no schema change. Tag-based filtering is natively supported by `IndexService` + `CnFacetSidebar`. Mutual-exclusivity logic in `DecisionService` keeps the outcome state clean without a new field.

**Alternative considered**: A new `implementationStatus: string` field on Decision — rejected (would require an ADR-000 schema update and repair step migration; built-in tags cover the use case; ADR-000 update requires separate architectural review).

## Reuse Analysis (ADR-012)

| Capability | OpenRegister service / component used | Custom code |
|---|---|---|
| Approval lifecycle transitions | `ObjectService.saveObject()` (Decision lifecycle update) | `DecisionApprovalService` (transition validation, role checks, notifications) |
| Approval role checks | `AuthorizationService` | Called from `DecisionApprovalService` |
| Reviewer notifications | `NotificationService` | Called from `DecisionApprovalService` |
| Reviewer sign-off storage | OpenRegister built-in `notes` + `relations` | `DecisionApprovalService::submitReview()` (note structure, relation query) |
| Approval timeline display | `CnTimelineStages` | `DecisionApprovalPanel.vue` (data wiring) |
| Analytics KPI data | `ObjectService.findAll()` (aggregate queries) | `DecisionAnalyticsController` (aggregation endpoint) |
| Analytics dashboard layout | `CnDashboardPage` + `CnKpiGrid` + `CnChartWidget` + `CnStatsBlock` | `DecisionAnalyticsDashboard.vue` (data wiring + layout) |
| Auto-record creation | `ObjectService.saveObject()` + OpenRegister relation | `DecisionAutoRecordService` (Motion hook, duplicate check) |
| Weekly digest email | `IMailer` (Nextcloud standard) | `DecisionDigestJob` (content assembly, recipient lookup) |
| Outcome tag management | `tags` built-in + `ObjectService.saveObject()` | `DecisionService::setOutcomeTag()` (new method on existing service) |
| Outcome facet filter | `IndexService` + `CnFacetSidebar` | None (config-only) |
| Overdue action item escalation | `OverdueActionItemsJob` (existing) + `NotificationService` | Extended notification target (secretary of linked GovernanceBody) |
| Steering committee view | `ObjectService.findAll()` + `CnFilterBar` preset | None (filter preset config) |
| Audit trail for all changes | `AuditTrailService` (built-in, automatic) | None |

No new entities are proposed. `DecisionApprovalService`, `DecisionAutoRecordService`, and `DecisionDigestJob` are the only net-new PHP classes. `DecisionAnalyticsController` is a new controller for the analytics endpoint. `DecisionService` is extended with new methods (not a new class).

## Seed Data

### Decision (5 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Decision", "slug": "besluit-woningbouwplan-oost-goedgekeurd" },
    "title": "Vaststelling Woningbouwplan Oost 2025-2030",
    "text": "De gemeenteraad besluit het Woningbouwplan Oost 2025-2030 vast te stellen, waarbij 850 woningen worden gerealiseerd in vier fasen. Uitvoering start Q3 2025.",
    "decisionDate": "2025-03-20T19:00:00Z",
    "outcome": "adopted",
    "isPublished": true,
    "publishedAt": "2025-03-21T09:00:00Z",
    "legalBasis": "Wet ruimtelijke ordening art. 3.1"
  },
  {
    "@self": { "register": "decidesk", "schema": "Decision", "slug": "besluit-ict-infrastructuur-goedkeuring" },
    "title": "Gunning aanbesteding ICT-infrastructuur 2025-2028",
    "text": "Het college besluit de opdracht ICT-infrastructuurbeheer 2025-2028 te gunnen aan TechNed BV voor € 2.400.000 exclusief BTW. Opdracht start 1 juli 2025.",
    "decisionDate": "2025-04-10T10:00:00Z",
    "outcome": "adopted",
    "isPublished": false,
    "legalBasis": "Aanbestedingswet 2012 art. 2.130"
  },
  {
    "@self": { "register": "decidesk", "schema": "Decision", "slug": "besluit-verduurzaming-gebouwen-lopend" },
    "title": "Programma Verduurzaming Gemeentelijke Gebouwen 2025-2030",
    "text": "De gemeenteraad stelt het programma Verduurzaming Gemeentelijke Gebouwen 2025-2030 vast met een budget van € 8.750.000. Doelstelling: energielabel A voor alle gemeentelijke panden voor eind 2030.",
    "decisionDate": "2025-04-03T19:00:00Z",
    "outcome": "adopted",
    "isPublished": false,
    "legalBasis": "Klimaatwet art. 3.1"
  },
  {
    "@self": { "register": "decidesk", "schema": "Decision", "slug": "besluit-sporthal-meerwijk-afgewezen" },
    "title": "Bouwen nieuwe sporthal wijk Meerwijk",
    "text": "Het voorstel voor de bouw van een nieuwe sporthal in wijk Meerwijk is verworpen. Bezwaren: onvoldoende financiering en overlap met bestaande sportaccommodaties op 800 meter afstand.",
    "decisionDate": "2025-03-06T19:00:00Z",
    "outcome": "rejected",
    "isPublished": false,
    "legalBasis": ""
  },
  {
    "@self": { "register": "decidesk", "schema": "Decision", "slug": "besluit-ozu-tarief-2025-vastgesteld" },
    "title": "Vaststelling OZU-tarief belastingjaar 2025",
    "text": "De gemeenteraad besluit het tarief onroerendezaakbelasting gebruikers (OZU) met 3,8% te verhogen conform inflatiecorrectie. Tarief 2025: 0,1354% van de WOZ-waarde.",
    "decisionDate": "2025-02-13T19:30:00Z",
    "outcome": "adopted",
    "isPublished": true,
    "publishedAt": "2025-02-14T08:00:00Z",
    "legalBasis": "Gemeentewet art. 220"
  }
]
```

### ActionItem — implementation follow-up examples (3 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-woningbouwplan-fase1-start" },
    "title": "Start uitvoering fase 1 Woningbouwplan Oost — aannemer selecteren",
    "description": "Conform besluit gemeenteraad 20 maart 2025: selectie aannemer fase 1 (200 woningen Oosterpark) afronden voor 1 augustus 2025. Aanbestedingsprocedure starten via TenderNed.",
    "assignee": "Projectleider Woningbouw",
    "dueDate": "2025-08-01T00:00:00Z",
    "taskStatus": "open"
  },
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-ict-contract-ondertekening" },
    "title": "Ondertekening contract ICT-infrastructuur TechNed BV",
    "description": "Conform gunningsbesluit 10 april 2025: contract ondertekenen voor 1 juni 2025. Juridische review door interne jurist afgerond; handtekeningen van gemeentesecretaris en TechNed-directeur vereist.",
    "assignee": "Gemeentesecretaris",
    "dueDate": "2025-06-01T00:00:00Z",
    "taskStatus": "open"
  },
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-verduurzaming-nulmeting" },
    "title": "Nulmeting energielabels gemeentelijke panden uitvoeren",
    "description": "Conform verduurzamingsbesluit 3 april 2025: nulmeting alle 47 gemeentelijke panden vóór 1 september 2025 door gecertificeerd energieadviseur. Budget: € 42.000 uit programmamiddelen.",
    "assignee": "Adviseur Duurzaamheid",
    "dueDate": "2025-09-01T00:00:00Z",
    "taskStatus": "open"
  }
]
```

### Motion — for auto-record creation demonstration (3 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motie-fietsinfra-binnenstad-ingediend" },
    "title": "Motie verbetering fietsinfrastructuur binnenstad",
    "text": "De gemeenteraad verzoekt het college om voor 1 oktober 2025 een uitvoeringsplan te presenteren voor de verbetering van de fietsinfrastructuur in de binnenstad, met prioriteit voor de Keizersgracht en Prinsengracht.",
    "motionType": "motion",
    "proposer": "Fractie GroenLinks",
    "coSigners": ["Fractie D66", "Fractie PvdA"],
    "lifecycle": "adopted",
    "submittedAt": "2025-04-10T18:45:00Z",
    "decisionText": "De gemeenteraad besluit het college te verzoeken een uitvoeringsplan fietsinfrastructuur binnenstad op te stellen.",
    "decisionDate": "2025-04-10T21:15:00Z",
    "isPublished": false,
    "legalBasis": "Gemeentewet art. 169"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motie-klimaatfonds-aanvraag" },
    "title": "Aanvraag Nationaal Klimaatfonds voor zonnepanelen scholen",
    "text": "De gemeenteraad stemt in met de aanvraag van een subsidie uit het Nationaal Klimaatfonds voor de plaatsing van zonnepanelen op 12 gemeentelijke schoolgebouwen.",
    "motionType": "motion",
    "proposer": "College van Burgemeester en Wethouders",
    "coSigners": [],
    "lifecycle": "adopted",
    "submittedAt": "2025-03-27T19:00:00Z",
    "decisionText": "De gemeenteraad besluit de subsidieaanvraag Nationaal Klimaatfonds zonnepanelen scholen goed te keuren.",
    "decisionDate": "2025-03-27T20:30:00Z",
    "isPublished": false,
    "legalBasis": "Klimaatfonds Besluit 2024 art. 4"
  },
  {
    "@self": { "register": "decidesk", "schema": "Motion", "slug": "motie-participatieraad-instellen" },
    "title": "Instelling Burgerparticipatieraad Leefomgeving",
    "text": "De gemeenteraad besluit een Burgerparticipatieraad Leefomgeving in te stellen als adviesorgaan, bestaande uit 15 gelote inwoners. De raad adviseert het college over grote ruimtelijke projecten.",
    "motionType": "motion",
    "proposer": "Fractie VVD",
    "coSigners": ["Fractie CDA"],
    "lifecycle": "voting",
    "submittedAt": "2025-04-15T19:00:00Z",
    "isPublished": false,
    "legalBasis": "Gemeentewet art. 84"
  }
]
```

## Risks / Trade-offs

- **[Risk] New approval lifecycle states may conflict with lifecycle values set directly on Decisions via the standard CRUD form** → Mitigation: `DecisionApprovalService::transitionLifecycle()` is the only permitted path for approval state transitions; the Decision edit form restricts lifecycle dropdown to `draft` and `published` only (non-approval states); approval states are only reachable via dedicated approval actions
- **[Risk] Auto-record creation could generate a Decision before the secretary has reviewed the motion text for accuracy** → Mitigation: auto-created Decisions enter `draft` lifecycle state, not `published`; the secretary must explicitly advance to `legal-review` or publish; a Nextcloud notification is sent to the secretary when a Decision is auto-created, with a "Review besluit" deep link
- **[Risk] Analytics `GET /api/decisions/analytics` may be slow for large archives (1000+ decisions)** → Mitigation: `ObjectService.findAll()` with narrow filter params limits the dataset; results are cached in `ICache` with a 15-minute TTL; the controller includes a `Cache-Control: max-age=900` header; cache invalidation on Decision save via a `DecisionSavedEvent` listener is deferred to a follow-up sprint
- **[Risk] Weekly digest job may fail silently if `IMailer` is not configured** → Mitigation: `DecisionDigestJob` wraps the `IMailer::send()` call in a try/catch; failures are logged at `ERROR` level with the governance body ID and exception; the job records `lastRunAt` in `IAppConfig` so missed runs can be detected via the health endpoint
- **[Trade-off] Reviewer sign-offs stored as structured notes (not a typed entity)** → Acceptable for v1; notes are human-readable and auditable; the structured `[REVIEW]` prefix prefix enables parsing if a typed entity is introduced in a future sprint; the format is documented in `DecisionApprovalService` PHPDoc

## Migration Plan

1. No schema changes to Decision, Motion, or ActionItem — all new functionality uses built-in `lifecycle`, `tags`, `relations`, and `notes` fields; no ADR-000 update required
2. Add new lifecycle states (`legal-review`, `committee-review`, `board-approved`, `board-rejected`) to Decision — existing Decisions in `draft` or `published` state are unaffected; no data migration needed
3. Create `lib/Service/DecisionApprovalService.php` with transition validation, reviewer notifications, and sign-off tracking
4. Create `lib/Service/DecisionAutoRecordService.php` with `createFromAdoptedMotion()` and duplicate-check logic
5. Create `lib/Job/DecisionDigestJob.php` as a `TimedJob`; register in `appinfo/info.xml` under `<jobs>` and in the DI container
6. Create `lib/Controller/DecisionAnalyticsController.php` with `GET /api/decisions/analytics`; register route in `appinfo/routes.php`
7. Extend `lib/Service/MotionService.php` to call `DecisionAutoRecordService::createFromAdoptedMotion()` in the `adopted` transition branch of `transitionLifecycle()`
8. Extend `lib/Service/DecisionService.php` from core-t1 with `setOutcomeTag()`, `assignReviewer()`, and `submitReview()` methods
9. Frontend: add `DecisionAnalyticsDashboard.vue` with route `/decisions/analytics`; add `DecisionApprovalPanel.vue` and `DecisionReviewerPanel.vue` to `DecisionDetail.vue`; extend `Decisions.vue` index with outcome tag facets and approval state filter
10. Add seed data for Decision (5 objects), ActionItem (3 objects), and Motion (3 objects) to `lib/Settings/decidesk_register.json`

## Open Questions

- Should the approval workflow require sequential sign-off (each reviewer in strict order) or parallel (all reviewers notified simultaneously)? (Recommendation: parallel for v1 — all nominated reviewers are notified simultaneously; the Decision advances when all required approvals are received; sequential ordering can be added in a later sprint if governance bodies request it)
- Should `DecisionAnalyticsController` expose the raw monthly data or only pre-aggregated counts? (Recommendation: pre-aggregated counts in the API response — the frontend renders charts from counts, not raw objects; raw data export is already covered by `CnMassExportDialog` on the Decision index)
- Should the weekly digest include deep links to each listed Decision/ActionItem in the Decidesk app? (Recommendation: yes — use `generateUrl('/apps/decidesk/')` + route path for deep links in the HTML email body; include a note that Nextcloud login is required; plain-text version omits links)
- Should `DecisionAutoRecordService` create the Decision as `draft` or `legal-review` directly? (Recommendation: `draft` — the secretary should review the auto-generated record before it enters the approval workflow; the notification sent on auto-creation includes a "Start goedkeuringsproces" action button)
