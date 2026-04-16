**status:** proposed

## Context

Decidesk stores all domain data in OpenRegister. The entities touched by this change — Decision and ActionItem — are already declared as OpenRegister schemas in `decidesk_register.json` and have full CRUD, lifecycle, and audit trail support from p2-minutes-and-decisions.

This change adds three interaction patterns on top of those existing objects:

1. **Decision evolution** — using OpenRegister's built-in `relations` mechanism to link Decision objects to one another (no new fields, no schema changes).
2. **Stakeholder notification** — dispatching Nextcloud in-app notifications from a backend service when a secretary explicitly triggers "Betrokkenen informeren" on a published Decision.
3. **Department cascade** — creating ActionItems in bulk (one per selected governance body / department) from the Decision detail view.

All three patterns reuse platform services and respect ADR-001 (no custom Entity/Mapper), ADR-003 (thin controller → service → ObjectService), and ADR-012 (no overlap with existing capabilities).

## Goals / Non-Goals

**Goals:**
- Allow Decision objects to be linked to related decisions with typed relations (amends, supersedes, replaces, is-superseded-by) via the OpenRegister relation mechanism
- Display the full evolution chain on the Decision detail page
- Enable an explicit, secretary-triggered notification to selected participants when a decision is published
- Enable bulk creation of ActionItems linked to a Decision for cascading to departments
- Add one Dashboard KPI for open cascade action items

**Non-Goals:**
- Automatic stakeholder discovery (no LDAP / org-chart integration; secretary selects recipients manually)
- Email or external notification channels (Nextcloud in-app notifications only in v1)
- Cross-organisation cascading (single Nextcloud instance only)
- AI-generated decision summaries for notifications (plain title + outcome text)
- Workflow enforcement on cascaded ActionItems (departments manage their own status transitions)

## Decisions

### 1. Decision-to-decision links use OpenRegister built-in relations — no new fields
**Decision**: Use `ObjectService::addRelation()` / OpenRegister's `relations` property to link Decision objects with a labelled relation type stored as the relation's `label`. No new schema fields are added.
**Rationale**: ADR-001 forbids foreign keys or embedded objects. OpenRegister relations are the correct mechanism for cross-object links. The relation `label` field carries the semantic type (amends / supersedes / replaces / is-superseded-by). This is non-breaking — the Decision schema is unchanged.
**Alternative considered**: Add `relatedDecisions` array field to the Decision schema — rejected because it duplicates the platform relations mechanism and introduces a breaking schema change.

### 2. Stakeholder notification uses OpenRegister NotificationService
**Decision**: `DecisionNotificationService` calls OpenRegister's `NotificationService::sendNotification()` for each selected participant UID. Notification body: decision title + outcome + deep-link URL (`/apps/decidesk/decisions/{uuid}`).
**Rationale**: ADR-004 and the platform catalogue confirm `NotificationService` is available and handles delivery, deduplication, and Nextcloud notification inbox. Building a custom notification dispatcher would violate ADR-012.
**Alternative considered**: Direct PHP `\OCP\INotificationManager` calls — rejected; OpenRegister's `NotificationService` is the correct abstraction layer within this app stack.

### 3. Cascade creates one ActionItem per selected department via ObjectService
**Decision**: `DecisionCascadeService::cascade(string $decisionId, array $departmentIds): array` loops over `$departmentIds`, calls `ObjectService::saveObject('decidesk', 'ActionItem', [...])` for each, and returns the created ActionItem UUIDs. Each ActionItem is pre-populated with: `title` = "Uitvoering: {decision title}", `taskStatus: open`, `description` = decision text excerpt, and an OpenRegister relation pointing back to the source Decision.
**Rationale**: ActionItem is already the correct entity for follow-up work (ADR-000, p2-minutes-and-decisions). Bulk creation via `ObjectService::saveObject()` is the standard pattern. No new schema or entity is needed.
**Alternative considered**: A dedicated "CascadeTask" entity — rejected; ActionItem fully covers the use case and a new entity would violate ADR-012 (unnecessary duplication).

### 4. Relation type stored as the OpenRegister relation label
**Decision**: The relation label field is used to store the semantic type string: `"amends"`, `"supersedes"`, `"replaces"`, or `"is-superseded-by"`. The frontend renders a translated badge per relation.
**Rationale**: OpenRegister's relation object includes a `label` string field designed for this purpose. No custom schema extension needed.

### 5. Notification dispatcher is triggered by explicit user action, not automatically on publish
**Decision**: Notification is only sent when the secretary clicks "Betrokkenen informeren" and confirms the participant selection. Publishing a decision (setting `isPublished: true`) does NOT automatically trigger notifications.
**Rationale**: Consistent with the explicit-publish decision in p2-minutes-and-decisions (human oversight over what is communicated). Automatic notification would risk alerting stakeholders before the secretary has verified the published text.

## Reuse Analysis (ADR-012)

| Capability | OpenRegister / Platform service used |
|------------|--------------------------------------|
| Decision / ActionItem CRUD | `ObjectService` + `CnIndexPage` + `CnDetailPage` (from p2-minutes-and-decisions) |
| Decision-to-decision relations | OpenRegister built-in `relations` mechanism via `ObjectService::addRelation()` |
| Relation display (forward + reverse) | `fetchUses` (decisions this one amends) + `fetchUsed` (decisions that amend this one) |
| Stakeholder notifications | `NotificationService` (OpenRegister built-in) |
| Bulk ActionItem creation (cascade) | `ObjectService::saveObject()` in a service loop |
| Dashboard KPI | `CnStatsBlock` (from p2-minutes-and-decisions Dashboard) |
| Participant picker UI | `CnFormDialog` with autocomplete from Participants object store |

No overlap with existing specs. Notification dispatch and relation-typed linking are not implemented elsewhere in Decidesk. Cascade ActionItem creation extends — but does not duplicate — the existing ActionItem workflow.

## Seed Data

This change introduces no new schemas and does not modify existing Decision or ActionItem schemas. Seed objects for both entities are already defined in `p2-minutes-and-decisions`. The examples below illustrate the cascade ActionItems and relation links that would be created at runtime — they are not loaded via the seed mechanism.

### Example: Decision-to-Decision Relation

A new decision "Woningbouwplan Oost 2025-2030 (herzien)" supersedes the earlier "Woningbouwplan Oost 2023-2025". In OpenRegister this is stored as a relation on the new Decision object:

```json
{
  "register": "decidesk",
  "schema": "Decision",
  "object": "<uuid-nieuw-besluit>",
  "relation": {
    "register": "decidesk",
    "schema": "Decision",
    "objectId": "<uuid-oud-besluit>",
    "label": "supersedes"
  }
}
```

### Example: Cascade ActionItems (3 objects per decision)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "cascade-woningbouw-dienst-so" },
    "title": "Uitvoering: Vaststelling Woningbouwplan Oost 2025-2030",
    "description": "Het raadsbesluit d.d. 20 maart 2025 inzake het Woningbouwplan Oost 2025-2030 dient door de afdeling Stedelijke Ontwikkeling te worden uitgewerkt in een implementatieplan.",
    "assignee": "Afdeling Stedelijke Ontwikkeling",
    "dueDate": "2025-06-30",
    "taskStatus": "open"
  },
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "cascade-woningbouw-dienst-fin" },
    "title": "Uitvoering: Vaststelling Woningbouwplan Oost 2025-2030",
    "description": "De afdeling Financiën dient de budgetruimte voor het Woningbouwplan Oost 2025-2030 te reserveren conform het raadsbesluit.",
    "assignee": "Afdeling Financiën",
    "dueDate": "2025-06-30",
    "taskStatus": "open"
  },
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "cascade-woningbouw-dienst-jz" },
    "title": "Uitvoering: Vaststelling Woningbouwplan Oost 2025-2030",
    "description": "De afdeling Juridische Zaken controleert de juridische grondslag en eventuele bezwaarrisico's voor het Woningbouwplan Oost 2025-2030.",
    "assignee": "Afdeling Juridische Zaken",
    "dueDate": "2025-05-31",
    "taskStatus": "in-progress"
  }
]
```

### Example: Notification Recipients (illustrative)

When "Betrokkenen informeren" is triggered for the decision "Vaststelling subsidieregeling verduurzaming particuliere woningen 2025":

| Participant | Role | Notification sent |
|-------------|------|-------------------|
| Roos de Vries | Voorzitter (chair) | Yes |
| Jan Bakker | Secretaris | Yes |
| Petra van den Berg | Raadslid (member) | Yes |
| Ahmed El-Farsi | Raadslid (member) | Yes |
| Lotte Smit | Hoofd afdeling Wonen | Yes |

Notification text: _"Besluit gepubliceerd: Vaststelling subsidieregeling verduurzaming particuliere woningen 2025 — uitkomst: aangenomen. Klik hier om het besluit te bekijken."_
