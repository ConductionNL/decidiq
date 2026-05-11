<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: p2-meeting-management (Meeting Management)
     This spec extends the existing `p2-meeting-management` capability. Do NOT define new entities or build new CRUD — reuse what `p2-meeting-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

## Context

Decidesk is a thin-client Nextcloud app: all domain data is stored in OpenRegister. The `p2-meeting-management` change delivered the Meeting lifecycle state machine and the `MeetingLifecycle.vue` component. The `p2-agenda-management` change added the `AgendaBuilder.vue` and `LiveMeeting.vue` views. This T2 change adds seven operational enhancements on top of the meeting detail and live meeting views — all using existing ADR-000 entities (Meeting, Participant, ActionItem, Speech, Area) with no schema additions.

The primary users of these features are the **Board Secretary / Company Secretary** (tracks attendance, manages the shared task inbox, coordinates post-meeting follow-up), the **Meeting Chair** (controls speech recognition session, manages virtual-only meeting links), and **Participants** themselves (join via video link, see their attendance status, claim action items). The **Team Lead** and **MT Member / Manager** personas benefit most from the shared task inbox with double-claim prevention. **School board governance bodies** benefit from the attendance zone map.

## Goals / Non-Goals

**Goals:**
- Virtual-only meeting mode enforcement with "Deelnemen aan vergadering" join button
- Participant list pagination at 10-per-page in MeetingDetail using `CnPagination`
- Capacity badge (`quorumRequired` vs actual participant count) in MeetingDetail header
- Attendance marking (aanwezig / afgemeld / verontschuldigd) with `joinedAt` / `leftAt` timestamps and `excused` tag
- Browser-side Web Speech API session saving Speech objects per AgendaItem
- Area boundary map tile in MeetingDetail when `location` matches an Area `identifier`
- Shared ActionItem inbox with "Claimen" button and double-claim prevention via `ObjectService.lockObject()`

**Non-Goals:**
- Server-side ASR (Whisper, Azure Cognitive Services) — browser Web Speech API only for this spec; server-side ASR is a future AI spec
- Full ORI / PLOOI integration for Speech transcripts — deferred to p3-ori-publication
- GIS data management or Area polygon editing — deferred to p3-governance-bodies
- External video conferencing integration beyond URL launch (Teams/Zoom/Jitsi API) — future integration spec
- Persistent attendance register export to CSV/PDF — covered by `CnMassExportDialog` (platform built-in)
- Proxy attendance (attending on behalf of another member) — future spec
- Quorum counting and quorum-met notification — future spec (builds on attendance tracking)
- School boundary GIS polygon upload — future spec

## Decisions

### 1. Virtual-only mode enforces video URL in existing `location` field
**Decision**: When `meetingMode` is set to `virtual-only`, a frontend validator in `CnFormDialog` requires the `location` field to be a valid HTTPS URL. The `VirtualMeetingJoin.vue` component renders a "Deelnemen aan vergadering" `NcButton` in `MeetingDetail.vue` only when `meetingMode === 'virtual-only'` and `location` is a non-empty string starting with `https://`. Physical location validation (Dutch address format check) is suppressed for virtual-only meetings.
**Rationale**: The Meeting schema already has `location: string` and `meetingMode: string` from ADR-000. No schema changes needed. A frontend validator in the form provides immediate user feedback; backend enforcement is not needed because OpenRegister's schema validation allows any string for `location`. This approach matches the Wet digitaal vergaderen guidance that the video conferencing URL replaces the physical address for virtual meetings.
**Alternative considered**: Adding a separate `videoUrl` field to Meeting — rejected (ADR-000 is the authoritative schema source; adding fields requires an ADR-000 update; `location` already semantically covers the meeting venue, whether physical or virtual).

### 2. Participant list limit is pure frontend pagination
**Decision**: The participant list in `MeetingDetail.vue` is paginated using `CnPagination` with a default page size of 10. The list is sourced from `objectStore.findAll('participant', { filters: { meeting: id } })` which already returns all participants. Pagination slices the local array client-side. A "Toon alle (N)" link expands the list. The section header always shows the total count: "Deelnemers (N)".
**Rationale**: No server-side changes needed — the participant count per meeting is bounded (maximum governance bodies have ~50 members; Dutch municipal councils have at most 45 seats). Client-side slicing avoids an extra API endpoint. `CnPagination` is the platform-provided component (ADR-012). This satisfies the competitor-derived `meeting-participant-list-limit` requirement without adding backend complexity.
**Alternative considered**: Server-side pagination with `_page` + `_limit` — rejected (over-engineering for bounded participant counts; client-side is sufficient and avoids a new route).

### 3. Space indicator reads `quorumRequired` and current participant count
**Decision**: `MeetingSpaceIndicator.vue` computes `filledRatio = participantCount / quorumRequired` and renders a `CnStatusBadge`: green (filledRatio < 0.8), amber (0.8 ≤ filledRatio < 1.0), red (filledRatio ≥ 1.0). If `quorumRequired` is null or zero the indicator is hidden. No server-side endpoint — both values are available in the `MeetingDetail.vue` component state: `quorumRequired` from the Meeting object and `participantCount` from the participants array length.
**Rationale**: Both data points are already fetched when `MeetingDetail.vue` loads. A pure Vue computed property avoids an API round-trip and keeps the component stateless. The amber/red thresholds match standard capacity planning conventions used across Dutch governance venues.
**Alternative considered**: A dedicated `GET /api/meetings/{id}/capacity` endpoint — rejected (data is already fetched client-side; a separate endpoint would duplicate the participant count query and add an unnecessary network call).

### 4. Attendance tracked via `joinedAt`/`leftAt` + `excused` tag on Participant
**Decision**: `AttendanceService.php` exposes three operations via `AttendanceController.php`:
- **Mark present** (`POST /api/meetings/{meetingId}/attendance/{participantId}` with body `{ "action": "join" }`): sets `joinedAt` to current UTC timestamp via `ObjectService.updateFromArray($id, ['joinedAt' => $now], patch: true)`
- **Mark left** (same endpoint, body `{ "action": "leave" }`): sets `leftAt` to current UTC timestamp
- **Mark excused** (same endpoint, body `{ "action": "excuse" }`): adds tag `excused` to the Participant's built-in `tags` array

Authorization: only the secretary or chair of the linked GovernanceBody may mark others. A Participant may mark their own `joinedAt` (self-check-in). `AttendanceService::authorize()` checks the caller's Membership role via `ObjectService.findAll()`.
**Rationale**: ADR-000 defines `joinedAt` and `leftAt` on Participant specifically for attendance tracking. The built-in `tags` array covers excused status without a schema change. `AttendanceService` enforces authorization server-side per ADR-005 (never trust frontend-sent user IDs). A single controller endpoint with an `action` body param follows ADR-002 conventions (same pattern as `MeetingController::lifecycle()`).
**Alternative considered**: Separate `Attendance` entity with a presence enum — rejected (ADR-000 is authoritative; Participant already has `joinedAt` and `leftAt`; a new entity would require an ADR-000 update and migration).

### 5. Speech recognition uses browser Web Speech API; transcripts stored as Speech objects
**Decision**: `SpeechRecognitionPanel.vue` uses `window.SpeechRecognition` (or `window.webkitSpeechRecognition`). When the chair or secretary starts a session, the component listens for `result` events and appends recognised segments to a running transcript string. On session stop (or when the active AgendaItem changes), the panel creates a new Speech object via `objectStore.saveObject('speech', { text, startDate, endDate, role: 'chair' })` with OpenRegister relations to the active AgendaItem UUID and the Meeting UUID. If `SpeechRecognition` is not available (non-HTTPS context, Firefox, or unsupported browser), the panel renders a `CnEmptyState` with message "Spraakherkenning niet beschikbaar in deze browser".
**Rationale**: Server-side ASR requires external API keys and significant infrastructure — out of scope for T2. Browser Web Speech API is available in Chrome and Edge (the primary browsers for Dutch government deployments). The Speech entity in ADR-000 was designed for this exact use case with `text`, `startDate`, `endDate`, `audio`, `video`, `role` fields. Saving via `objectStore.saveObject()` requires no new backend controller.
**Alternative considered**: Integration with Azure Cognitive Services / OpenAI Whisper — deferred to a future AI spec; browser API is sufficient for v1 and adds no external dependencies or GDPR complications.

### 6. Area boundary map uses OpenLayers with PDOK WMS tile service
**Decision**: `AttendanceZoneMap.vue` fetches all Area objects via `objectStore.findAll('area')` on mount. It checks if the Meeting's `location` value exactly matches the `identifier` of any Area object (e.g., CBS gemeentecode `0503` for Delft). If a match is found, an OpenLayers map is initialised with a WMS tile layer from the PDOK REST API at `https://service.pdok.nl/cbs/gebiedsindelingen/wms/v1_0` using `LAYERS=gemeenten` and a CQL filter on the CBS code. The map is read-only and embedded in a `CnDetailCard` labelled "Vergadergebied". If no Area matches, the card is not rendered.
**Rationale**: PDOK provides free, publicly accessible WMS tiles for Dutch municipal, provincial, and waterboard boundaries with no authentication required — appropriate for a Dutch government platform. OpenLayers is the standard open-source GIS library used in Dutch government webapps (used by the VNG Realisatie reference implementations). The match-by-identifier approach keeps the logic simple and avoids a separate geocoding service. No new backend code needed.
**Alternative considered**: Leaflet.js with a Google Maps tile — rejected (Google Maps requires a paid API key and raises GDPR concerns for Dutch government data). Custom PDOK WFS polygon fetch — deferred (higher complexity, sufficient for v1 to use WMS tiles which PDOK optimises for rendering).

### 7. Shared task inbox uses object locking for double-claim prevention
**Decision**: The shared inbox view (`SharedTaskInbox.vue`) is a dedicated route `/action-items/inbox` showing ActionItems where `assignee` is null or empty and `taskStatus` is `"open"`. When a user clicks "Claimen", the frontend:
1. Calls `objectStore.lockObject(actionItemId)` — returns the lock token or throws if another session holds the lock
2. On success: calls `objectStore.saveObject('action-item', { ...item, assignee: currentUserDisplayName })`
3. Calls `objectStore.unlockObject(actionItemId)` in a `finally` block
4. On lock failure: shows a `NcDialog` toast: "Deze taak is al geclaimd door [assignee name]"

Claimed items disappear from the inbox immediately (optimistic update). The user's personal task list (filtered by `assignee === currentUserDisplayName`) gains the item.
**Rationale**: `ObjectService.lockObject()` / `unlockObject()` are OpenRegister built-in methods (ADR-012) that provide optimistic concurrency control without a custom database transaction or backend endpoint. The `assignee` field on ActionItem is the natural ownership field. Showing `assignee` name on lock failure matches the user story acceptance criteria ("shows who has claimed it").
**Alternative considered**: A dedicated `claimed_by` field — rejected (ADR-000 is authoritative; `assignee` already serves this purpose semantically; adding a field would require an ADR-000 update).

## Reuse Analysis (ADR-012)

| Capability | OpenRegister service / component used | Custom code |
|---|---|---|
| Virtual-only mode validation | `CnFormDialog` (schema-driven form, frontend validator) | `VirtualMeetingJoin.vue` (join button) |
| Participant list pagination | `CnPagination` (platform component) | `MeetingDetail.vue` (client-side slice, page state) |
| Space indicator | `CnStatusBadge` | `MeetingSpaceIndicator.vue` (computed ratio + badge) |
| Attendance marking | `ObjectService.saveObject()` (patch: true) | `AttendanceService.php`, `AttendanceController.php`, `AttendanceTracker.vue` |
| Attendance authorization | `AuthorizationService` (Membership role check) | Called from `AttendanceService::authorize()` |
| Speech recognition save | `objectStore.saveObject()` (Speech objects) | `SpeechRecognitionPanel.vue` (Web Speech API session) |
| Area map data | `objectStore.findAll('area')` | `AttendanceZoneMap.vue` (OpenLayers + PDOK WMS) |
| Task claim locking | `ObjectService.lockObject()` + `unlockObject()` | `SharedTaskInbox.vue` (claim button + lock flow) |
| Audit trail (all changes) | `AuditTrailService` (automatic on every `saveObject()`) | None |
| Notifications (attendance) | `NotificationService` (called from `AttendanceService`) | None additional |
| Export (attendance list) | `CnMassExportDialog` + `ExportService` (platform built-in) | None |
| Search / filter (task inbox) | `IndexService` + `CnFilterBar` (platform built-in) | None |

No new entities proposed. No new OpenRegister schemas. All net-new PHP code is in `AttendanceService.php` and `AttendanceController.php`. No overlap with existing OpenRegister core services beyond what is listed above.

## Seed Data (Dutch examples — meeting management extensions)

These objects supplement the seed data from `p1-schemas-and-data-model` and `p2-meeting-management`. No schema changes needed.

### Meeting (virtual-only and hybrid examples)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Meeting", "slug": "videoraad-delft-2026-05-14" },
    "title": "Digitale raadsvergadering 14 mei 2026",
    "meetingType": "regular",
    "scheduledDate": "2026-05-14T19:30:00Z",
    "endDate": "2026-05-14T22:00:00Z",
    "location": "https://teams.microsoft.com/l/meetup-join/gemeente-delft-raad-20260514",
    "meetingMode": "virtual-only",
    "lifecycle": "scheduled",
    "quorumRequired": 23
  },
  {
    "@self": { "register": "decidesk", "schema": "Meeting", "slug": "hybride-commissie-ruimte-2026-05-07" },
    "title": "Commissie Ruimte — hybride vergadering 7 mei 2026",
    "meetingType": "committee",
    "scheduledDate": "2026-05-07T14:00:00Z",
    "endDate": "2026-05-07T16:30:00Z",
    "location": "https://zoom.us/j/96452310987",
    "meetingMode": "hybrid",
    "lifecycle": "opened",
    "quorumRequired": 7
  },
  {
    "@self": { "register": "decidesk", "schema": "Meeting", "slug": "schoolraad-amsterdam-2026-04-22" },
    "title": "Medezeggenschapsraad De Waterlelie — vergadering 22 april 2026",
    "meetingType": "regular",
    "scheduledDate": "2026-04-22T19:00:00Z",
    "endDate": "2026-04-22T21:00:00Z",
    "location": "0363",
    "meetingMode": "in-person",
    "lifecycle": "closed",
    "quorumRequired": 10
  }
]
```

### Participant (attendance examples)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Participant", "slug": "participant-janssen-delft-raad" },
    "displayName": "Drs. A.H. Janssen",
    "role": "member",
    "party": "VVD",
    "email": "a.janssen@raad.delft.nl",
    "joinedAt": "2026-05-14T19:34:00Z",
    "leftAt": "2026-05-14T21:58:00Z",
    "votingWeight": 1
  },
  {
    "@self": { "register": "decidesk", "schema": "Participant", "slug": "participant-deruijter-delft-raad" },
    "displayName": "Mw. C. de Ruijter",
    "role": "member",
    "party": "D66",
    "email": "c.deruijter@raad.delft.nl",
    "joinedAt": null,
    "leftAt": null,
    "votingWeight": 1
  },
  {
    "@self": { "register": "decidesk", "schema": "Participant", "slug": "participant-bakker-delft-griffie" },
    "displayName": "Mr. P.J. Bakker",
    "role": "secretary",
    "party": null,
    "email": "p.bakker@griffie.delft.nl",
    "joinedAt": "2026-05-14T19:28:00Z",
    "leftAt": null,
    "votingWeight": 0
  },
  {
    "@self": { "register": "decidesk", "schema": "Participant", "slug": "participant-molenaar-excused" },
    "displayName": "Dhr. R. Molenaar",
    "role": "member",
    "party": "PvdA",
    "email": "r.molenaar@raad.delft.nl",
    "joinedAt": null,
    "leftAt": null,
    "votingWeight": 1
  }
]
```

### ActionItem (shared inbox — unclaimed items)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-bewonersbrief-rondweg-2026-06" },
    "title": "Bewonersbrief rondweg N470 opstellen",
    "description": "Informatiebrief opstellen voor bewoners over de geplande rondweg N470 — gereed vóór de inspraakperiode",
    "assignee": null,
    "dueDate": "2026-06-01",
    "taskStatus": "open",
    "completedAt": null
  },
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-subsidie-provinciefonds-2026" },
    "title": "Subsidieaanvraag Provinciefonds indienen",
    "description": "Aanvraagformulier invullen en indienen vóór de sluitingsdatum van het Provinciefonds Mobiliteit",
    "assignee": null,
    "dueDate": "2026-05-28",
    "taskStatus": "open",
    "completedAt": null
  },
  {
    "@self": { "register": "decidesk", "schema": "ActionItem", "slug": "actie-verkeersonderzoek-opdracht-2026" },
    "title": "Opdracht verkeersonderzoek Papsouwselaan uitzetten",
    "description": "Aanbestedingsstukken opstellen voor onafhankelijk verkeersonderzoek kruispunt Papsouwselaan / Voorhofdreef",
    "assignee": "Drs. A.H. Janssen",
    "dueDate": "2026-05-20",
    "taskStatus": "open",
    "completedAt": null
  }
]
```

### Speech (transcript examples)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Speech", "slug": "speech-raad-2026-04-01-opening" },
    "text": "Ik open de vergadering. Er zijn 29 leden aanwezig, waarmee het quorum is gehaald. Ik stel voor de agenda vast te stellen conform het rondgestuurde concept. Zijn er bezwaren? Dat is niet het geval. De agenda is vastgesteld.",
    "role": "chair",
    "startDate": "2026-04-01T19:30:15Z",
    "endDate": "2026-04-01T19:32:00Z",
    "audio": null,
    "video": null
  },
  {
    "@self": { "register": "decidesk", "schema": "Speech", "slug": "speech-raad-2026-04-01-vvd-bijdrage" },
    "text": "Voorzitter, de VVD-fractie steunt het voorstel voor de rondweg, maar wil graag een nadere toelichting op de financiering en de planning. Kan de wethouder aangeven wanneer de aanbesteding start?",
    "role": "member",
    "startDate": "2026-04-01T20:15:00Z",
    "endDate": "2026-04-01T20:17:30Z",
    "audio": null,
    "video": null
  },
  {
    "@self": { "register": "decidesk", "schema": "Speech", "slug": "speech-raad-2026-04-01-wethouder-antwoord" },
    "text": "Voorzitter, in antwoord op de vraag van de VVD-fractie: de financiering is gedekt vanuit het Mobiliteitsfonds en het gemeentelijk investeringsbudget 2026–2028. De aanbesteding is gepland voor het derde kwartaal van 2026.",
    "role": "member",
    "startDate": "2026-04-01T20:45:00Z",
    "endDate": "2026-04-01T20:47:45Z",
    "audio": null,
    "video": null
  }
]
```

### Area (attendance zone examples)

```json
[
  {
    "@self": { "register": "decidesk", "schema": "Area", "slug": "area-gemeente-delft" },
    "name": "Gemeente Delft",
    "identifier": "0503",
    "classification": "municipality"
  },
  {
    "@self": { "register": "decidesk", "schema": "Area", "slug": "area-gemeente-amsterdam" },
    "name": "Gemeente Amsterdam",
    "identifier": "0363",
    "classification": "municipality"
  },
  {
    "@self": { "register": "decidesk", "schema": "Area", "slug": "area-provincie-zuidholland" },
    "name": "Provincie Zuid-Holland",
    "identifier": "PV28",
    "classification": "province"
  }
]
```
