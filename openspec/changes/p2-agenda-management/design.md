## Context

Decidesk is a Nextcloud app using the **thin-client** pattern: all domain data is stored in OpenRegister; the backend provides only settings, business-rule services, and PDF generation. The `AgendaItem` entity was introduced in p1-crud-operations with basic CRUD views. This change adds the full governance agenda lifecycle on top of that foundation: building, publication, live amendments, consent-item processing, BOB phase tracking, and conflict-of-interest declaration.

Dutch governance workflows (gemeenteraden, waterschappen, provinciale staten) follow the BOB model (Beeldvorming → Oordeelsvorming → Besluitvorming). An agenda item moves through these phases during the meeting. Consent items (hamerstukken) bypass the BOB phases and are adopted without debate. These domain rules are the primary custom business logic this spec adds; everything else (CRUD, ordering, file attachments, notifications) is provided by the OpenRegister platform.

## Goals / Non-Goals

**Goals:**
- Provide a drag-and-drop agenda builder that orders AgendaItems by `orderNumber` and supports time allocation
- Allow participants to submit agenda item proposals; chair approves before publication
- Publish a complete agenda package (AgendaItem list + attachments) and distribute to participants via Nextcloud notifications
- Support live amendments during an open meeting with chair privilege gate
- Track BOB phase per agenda item using the OpenRegister built-in `status` field
- Process consent agenda items (hamerstukken) with a single batch-adopt action
- Allow participants to declare conflict of interest against specific agenda items

**Non-Goals:**
- Conduct votes on agenda items (p2-motion-and-voting)
- Record meeting minutes (p2-minutes-and-decisions)
- AI-assisted agenda drafting (future AI spec)
- Video/webcast indexing by agenda item (future media spec)
- Auto-create Talk conversations (future integration spec)

## Decisions

### 1. BOB phase via OpenRegister built-in `status` field
**Decision**: Track BOB phase using the OpenRegister built-in `status` field on AgendaItem, with values `voorstel`, `beeldvorming`, `oordeelsvorming`, `besluitvorming`, `afgerond`.
**Rationale**: ADR-000 does not add a `bobPhase` property to AgendaItem. The platform-provided `status` field serves this purpose without a schema change. This avoids a breaking migration.
**Alternative considered**: Add `bobPhase` property to AgendaItem schema — rejected because ADR-000 is the single source of truth and must not be modified per-spec.

### 2. Consent items tagged `hamerstuk`
**Decision**: Consent agenda items are identified by the OpenRegister built-in `tags` field containing `hamerstuk`. The batch-adopt action filters items with this tag.
**Rationale**: No schema change needed. Tags are first-class OpenRegister features with existing UI support in `CnObjectSidebar`. Chair can tag/untag items using standard tag UI.
**Alternative considered**: A boolean `isConsentItem` field — rejected (schema change required; tags achieve the same outcome).

### 3. COI declarations via OpenRegister built-in notes
**Decision**: Conflict-of-interest declarations are stored as structured notes on AgendaItem objects, with the note title prefixed `COI:` and the Participant's display name in the body.
**Rationale**: No custom entity or relation required. Notes are built-in to every OpenRegister object. The `CnObjectSidebar` notes tab shows them inline. Future specs can query notes filtered by `COI:` prefix.
**Alternative considered**: A dedicated ConflictOfInterest entity — rejected (ADR-000 not extended in this spec; adds complexity without benefit at p2 scope).

### 4. Spokesperson as OpenRegister relation AgendaItem → Participant
**Decision**: Spokesperson assignment is stored as a named relation `spokesperson` from AgendaItem to Participant, using the OpenRegister `relations` mechanism.
**Rationale**: ADR-000's AgendaItem does not have a `spokesperson` property. The built-in relation mechanism handles this without a schema migration. `CnDetailCard` can render relation values inline.
**Alternative considered**: A free-text `spokesperson` string field in the schema — rejected (requires migration; relation gives type-safety and links to the Participant record).

### 5. Agenda publication via `AgendaService::publishAgenda()`
**Decision**: A single `AgendaService::publishAgenda(meetingId)` method (a) validates all required items are present, (b) calls `NotificationService` to notify participants, (c) calls `CalendarEventService` to update the meeting calendar entry, (d) transitions Meeting lifecycle from `scheduled` to `opened` (Meeting update via ObjectService). No PDF is generated in p2 — publication = distribution of the structured agenda.
**Rationale**: PDF generation requires docudesk or a template engine; that is out of scope for p2. Structured digital distribution (notifications + calendar) covers the demand-driven requirement. PDF export can be added via `ExportService` in a later spec.
**Alternative considered**: Generate a PDF board pack — deferred to p3.

### 6. Drag-and-drop ordering via `orderNumber` updates
**Decision**: The frontend drag-and-drop builder reorders AgendaItems by updating `orderNumber` values via `ObjectService.saveObject()` after each drop. All items in the meeting's agenda are re-numbered sequentially (1, 2, 3…).
**Rationale**: The `orderNumber` field is already in ADR-000. No backend changes needed. Platform `useListView` sorts by `orderNumber` ascending automatically.
**Alternative considered**: A separate ordering array on the Meeting object — rejected (denormalized; `orderNumber` on AgendaItem is the canonical approach per ADR-000).

## Reuse Analysis (ADR-012)

| Capability | OpenRegister service / component used | Custom code |
|---|---|---|
| CRUD | `ObjectService`, `CnIndexPage`, `CnDetailPage` | None |
| Ordering | `useListView` sort by `orderNumber` | Frontend drag-drop handler only |
| File attachments | `FileService` + `CnObjectSidebar` `CnFilesTab` | None |
| BOB phase display | `CnStatusBadge`, `CnTimelineStages` | `AgendaService::advanceBobPhase()` |
| Notifications | `NotificationService` | `AgendaService::publishAgenda()` |
| Calendar update | `CalendarEventService` | Called from `AgendaService::publishAgenda()` |
| Consent batch adopt | `ObjectService.saveObjects()` bulk update | `AgendaService::processHamerstukken()` |
| COI notes | `CnObjectSidebar` `CnNotesCard` | COI note-writing helper only |
| Spokesperson relation | `relationsPlugin` on object store | None |
| Export | `ExportService` + `CnMassExportDialog` | None |
| Search | `IndexService` + `CnFilterBar` | None |

No new capabilities were identified that should be moved to OpenRegister core.

## Seed Data (Dutch examples)

### AgendaItem

```json
[
  {
    "@self": { "register": "decidesk", "schema": "AgendaItem", "slug": "opening-raadsvergadering-2025-04-14" },
    "title": "Opening vergadering",
    "itemType": "informational",
    "orderNumber": 1,
    "estimatedDuration": 5,
    "isRecurring": true,
    "status": "afgerond"
  },
  {
    "@self": { "register": "decidesk", "schema": "AgendaItem", "slug": "vaststellen-notulen-2025-03-17" },
    "title": "Vaststellen notulen vergadering 17 maart 2025",
    "itemType": "decision",
    "orderNumber": 2,
    "estimatedDuration": 10,
    "isRecurring": true,
    "status": "besluitvorming",
    "tags": ["hamerstuk"]
  },
  {
    "@self": { "register": "decidesk", "schema": "AgendaItem", "slug": "begroting-2026-kadernota" },
    "title": "Kadernota begroting 2026",
    "itemType": "discussion",
    "orderNumber": 3,
    "estimatedDuration": 60,
    "description": "Eerste bespreking van de financiële kaders voor begrotingsjaar 2026. Bijgevoegd: financieel meerjarenperspectief en bijdrage rekenkamer.",
    "status": "beeldvorming"
  },
  {
    "@self": { "register": "decidesk", "schema": "AgendaItem", "slug": "bestemmingsplan-haarlemmermeer-2025" },
    "title": "Vaststellen bestemmingsplan Haarlemmermeer-Noord 2025",
    "itemType": "decision",
    "orderNumber": 4,
    "estimatedDuration": 45,
    "description": "Definitieve vaststelling na inspraakprocedure. Zienswijzen zijn behandeld in de commissievergadering van 31 maart.",
    "status": "oordeelsvorming"
  },
  {
    "@self": { "register": "decidesk", "schema": "AgendaItem", "slug": "rondvraag-2025-04-14" },
    "title": "Rondvraag",
    "itemType": "informational",
    "orderNumber": 99,
    "estimatedDuration": 10,
    "isRecurring": true,
    "status": "beeldvorming"
  }
]
```

## Risks / Trade-offs

- **[Risk] Concurrent orderNumber updates during live amendment** → Two chair actions in quick succession could create duplicate `orderNumber` values. Mitigation: the `AgendaService::reorderItems()` method fetches all items for the meeting and atomically reassigns `orderNumber` values 1..n in a single batch update via `ObjectService`.
- **[Risk] BOB phase value in `status` field conflicts with other uses of `status`** → OpenRegister `status` is a free-text field; collision is possible if other specs use it differently. Mitigation: document allowed values in ADR-000 comments; the design decision is reversible by adding a `bobPhase` property in a future non-breaking migration.
- **[Risk] Notification spam on agenda amendments during meeting** → Sending a notification on every item reorder would flood participants. Mitigation: `AgendaService::publishAgenda()` sends one notification per publication event only; live amendments do not send new notifications.
- **[Trade-off] No PDF board pack in p2** — publication is digital (structured data + notifications) rather than a traditional PDF. This satisfies the "Digital Agenda Distribution" (demand 302) requirement but defers the PDF board book requirement (from "Agenda Builder and Board Pack Publishing Workflow", demand 263) to p3.

## Migration Plan

1. No schema migrations required — AgendaItem entity is unchanged from p1-crud-operations
2. Add `AgendaService` backend service with `publishAgenda()`, `advanceBobPhase()`, `processHamerstukken()`, and `reorderItems()` methods
3. Add `AgendaController` with routes: `POST /api/agendas/{meetingId}/publish`, `PUT /api/agenda-items/{id}/bob-phase`, `POST /api/agendas/{meetingId}/hamerstukken`
4. Frontend: extend existing `AgendaItemDetail.vue` (from p1) with BOB phase `CnTimelineStages`, COI note panel, spokesperson relation display
5. Frontend: add `AgendaBuilder.vue` as a new sub-view within MeetingDetail showing drag-drop item list
6. Seed data objects are upserted on install — no existing data is modified

## Open Questions

- Should the agenda publication send a Nextcloud notification to ALL meeting participants or only registered Participants in the GovernanceBody? (Recommendation: use Participant relations on the Meeting; fall back to Nextcloud group membership)
- Should items proposed by participants (`status: voorstel`) be visible to all members or only the chair before approval? (Recommendation: visible to chair only until approved; use `ObjectService` permission filter by role)
- Should `processHamerstukken()` create a Decision object immediately or wait for the minutes workflow? (Recommendation: defer Decision creation to p2-minutes-and-decisions; hamerstukken processing just bulk-updates AgendaItem status to `afgerond`)
