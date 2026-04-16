## Context

Decidesk is a thin-client Nextcloud app: all domain data is stored in OpenRegister. The post-meeting workflow — Minutes lifecycle, Decision recording, ActionItem tracking, and minutes generation — was delivered in p2-minutes-and-decisions. T1 added the highest-demand compliance extensions (document generation, statutory deadlines, urgent flag, decision list). This T3 change adds the operational efficiency tier: cross-meeting analytics, live decision entry, ALV-specific minutes, approval notifications, auto-extraction of action items, decision rationale capture, and notification on publication.

All four entities — Decision, Minutes, ActionItem, and Meeting — are already registered as OpenRegister schemas from p1-schemas-and-data-model. No schema changes are required: the `rationale` concept uses the OpenRegister built-in `notes` array with a labelled note, and analytics queries use `ObjectService.findAll()` with filters.

The Board Secretary is the primary actor for most features: they run meetings, record decisions, generate and distribute minutes, and track follow-up. The CEO / Director needs the analytics overview and decision notifications. The chair needs the approval request notifications.

## Goals / Non-Goals

**Goals:**
- Multi-meeting action item analytics — KPI cards, completion rate chart, personal action item list on the Dashboard
- Live decision recording during active meeting via "Besluiten" tab on Meeting detail
- ALV minutes template generation and member distribution
- Minutes approval request with Nextcloud notifications to chair and secretary
- Auto-extract action item candidates from Minutes `content` with preview-and-confirm
- Decision rationale ("Overwegingen") capture via OpenRegister notes
- Decision notification dispatch when `isPublished` transitions to `true`

**Non-Goals:**
- AI/LLM-powered transcription or decision summary (deferred)
- Registered mail integration for formal legal notification (deferred)
- Full DMN (Decision Model & Notation) workflow integration (deferred)
- External analytics platforms (deferred)
- Complex NLP entity extraction for action items (regex-based only)
- API to Agent Tool Conversion (deferred)

## Decisions

### 1. Analytics computed at query time via ObjectService, not cached
**Decision**: `ActionItemAnalyticsService` computes analytics on each dashboard load by calling `ObjectService.findAll()` with filters for `taskStatus`, `dueDate`, and `assignee`. No separate analytics cache or materialized view.
**Rationale**: OpenRegister's `ObjectService.findAll()` supports filtering and pagination. For the scale of Dutch governance deployments (hundreds to low thousands of ActionItems), query-time computation is fast enough. Caching would require additional infrastructure and invalidation logic that adds maintenance cost without meaningful benefit at this scale. ADR-001 prohibits custom data stores.
**Alternative considered**: Pre-computed analytics table — rejected (custom mapper, ADR-001 violation); client-side calculation from a full export — rejected (performance risk at scale).

### 2. Decision rationale stored in OpenRegister built-in `notes` array
**Decision**: The "Overwegingen" rationale is stored as a note with `label: "overwegingen"` in the Decision object's built-in `notes` array via `ObjectService.saveObject()`. The Decision detail page reads the notes array and renders the first note with this label in the "Overwegingen" section.
**Rationale**: ADR-000 defines no `rationale` property on Decision. Adding one would be a breaking schema change (ADR-011). The built-in `notes` array already supports labelled, free-text content with full audit trail tracking (ADR-001). This approach requires zero schema changes and avoids an ADR-000 update.
**Alternative considered**: A new `rationale: string` field on Decision schema — rejected (breaking ADR-000 change without a new ADR-000 revision; notes covers the use case).

### 3. Live decision recording uses the existing Decision schema and Meeting relation
**Decision**: The `LiveDecisionPanel.vue` creates Decision objects via `ObjectService.saveObject()` with a relation to the parent Meeting. If no Minutes object is linked to the Meeting, the panel auto-creates a draft Minutes object with `title` set to "Concept notulen — {meeting title}" and `lifecycle: draft`. No separate "live mode" entity is introduced.
**Rationale**: Reusing existing Decision + Minutes schemas (ADR-000, ADR-012) avoids new entities. The Meeting relation is already supported by OpenRegister's relation mechanism. The "live" aspect is purely a frontend UX — the data model is unchanged.
**Alternative considered**: A "LiveSession" entity for in-progress meeting state — rejected (ADR-000 is the source of truth; no new entity needed; existing Meeting lifecycle `opened` serves as the gate).

### 4. Action item extraction uses regex markers, not NLP
**Decision**: `ActionItemExtractionService::extractFromContent()` uses PHP regex patterns to detect action item markers in the Minutes `content` string. Patterns: lines starting with `Actie:`, `AI:`, `Taak:`, `Actiepunt:`, or lines containing Dutch action phrases (`wordt verzocht`, `zal worden`, `is toegezegd`). Each match is converted to an ActionItem candidate with `title` from the matched text and `taskStatus: open`. The secretary reviews candidates in a modal before saving.
**Rationale**: LLM-based extraction (ADR-001's `ChatService`) requires always-on infrastructure and raises GDPR concerns for meeting minutes. Regex-based extraction covers the 80% case (structured Dutch minutes follow predictable patterns). The preview-and-confirm step protects against false positives. This is consistent with the `MinutesGenerationService` approach (template-based, deterministic, no LLM).
**Alternative considered**: `ChatService` / LLM extraction — deferred; privacy risk; infrastructure dependency.

### 5. Approval request transitions `lifecycle: draft → review` and sends notifications
**Decision**: "Ter goedkeuring indienen" on a Minutes in `draft` state calls `WorkflowEngineController` to transition `lifecycle: draft → review` (existing transition from p2-minutes-and-decisions), then dispatches Nextcloud notifications via `NotificationService` to all users who have an active Membership with role `chair` or `secretary` in the linked GovernanceBody. The notification includes the minutes title, `decisionDate` context, and a deep link to the Minutes detail page.
**Rationale**: The `draft → review` transition already exists in the workflow. Attaching notification dispatch to it avoids a separate workflow state. Role-based recipient lookup reuses the existing Membership relation on GovernanceBody (ADR-001). `NotificationService` is the platform service for in-app notifications (ADR-001).
**Alternative considered**: Email-only notification — rejected (requires external SMTP config; Nextcloud notifications are native and reliable); separate "approval request" state — rejected (over-engineering for the use case).

### 6. ALV minutes use a dedicated Dutch template branch in ALVMinutesService
**Decision**: `ALVMinutesService::generateALVDraft(string $minutesId): string` is a new standalone service (not extending `MinutesGenerationService`) that renders an ALV-specific Dutch template. It checks that the linked Meeting has `meetingType` matching `alv` (or `algemene-ledenvergadering`); if not, it returns a validation error. The ALV template includes: quorum confirmation, member roll call, agenda items as resolutions with vote totals, and a standard "rondvraag en sluiting" section.
**Rationale**: The ALV template is structurally different from the general council minutes template — it must confirm quorum under association law, use "leden" instead of "raadsleden", and include formal resolution language per Dutch association/BV law. A separate service class is cleaner than branching logic inside `MinutesGenerationService` (ADR-012: single-responsibility; extension is acceptable, but a 50-line branch adds complexity).
**Alternative considered**: Extending `MinutesGenerationService` with an ALV branch — acceptable but deferred; separate service is cleaner and testable in isolation.

### 7. Decision notification uses DecisionNotificationService hooked into DecisionService
**Decision**: `DecisionNotificationService::notifyOnPublish(string $decisionId, array $recipients)` sends Nextcloud notifications to recipients when called. It is invoked from `DecisionService` (T1) after `isPublished` is set to `true` via `ObjectService.saveObject()`. Recipients are resolved by `DecisionNotificationService::resolveRecipients(string $decisionId): array` which reads Membership records for the linked GovernanceBody and filters by role (configurable: default `chair`, `secretary`, `member`). The roles included in notification are configurable via `IAppConfig` key `decision_notify_roles`.
**Rationale**: `NotificationService` is the ADR-001 platform service. Hooking into `DecisionService` avoids duplicating publication logic. Role-based recipient resolution reuses Membership data (ADR-001, ADR-012). The configurable roles allow water boards, associations, and corporate boards to customise who receives notifications.
**Alternative considered**: Webhook dispatch (ADR-001 WebhookService) — deferred to p3-ori-publication; Nextcloud notifications are sufficient for T3.

## Reuse Analysis (ADR-012)

| Capability | OpenRegister service / component used | Custom code |
|---|---|---|
| Action item analytics queries | `ObjectService.findAll()` with filters | `ActionItemAnalyticsService` (aggregation logic) |
| Analytics chart | `CnChartWidget` (ApexCharts built-in) | `ActionItemAnalyticsWidget.vue` (props mapping) |
| Live decision entry | `ObjectService.saveObject()` | `LiveDecisionPanel.vue` (quick-entry form) |
| Auto-init draft Minutes | `ObjectService.saveObject()` | Called from `LiveDecisionPanel.vue` |
| ALV template rendering | `ObjectService.findAll()` (AgendaItems, Participants) | `ALVMinutesService::generateALVDraft()` |
| ALV distribution notifications | `NotificationService` (built-in) | Called from `ALVMinutesService::distribute()` |
| Minutes approval notification | `NotificationService` + `WorkflowEngineController` | Hooked into existing `draft → review` transition |
| Action item extraction | `ObjectService.saveObject()` (ActionItem creation) | `ActionItemExtractionService::extractFromContent()` |
| Decision rationale display | `ObjectService.saveObject()` (notes array, built-in) | "Overwegingen" section in `DecisionDetail.vue` |
| Decision notification | `NotificationService` + Membership query | `DecisionNotificationService` |
| Membership role lookup | `ObjectService.findAll()` (Membership filter) | Called from `DecisionNotificationService` |
| Analytics personal list | `ObjectService.findAll()` (filter by assignee) | None (standard list query) |
| Pagination and filtering | `CnFilterBar` + `useListView` | None |
| Audit trail | `AuditTrailService` (automatic) | None |

No new entities are proposed. No overlap with OpenRegister core services beyond what is listed. `ActionItemAnalyticsService`, `ALVMinutesService`, `ActionItemExtractionService`, and `DecisionNotificationService` are the only net-new PHP classes.

## Seed Data

### Minutes — ALV examples (3 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Minutes", "slug": "notulen-alv-vereniging-dorpshuis-laren-2025" },
    "title": "Notulen Algemene Ledenvergadering Vereniging Dorpshuis Laren — 18 maart 2025",
    "lifecycle": "approved",
    "content": "De voorzitter opent de ALV om 20:00 uur en stelt vast dat 47 van de 89 leden aanwezig zijn (quorum behaald). Agendapunt 1 — Vaststelling notulen vorige ALV: de notulen van 19 maart 2024 worden ongewijzigd vastgesteld. Agendapunt 2 — Jaarverslag 2024: het jaarverslag 2024 wordt aangenomen met 44 stemmen voor, 2 tegen, 1 onthouding. Actie: penningmeester stuurt het goedgekeurde jaarverslag naar alle leden vóór 1 april 2025. Agendapunt 3 — Begroting 2025: de begroting 2025 wordt vastgesteld met 43 stemmen voor. Rondvraag: geen bijzonderheden. Sluiting: 21:30 uur.",
    "approvedAt": "2025-04-02T10:00:00Z",
    "signedBy": ["Marjan Visser", "Erik de Boer"],
    "version": 2
  },
  {
    "@self": { "register": "decidesk", "schema": "Minutes", "slug": "notulen-alv-buurtvereniging-zwolle-2025" },
    "title": "Notulen Algemene Ledenvergadering Buurtvereniging Assendorp Zwolle — 5 april 2025",
    "lifecycle": "review",
    "content": "Aanvang 19:30 uur. Aanwezig: 31 leden. Quorum (25 leden) behaald. Actie: secretaris stuurt uitnodiging nieuwe leden vóór 15 mei 2025. Agendapunt 4 — Wijziging statuten: het voorstel tot wijziging van artikel 12 lid 3 wordt aangenomen met 2/3 meerderheid (27 stemmen voor, 4 tegen). Taak: notaris ontvangt concept-statutenwijziging uiterlijk 1 juni 2025.",
    "version": 1
  },
  {
    "@self": { "register": "decidesk", "schema": "Minutes", "slug": "notulen-alv-woningcorporatie-haag-wonen-2025" },
    "title": "Notulen Huurdersraadsvergadering Haag Wonen — 22 april 2025",
    "lifecycle": "draft",
    "content": "Concept-notulen, nog niet vastgesteld. Actiepunt: huurcommissie onderzoekt mogelijkheden voor onderhoud lift Bezuidenhoutseweg 45 en rapporteert terug voor 1 juni 2025.",
    "version": 1
  }
]
```

### ActionItem — extracted from minutes (5 objects)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-alv-jaarverslag-versturen-laren" },
    "title": "Jaarverslag 2024 versturen aan alle leden",
    "description": "Penningmeester stuurt het goedgekeurde jaarverslag 2024 naar alle 89 leden van Vereniging Dorpshuis Laren vóór 1 april 2025.",
    "assignee": "Penningmeester",
    "dueDate": "2025-04-01T00:00:00Z",
    "taskStatus": "completed",
    "completedAt": "2025-03-29T11:00:00Z"
  },
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-alv-uitnodiging-nieuwe-leden-zwolle" },
    "title": "Uitnodiging nieuwe leden versturen",
    "description": "Secretaris stuurt uitnodiging voor ALV 2025 aan nieuwe leden vóór 15 mei 2025, conform besluit ALV 5 april 2025.",
    "assignee": "Secretaris",
    "dueDate": "2025-05-15T00:00:00Z",
    "taskStatus": "open"
  },
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-alv-statutenwijziging-notaris-zwolle" },
    "title": "Concept-statutenwijziging naar notaris sturen",
    "description": "Notaris ontvangt concept-wijziging artikel 12 lid 3 uiterlijk 1 juni 2025 voor formele bekrachtiging.",
    "assignee": "Voorzitter",
    "dueDate": "2025-06-01T00:00:00Z",
    "taskStatus": "in-progress"
  },
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-lift-onderzoek-haag-wonen" },
    "title": "Onderzoek onderhoud lift Bezuidenhoutseweg 45",
    "description": "Huurcommissie onderzoekt mogelijkheden voor lift-onderhoud en rapporteert terug aan huurdersraad vóór 1 juni 2025.",
    "assignee": "Huurcommissie",
    "dueDate": "2025-06-01T00:00:00Z",
    "taskStatus": "open"
  },
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-besluit-notificatie-woningbouw-verzonden" },
    "title": "Besluitnotificatie Woningbouwplan Oost verzonden",
    "description": "Automatische besluitnotificatie verzonden aan alle raadsleden na publicatie besluit Vaststelling Woningbouwplan Oost 2025-2030.",
    "assignee": "Griffier",
    "dueDate": "2025-03-22T00:00:00Z",
    "taskStatus": "completed",
    "completedAt": "2025-03-21T09:05:00Z"
  }
]
```

## Risks / Trade-offs

- **[Risk] Analytics performance degrades with very large ActionItem datasets** → Mitigation: `ActionItemAnalyticsService` applies date-range filters (default: current year) to limit query scope; the service accepts `limit` and `dateFrom` parameters; dashboard displays a "Beperkt tot {year}" label when the filter is active
- **[Risk] Action item extraction regex produces false positives on formatted text** → Mitigation: the extraction preview modal always appears before saving; the secretary can uncheck, edit title, or assign each candidate; no action items are saved without explicit confirmation
- **[Risk] ALV distribution sends notifications to all GovernanceBody participants, including inactive members** → Mitigation: `ALVMinutesService::distribute()` filters by `Participant.leftAt == null` (active members only); a preview step shows the recipient list before dispatching
- **[Risk] Approval request notifications sent to all chair/secretary roles may create noise in large bodies** → Mitigation: notification content is concise (title + deep link + one action button); the `decision_notify_roles` config allows restriction to specific roles; notification frequency is bounded by the number of Minutes objects submitted for approval
- **[Trade-off] Rationale stored in notes array, not a dedicated field** → Acceptable for v1 (avoids breaking schema change); notes are already displayed in the sidebar; the label `overwegingen` makes the note identifiable; a dedicated field can be added in a future ADR-000 revision when demand justifies the breaking change
- **[Trade-off] Live decision panel only available when `lifecycle: opened`** → Intentional; prevents test entries from being recorded outside an active meeting; the secretary can manually set lifecycle via the existing lifecycle transition controls

## Migration Plan

1. No schema changes — Decision, Minutes, ActionItem, Meeting all unchanged from ADR-000
2. Add `ActionItemAnalyticsService.php`, `ALVMinutesService.php`, `ActionItemExtractionService.php`, `DecisionNotificationService.php`
3. Extend `DecisionService.php` (T1) with `notifyOnPublish()` hook; register call after `isPublished: true` save
4. Add new API routes in `appinfo/routes.php`: `GET /api/analytics/action-items`, `POST /api/minutes/{id}/generate-alv`, `POST /api/minutes/{id}/distribute`, `POST /api/minutes/{id}/submit-for-approval`, `POST /api/minutes/{id}/extract-action-items`
5. Frontend: add `ActionItemAnalyticsWidget.vue`, `LiveDecisionPanel.vue`, `ALVMinutesActions.vue`, `ActionItemExtractionModal.vue`; extend `DecisionDetail.vue`, `MinutesDetail.vue`, `MeetingDetail.vue`
6. Add seed data for ALV Minutes (3 objects) and extracted ActionItems (5 objects) via repair step
7. Add translation keys for all new strings in `l10n/nl.json` and `l10n/en.json`

## Open Questions

- Should the analytics date range be configurable per user, or fixed to "current calendar year" for all users? (Recommendation: current year as default with a date-range picker in the analytics panel; user preference stored in frontend `localStorage`)
- Should ALV minutes distribution include a PDF attachment of the signed minutes, or only a link? (Recommendation: link only for v1; PDF attachment requires `FileService` export pipeline which can be added in a future sprint alongside e-signing)
- Should the `decision_notify_roles` configuration be per-GovernanceBody or global? (Recommendation: global default with per-GovernanceBody override in the GovernanceBody settings panel; defer per-body config to p3-governance-bodies)
- Should the action item extraction also suggest an `assignee` by detecting names in the matched text? (Recommendation: yes, using simple name detection against the known Participant list; surfaced as a suggestion in the extraction modal with a dropdown)
