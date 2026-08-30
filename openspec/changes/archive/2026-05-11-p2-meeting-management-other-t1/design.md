## Context

Decidesk is a thin-client Nextcloud app built on OpenRegister and CalDAV-first storage (ADR-002). The `p2-meeting-management` change delivered the Meeting lifecycle state machine and its `MeetingLifecycle.vue` component. This T1 change adds three highest-demand "other" category features that operate entirely on top of the existing `Meeting` (CalDAV VEVENT + OpenRegister wrapper), `AgendaItem`, and `GovernanceBody` entities from ADR-000 — no schema additions required.

The Board Secretary / Company Secretary (primary persona) needs: (1) a governance platform where video links are a first-class UI element, not buried in a text field; (2) structured debate management tools that replace paper speaker lists and phone-timer workarounds; (3) recurring meeting series configured once, not cloned monthly. The presiding officer (council chair, dijkgraaf, RvC chair) needs the speaking time clock to enforce fair debate. Citizens and journalists following live meetings need a public-access live view that does not require a Nextcloud account.

## Goals / Non-Goals

**Goals:**
- Digital/hybrid meeting join button and live badge from `Meeting.meetingMode` and `lifecycle`
- Public live view page (`/apps/decidesk/meetings/{id}/live`) — no login required
- Current AgendaItem indicator via OpenRegister built-in `status` field
- Speaking time countdown clock driven by `AgendaItem.estimatedDuration`
- Speaker queue with chair controls (add, reorder, remove)
- Cumulative time balance bars per participant
- CalDAV RRULE support for weekly, bi-weekly, monthly, quarterly recurrence
- Series filter in Meeting index; series badge on Meeting detail

**Non-Goals:**
- Actual video conferencing infrastructure (Teams, Zoom, Jitsi integration — external services)
- Real-time WebSocket push for live view (polling is sufficient for T1)
- Server-side persistence of speaker queue or timer state (T1 is session-local)
- AI-generated meeting transcription or speech recognition
- Full public citizen participation portal (p3)
- Voting via live view (p3)
- RFID attendance (p3)

## Decisions

### 1. Video link rendered from `Meeting.location` — no new field
**Decision**: The `Meeting.location` field already stores the physical location or video link. When `meetingMode` is `digital` or `hybrid`, the UI renders `location` as a clickable "Deelnemen" button using `NcButton` with a video icon. No new field, no schema change.
**Rationale**: ADR-000 defines `location` as "Physical location or video link". The field's stated purpose already covers this use case. Adding a separate `videoLink` field would violate ADR-012 (deduplication — existing capability exists) and introduce a breaking schema change.
**Alternative considered**: New `videoLink` field on Meeting — rejected (ADR-000 update required; `location` already serves this purpose per the schema description).

### 2. Current AgendaItem tracked via OpenRegister built-in `status` field
**Decision**: The chair or secretary marks an AgendaItem as currently being discussed by setting its built-in OpenRegister `status` field to `current`. Other values used are `upcoming` (default) and `completed`. The public live view endpoint reads the AgendaItem with `status: current` for the meeting to determine what is active.
**Rationale**: The OpenRegister `status` field is built-in on all entities and does not require a schema change. Using it for BOB phase tracking was already established in `p2-agenda-management` (where `status` stores `beeldvorming`, `oordeelsvorming`, `besluitvorming`). For this change, `current` is unambiguous and consistent with that pattern.
**Alternative considered**: A new `isCurrent: boolean` field on AgendaItem — rejected (requires ADR-000 schema update; built-in `status` is the established pattern in this codebase).

### 3. Speaking time is session-local in Vue component — no backend persistence for T1
**Decision**: The speaking time clock, speaker queue, and per-participant time totals are maintained in the Vue component's reactive data for the duration of the meeting session. When the chair closes the browser tab, session state is lost. No backend API is needed for the clock state itself.
**Rationale**: T1 scope focuses on the most impactful delivery: a functional speaking time tool that works in a single chair session. Persisting timer state requires either a new backend entity (rejected — ADR-000 must be updated) or overloading existing fields. The `AgendaItem.actualDuration` field can be updated after an item closes to record actual time, which serves as the persistent record.
**Alternative considered**: Storing speaker queue in Meeting `notes` or `tags` — rejected (these fields are not structured for this purpose; would require complex parsing; breaks separation of concerns).
**Post-T1 path**: Speaker queue server persistence and WebSocket real-time sync are tracked for a future `p2-meeting-management-other-t2` change when the Speech entity is implemented.

### 4. RRULE stored in CalDAV VEVENT — no new X-DECIDESK-* property
**Decision**: The CalDAV standard `RRULE` property is written directly into the VEVENT when a meeting is configured as recurring. `CalDavService` is extended to serialize/deserialize RRULE from a structured `RecurrenceRule` PHP object. The `X-DECIDESK-SERIES` property (already in the X-DECIDESK-* registry from ADR-002) provides the series identifier for OpenRegister relational filtering.
**Rationale**: `RRULE` is a native CalDAV property per RFC 5545 Section 3.8.5.3. Using it directly gives free recurrence expansion in Nextcloud Calendar and all external CalDAV clients. No new X-DECIDESK-* extension is needed because `RRULE` is already in the standard. `X-DECIDESK-SERIES` is already defined in ADR-002.
**Alternative considered**: A new `X-DECIDESK-RRULE` extension — rejected (redundant; using the standard RRULE directly is simpler, more interoperable, and prevents proprietary extension creep).

### 5. Public live view via `GET /api/meetings/{id}/live` — minimal data, no auth
**Decision**: A thin public endpoint annotated `#[PublicPage] #[NoCSRFRequired]` returns: `{ title, lifecycle, meetingMode, location, currentAgendaItem: { title, estimatedDuration, orderNumber } }`. The `MeetingLiveView.vue` page polls this endpoint every 30 seconds. Sensitive fields (attendee list, internal notes) are excluded from the public response.
**Rationale**: ADR-002 mandates `#[PublicPage]` annotation for public endpoints. Polling at 30-second intervals is sufficient for agenda state — meetings rarely change items faster than this. No WebSocket infrastructure is needed for T1.
**Alternative considered**: Full Nextcloud shared-link mechanism — rejected (requires additional infrastructure; public API endpoint is simpler and sufficient for read-only agenda tracking).

## Reuse Analysis (ADR-012)

| Capability | OpenRegister service / component used | Custom code |
|---|---|---|
| Meeting data retrieval | `ObjectService.findObject()` (OpenRegister wrapper) + `CalDavService` (VEVENT) | None |
| AgendaItem current status | `ObjectService.saveObject()` (built-in `status` field) | `MeetingController::setCurrentAgendaItem()` (thin) |
| Speaking time limit | `AgendaItem.estimatedDuration` (existing field, read in frontend) | `SpeakingTimeClock.vue` (Vue timer component) |
| Speaker queue | Vue reactive data (no backend for T1) | `SpeakerQueue.vue` |
| Time balance tracking | Vue reactive data (no backend for T1) | `TimeBalanceBar.vue` |
| RRULE storage | CalDAV VEVENT (standard property) | `CalDavService::buildRRule()`, `CalDavService::parseRRule()` |
| Series filtering | `ObjectService.findAll()` with `series` filter + `CnFilterBar` | None (existing filter mechanism) |
| Public meeting state | `ObjectService.findObject()` + `CalDavService` read | `MeetingController::live()` (public, thin) |
| Notifications for recurring | `NotificationService` (existing) | None (called from lifecycle on series) |
| Meeting list / detail | `CnIndexPage` + `CnDetailPage` (existing from p1) | `RecurrencePanel.vue`, `MeetingVideoLink.vue` injected |
| Audit trail | `AuditTrailService` (built-in, automatic) | None |

No new entities are proposed. The only net-new PHP logic is: `CalDavService` RRULE extension, `MeetingController::live()` (public endpoint), and `MeetingController::setCurrentAgendaItem()`.

## Seed Data

This change does not introduce new schemas. The following seed objects supplement the `Meeting` and `AgendaItem` seed data from `p1-crud-operations` and `p2-meeting-management`. They demonstrate digital meeting modes, recurring series, and meeting states with active agenda items.

### Meeting (5 additional objects — digital/hybrid/recurring examples)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Meeting", "slug": "digitale-raadsvergadering-mei-2026" },
    "title": "Digitale Raadsvergadering mei 2026",
    "meetingType": "regular",
    "scheduledDate": "2026-05-14T19:30:00Z",
    "endDate": "2026-05-14T22:00:00Z",
    "location": "https://teams.microsoft.com/l/meetup-join/19%3Ameeting_abc123/0?context=%7B%7D",
    "meetingMode": "digital",
    "lifecycle": "scheduled",
    "quorumRequired": 19,
    "series": "raadsvergadering-2026"
  },
  {
    "@self": { "register": "decidesk", "schema": "Meeting", "slug": "hybride-commissievergadering-ruimte-2026-05" },
    "title": "Hybride Commissievergadering Ruimte — mei 2026",
    "meetingType": "committee",
    "scheduledDate": "2026-05-07T19:00:00Z",
    "endDate": "2026-05-07T22:00:00Z",
    "location": "Raadzaal, Stadhuis Markt 1, Enschede | https://meet.jitsi.si/commissie-ruimte-mei-2026",
    "meetingMode": "hybrid",
    "lifecycle": "opened",
    "quorumRequired": 7,
    "series": "commissievergadering-ruimte-2026"
  },
  {
    "@self": { "register": "decidesk", "schema": "Meeting", "slug": "ab-vergadering-waterboard-april-2026" },
    "title": "AB-vergadering Waterschap Rivierenland — april 2026",
    "meetingType": "regular",
    "scheduledDate": "2026-04-24T14:00:00Z",
    "endDate": "2026-04-24T17:00:00Z",
    "location": "Beatrixgebouw, Churchilllaan 1, Tiel",
    "meetingMode": "in-person",
    "lifecycle": "closed",
    "quorumRequired": 30,
    "series": "ab-vergadering-waterschap-rivierenland"
  },
  {
    "@self": { "register": "decidesk", "schema": "Meeting", "slug": "digitale-buitengewone-vergadering-2026-05" },
    "title": "Buitengewone Digitale Vergadering — Noodprocedure Woningbouw",
    "meetingType": "extraordinary",
    "scheduledDate": "2026-05-03T10:00:00Z",
    "endDate": "2026-05-03T12:00:00Z",
    "location": "https://zoom.us/j/98765432100",
    "meetingMode": "digital",
    "lifecycle": "scheduled",
    "quorumRequired": 19,
    "series": null
  },
  {
    "@self": { "register": "decidesk", "schema": "Meeting", "slug": "rvc-vergadering-q2-2026" },
    "title": "Vergadering Raad van Commissarissen — Q2 2026",
    "meetingType": "regular",
    "scheduledDate": "2026-06-17T09:00:00Z",
    "endDate": "2026-06-17T12:00:00Z",
    "location": "Boardroom, Prinses Julianaplein 10, Den Haag | https://teams.microsoft.com/l/meetup-join/19%3Ameeting_rvc_q2/0",
    "meetingMode": "hybrid",
    "lifecycle": "scheduled",
    "quorumRequired": 4,
    "series": "rvc-kwartaalvergadering-2026"
  }
]
```

### AgendaItem (5 additional objects — with estimatedDuration for speaking time)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "AgendaItem", "slug": "digitale-raad-mei-opening" },
    "title": "Opening en vaststelling agenda",
    "itemType": "informational",
    "orderNumber": 1,
    "estimatedDuration": 5,
    "description": "Voorzitter opent de vergadering en vraagt instemming met de agenda.",
    "isRecurring": true
  },
  {
    "@self": { "register": "decidesk", "schema": "AgendaItem", "slug": "digitale-raad-mei-woningbouw-motie" },
    "title": "Motie: Versnelling woningbouwprogramma 2026-2030",
    "itemType": "decision",
    "orderNumber": 3,
    "estimatedDuration": 45,
    "description": "Behandeling van motie GL/PvdA inzake versnelling van het woningbouwprogramma. Spreektijd per fractie: 3 minuten.",
    "isRecurring": false
  },
  {
    "@self": { "register": "decidesk", "schema": "AgendaItem", "slug": "commissie-ruimte-bestemmingsplan-debat" },
    "title": "Bestemmingsplan Buitengebied Oost — debat",
    "itemType": "discussion",
    "orderNumber": 2,
    "estimatedDuration": 60,
    "description": "Oordeelsvormend debat over het ontwerpbestemmingsplan Buitengebied Oost. Spreektijd per raadslid: 4 minuten, tweede termijn 2 minuten.",
    "isRecurring": false
  },
  {
    "@self": { "register": "decidesk", "schema": "AgendaItem", "slug": "ab-vergadering-begroting-toelichting" },
    "title": "Toelichting Begroting 2027 door dijkgraaf",
    "itemType": "informational",
    "orderNumber": 4,
    "estimatedDuration": 20,
    "description": "Presentatie van de kaderbrief begroting 2027 door dijkgraaf Van der Berg. Geen spreektijd voor leden, enkel vragenronde (2 min per lid).",
    "isRecurring": false
  },
  {
    "@self": { "register": "decidesk", "schema": "AgendaItem", "slug": "digitale-raad-mei-sluiting" },
    "title": "Sluiting vergadering",
    "itemType": "informational",
    "orderNumber": 10,
    "estimatedDuration": 2,
    "description": "Voorzitter sluit de vergadering en dankt de deelnemers.",
    "isRecurring": true
  }
]
```
