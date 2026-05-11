<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: p2-meeting-management (Meeting Management)
     This spec extends the existing `p2-meeting-management` capability. Do NOT define new entities or build new CRUD — reuse what `p2-meeting-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

## Deduplication Check (ADR-012)

- [ ] 0.1 Confirm no custom CRUD, export, file, notification, or audit code is needed: all use `ObjectService`, `ExportService`, `FileService`, `NotificationService`, `AuditTrailService` from OpenRegister platform
- [ ] 0.2 Confirm `Meeting`, `Participant`, `ActionItem`, `Speech`, and `Area` entities are used as-is from ADR-000 — no schema properties added or renamed; `excused` tag uses the built-in `tags` array on Participant
- [ ] 0.3 Confirm `AttendanceService` is the only net-new PHP service class — virtual-only validation, participant pagination, space indicator, speech recognition, area map, and task claim all use existing platform capabilities with no custom server logic beyond `AttendanceService`
- [ ] 0.4 Confirm `ObjectService.lockObject()` / `unlockObject()` are used for task claim — no custom locking mechanism implemented
- [ ] 0.5 Confirm PDOK WMS tile is used for area map — no custom GIS backend, no polygon storage, no new controller

## 1. Backend — AttendanceService

- [ ] 1.1 Create `lib/Service/AttendanceService.php` — stateless service with `@spec openspec/changes/p2-meeting-management-other-t2/tasks.md#task-1` with the following public methods:
  - `markJoined(string $participantId, string $meetingId, string $actorId): array` — authorizes via `authorize()`; sets `joinedAt` to `new \DateTime('now', new \DateTimeZone('UTC'))` formatted as ISO 8601 on the Participant via `ObjectService::updateFromArray($participantId, ['joinedAt' => $now], patch: true)`; sends a `NotificationService` notification to the Meeting chair; returns updated Participant array
  - `markLeft(string $participantId, string $meetingId, string $actorId): array` — authorizes; sets `leftAt` to current UTC timestamp via patch; returns updated Participant array
  - `markExcused(string $participantId, string $meetingId, string $actorId): array` — authorizes; fetches Participant via `ObjectService`; adds tag `excused` to the `tags` array if not already present; saves via `ObjectService.saveObject()`; returns updated Participant array
  - `markUnexcused(string $participantId, string $meetingId, string $actorId): array` — authorizes; fetches Participant; removes tag `excused` from `tags` array; saves; returns updated Participant array
  - `authorize(string $actorId, string $meetingId, ?string $targetParticipantId = null): void` — fetches the actor's Membership for the GovernanceBody linked to the Meeting via `ObjectService.findAll()`; allows if actor role is `chair` or `secretary`, OR if `$targetParticipantId` equals the actor's own Participant UUID (self-check-in); throws `\OCP\AppFramework\Http\DataResponse` 403 otherwise
- [ ] 1.2 Add `@spec openspec/changes/p2-meeting-management-other-t2/tasks.md#task-1.1` to class docblock and every public method in `AttendanceService.php`
- [ ] 1.3 Register `AttendanceService` in `lib/AppInfo/Application.php` DI container (Nextcloud auto-wires constructor-injected services; confirm `ObjectService`, `NotificationService` are available)

## 2. Backend — AttendanceController

- [ ] 2.1 Create `lib/Controller/AttendanceController.php` — thin controller (< 10 lines per method) annotated `@NoAdminRequired` with `@spec openspec/changes/p2-meeting-management-other-t2/tasks.md#task-2`:
  - `attendance(string $meetingId, string $participantId): JSONResponse` — reads `action` from request body (`join`, `leave`, `excuse`, `unexcuse`); dispatches to corresponding `AttendanceService` method; returns 200 with updated Participant or 403 if unauthorized or 400 if unknown action
- [ ] 2.2 Add `@spec` to class docblock and `attendance()` method
- [ ] 2.3 Register route in `appinfo/routes.php`:
  - `POST /api/meetings/{meetingId}/attendance/{participantId}` → `attendance#attendance`
  Ensure this specific route appears before any wildcard `{slug}` routes
- [ ] 2.4 Register `AttendanceController` in `lib/AppInfo/Application.php`

## 3. Tests — PHPUnit

- [ ] 3.1 Create `tests/Unit/Service/AttendanceServiceTest.php` with `@spec openspec/changes/p2-meeting-management-other-t2/tasks.md#task-3` covering at minimum:
  - `testMarkJoinedSetsJoinedAt`: valid secretary actor → Participant `joinedAt` is set to current timestamp
  - `testMarkJoinedSelfCheckIn`: actor is own Participant UUID → allowed without secretary/chair role
  - `testMarkJoinedUnauthorized`: regular member marks another Participant → 403 thrown
  - `testMarkExcusedAddsTag`: secretary marks Participant excused → `excused` in `tags` array
  - `testMarkExcusedIdempotent`: marking excused when already excused → `tags` array has single `excused` entry
  - `testMarkUnexcusedRemovesTag`: secretary removes excused → `excused` not in `tags`
  - `testMarkLeftSetsLeftAt`: valid actor → Participant `leftAt` is set
- [ ] 3.2 Create `tests/Unit/Controller/AttendanceControllerTest.php` with `@spec` covering at minimum:
  - `testJoinActionReturns200`: valid `action: join` → HTTP 200 with updated Participant
  - `testUnknownActionReturns400`: `action: "fly"` → HTTP 400
  - `testUnauthorizedReturns403`: service throws 403 → controller returns HTTP 403

## 4. Frontend — VirtualMeetingJoin Component

- [ ] 4.1 Create `src/components/VirtualMeetingJoin.vue` — receives `meeting` object as prop; renders a primary `NcButton` labelled "Deelnemen aan vergadering" with an external-link icon only when `meeting.meetingMode === 'virtual-only'` and `meeting.location` starts with `https://`; clicking opens `meeting.location` in a new browser tab (`window.open(url, '_blank', 'noopener,noreferrer')`); add ARIA label "Deelnemen aan digitale vergadering" for screen readers
- [ ] 4.2 Create `src/components/VirtualMeetingBadge.vue` — receives `meetingMode` string as prop; renders a `CnStatusBadge` with label "Digitaal" and type `"success"` when `meetingMode === 'virtual-only'`; renders nothing otherwise
- [ ] 4.3 Add `@spec openspec/changes/p2-meeting-management-other-t2/tasks.md#task-4.1` JSDoc comment to both components
- [ ] 4.4 Add frontend form validator in `MeetingDetail.vue` (or the Meeting form component): when `meetingMode` is set to `virtual-only`, validate that `location` starts with `https://`; show inline error "Een videoconferentie-URL is verplicht voor digitale vergaderingen" if not; block form submission

## 5. Frontend — MeetingSpaceIndicator Component

- [ ] 5.1 Create `src/components/MeetingSpaceIndicator.vue` — receives `quorumRequired` (number or null) and `participantCount` (number) as props; computes `filledRatio = participantCount / quorumRequired`; renders a `CnStatusBadge` with label `"{participantCount} / {quorumRequired} deelnemers"` coloured: green (`type="success"`) when filledRatio < 0.8, amber (`type="warning"`) when 0.8 ≤ filledRatio < 1.0, red (`type="error"`) when filledRatio ≥ 1.0; renders nothing when `quorumRequired` is null or 0
- [ ] 5.2 Add `@spec openspec/changes/p2-meeting-management-other-t2/tasks.md#task-5.1` JSDoc comment

## 6. Frontend — AttendanceTracker Component

- [ ] 6.1 Create `src/components/AttendanceTracker.vue` — receives `meeting` object and `participants` array as props; renders a table with columns: "Naam", "Rol", "Fractie", "Aankomst", "Vertrek", "Status", "Acties"; for each Participant row: shows `joinedAt` (formatted HH:mm or "—"), `leftAt` (formatted HH:mm or "—"), presence status badge (Aanwezig / Afwezig / Verontschuldigd), and action buttons; action buttons shown based on current user role (secretary/chair see all; others see only own row)
- [ ] 6.2 Wire attendance action buttons in `AttendanceTracker.vue`: "Aanwezig melden" calls `POST /api/meetings/{meetingId}/attendance/{participantId}` with body `{ action: "join" }`; "Afmelden" calls with `{ action: "leave" }`; "Verontschuldigd" calls with `{ action: "excuse" }`; "Verontschuldigd intrekken" calls with `{ action: "unexcuse" }`; wrap each `await` call in `try/catch` with `NcDialog` error feedback (ADR-004)
- [ ] 6.3 Add attendance summary line to `AttendanceTracker.vue` header: "aanwezig: N | afwezig: M | verontschuldigd: K" computed from the participants array
- [ ] 6.4 Add `@spec openspec/changes/p2-meeting-management-other-t2/tasks.md#task-6.1` JSDoc comment

## 7. Frontend — SpeechRecognitionPanel Component

- [ ] 7.1 Create `src/components/SpeechRecognitionPanel.vue` — receives `meeting` object and `activeAgendaItem` object as props; on `mounted()` checks for `window.SpeechRecognition || window.webkitSpeechRecognition`; if unavailable renders `CnEmptyState` with message "Spraakherkenning is niet beschikbaar in deze browser. Gebruik Chrome of Edge."; if available and meeting lifecycle is not `opened`, renders a disabled button with tooltip; if available and meeting `opened`, renders start / stop buttons and a live transcript textarea
- [ ] 7.2 Implement session logic in `SpeechRecognitionPanel.vue`: on "Spraakherkenning starten" click, create a `SpeechRecognition` instance with `continuous: true`, `interimResults: false`, `lang: 'nl-NL'`; subscribe to `onresult` to append `event.results[i][0].transcript` to a local `transcript` data string; on "Spraakherkenning stoppen" or when `activeAgendaItem` changes, call `recognition.stop()` and save the transcript as a Speech object via `objectStore.saveObject('speech', { text: this.transcript, startDate: this.startTime, endDate: new Date().toISOString(), role: 'chair' })` with OpenRegister relations to `activeAgendaItem.id` and `meeting.id`; clear the transcript after save
- [ ] 7.3 Add `@spec openspec/changes/p2-meeting-management-other-t2/tasks.md#task-7.1` JSDoc comment

## 8. Frontend — AttendanceZoneMap Component

- [ ] 8.1 Install `ol` (OpenLayers) as a project dependency: `npm install ol` — verify no version conflict with existing Nextcloud dependencies
- [ ] 8.2 Create `src/components/AttendanceZoneMap.vue` — receives `meeting` object and `areas` array as props; on `mounted()` finds an Area whose `identifier` exactly matches `meeting.location`; if no match found renders nothing; if match found initialises an `ol/Map` in a container div with an `ol/layer/Tile` using `ol/source/TileWMS` pointing to `https://service.pdok.nl/cbs/gebiedsindelingen/wms/v1_0` with params `{ LAYERS: 'gemeenten', CQL_FILTER: "statcode='" + area.identifier + "'", TILED: true }`; sets map view to `ol/View` with projection `EPSG:4326` and center derived from the Area classification (municipality: zoom 12, province: zoom 9); renders Area `name` in a `CnDetailCard` header above the map
- [ ] 8.3 Add a "Bekijk op PDOK" external link below the map pointing to `https://pdok.nl` in a new tab (`noopener,noreferrer`)
- [ ] 8.4 Add `@spec openspec/changes/p2-meeting-management-other-t2/tasks.md#task-8.1` JSDoc comment
- [ ] 8.5 Scope all CSS in `AttendanceZoneMap.vue` with `<style scoped>`; set map container height via CSS variable `var(--body-container-margin)` fallback to `300px`

## 9. Frontend — SharedTaskInbox View

- [ ] 9.1 Create `src/views/SharedTaskInbox.vue` — route `/action-items/inbox`; uses `useListView('action-item', { sidebarState })` with pre-applied filters `{ assignee: '', taskStatus: 'open' }` (matching null or empty assignee); renders a `CnDataTable` with columns: title, description (truncated to 100 chars), due date, age (days since `createdAt`); sorted by `dueDate` ascending
- [ ] 9.2 Add "Claimen" action button to each row in `SharedTaskInbox.vue`:
  - On click: call `objectStore.lockObject(actionItemId)` — if successful, call `objectStore.saveObject('action-item', { ...item, assignee: currentUserDisplayName })`, then `objectStore.unlockObject(actionItemId)` in `finally`
  - On lock failure: show `NcDialog` with message `t(appName, 'This task has already been claimed by {name}', { name: item.assignee })` and a "Sluiten" button; refresh the row to show the updated assignee
  - Use `try/catch` wrapping every `await` call with user-facing error feedback (ADR-004)
  - Optimistic update: remove the item from the local list immediately after successful lock acquisition; revert if save fails
- [ ] 9.3 Add "Actiepunten inbox" navigation item to `MainMenu.vue` with route `{ name: 'SharedTaskInbox', path: '/action-items/inbox' }` and an inbox MDI icon; visible to all authenticated users
- [ ] 9.4 Register route in `src/router/index.js`: `{ name: 'SharedTaskInbox', path: '/action-items/inbox', component: SharedTaskInbox }`
- [ ] 9.5 Add `@spec openspec/changes/p2-meeting-management-other-t2/tasks.md#task-9.1` JSDoc comment to the view

## 10. Frontend — MeetingDetail Integration

- [ ] 10.1 Import and render `VirtualMeetingJoin` and `VirtualMeetingBadge` in `MeetingDetail.vue`: place `VirtualMeetingJoin` in the meeting header actions area below the lifecycle buttons; place `VirtualMeetingBadge` next to the meeting type label; pass `meeting` (or `meetingMode`) as props
- [ ] 10.2 Import and render `MeetingSpaceIndicator` in the `MeetingDetail.vue` header section; pass `quorumRequired` from the meeting object and `participantCount` from the fetched participants array length
- [ ] 10.3 Add client-side pagination to the Participants section of `MeetingDetail.vue`: introduce `participantPage` (default 1) and `pageSize` (default 10) data properties; compute `paginatedParticipants = participants.slice((participantPage - 1) * pageSize, participantPage * pageSize)`; render `CnPagination` with `total`, `current-page`, and `page-size` props; show "Toon alle (N)" toggle that sets `pageSize` to `Infinity` and resets on "Toon minder"
- [ ] 10.4 Import and render `AttendanceTracker` in `MeetingDetail.vue` below the participant list; pass `meeting` and the full `participants` array as props; ensure it only appears when Meeting lifecycle is `opened` or `closed`
- [ ] 10.5 Import and render `AttendanceZoneMap` in `MeetingDetail.vue` in a `CnDetailCard` below the main details section; pass `meeting` and the preloaded `areas` array from `objectStore.findAll('area')` (fetched on page mount alongside other data); ensure the card is hidden when `AttendanceZoneMap` finds no matching Area (use a `v-if` on a computed `hasAreaMatch` prop)
- [ ] 10.6 Add Speech transcripts section to `MeetingDetail.vue`: query Speech objects linked to this Meeting via `objectStore.findAll('speech', { relations: { meeting: meetingId } })`; render in a `CnDetailCard` labelled "Toespraken"; each row shows: start time, role badge, transcript preview (first 200 chars); clicking a row expands the full transcript in a modal or inline

## 11. Frontend — LiveMeeting Integration

- [ ] 11.1 Import and render `SpeechRecognitionPanel` in `LiveMeeting.vue` (delivered by p2-agenda-management); pass `meeting` and the currently active agenda item (`activeAgendaItem`) as props; place the panel in the chair/secretary-only control area (alongside the BOB phase panel and hamerstukken controls)

## 12. AgendaItemDetail Integration

- [ ] 12.1 Add "Bijdragen" section to `AgendaItemDetail.vue` (if it exists) or `AgendaBuilder.vue` item detail panel: query Speech objects linked to this AgendaItem via `objectStore.findAll('speech', { relations: { agendaItem: agendaItemId } })`; render with columns: tijdstip, rol, tekst (truncated, expandable)

## 13. Seed Data

- [ ] 13.1 Add seed objects to `lib/Settings/decidesk_register.json` under `components.objects[]` for the following schemas (using the `@self` envelope format from the design.md Seed Data section):
  - 3 Meeting objects (virtual-only, hybrid, in-person with area identifier in `location`)
  - 4 Participant objects (one present, one absent, one excused, one secretary)
  - 3 ActionItem objects (two unclaimed, one claimed)
  - 3 Speech objects (opening, VVD bijdrage, wethouder antwoord)
  - 3 Area objects (Gemeente Delft `0503`, Gemeente Amsterdam `0363`, Provincie Zuid-Holland `PV28`)
- [ ] 13.2 Verify idempotency: re-importing with `force: false` does not create duplicate objects; all seed objects matched by `slug` via `ObjectService::searchObjects()` per ADR seed rules

## 14. Verification

- [ ] 14.1 Run `composer check:strict` — all PHP quality checks pass
- [ ] 14.2 Run `npm run lint` — ESLint passes with no errors
- [ ] 14.3 Verify virtual-only meeting: create a Meeting with `meetingMode: virtual-only` and a valid `https://` URL in `location` → "Deelnemen aan vergadering" button appears; create another without a URL → validation error shown; create an `in-person` meeting → no join button visible
- [ ] 14.4 Verify participant list pagination: add >10 Participants to a Meeting → list shows 10; "Toon alle (N)" expands; `CnPagination` control navigates pages
- [ ] 14.5 Verify space indicator: set `quorumRequired: 10`, add 8 participants → green badge; add to 9 → amber; add to 10 → red; set `quorumRequired: null` → badge hidden
- [ ] 14.6 Verify attendance tracking: mark a Participant as joined → `joinedAt` persists in OpenRegister; mark excused → `excused` tag appears; secretary sees all rows; regular member sees only own check-in button
- [ ] 14.7 Verify speech recognition (Chrome/Edge): open LiveMeeting view on an `opened` Meeting → "Spraakherkenning starten" button visible; start session → transcript area shows live text; stop session → Speech object created and visible in MeetingDetail "Toespraken" section; open in Firefox → `CnEmptyState` shown
- [ ] 14.8 Verify area map: create a Meeting with `location: "0503"` and seed Area with `identifier: "0503"` → map tile appears in "Vergadergebied" card; create Meeting with `location: "https://..."` → no map card shown
- [ ] 14.9 Verify task inbox: navigate to `/action-items/inbox` → only unclaimed ActionItems shown; click "Claimen" → item moves to personal task list; open second browser session and claim same item → double-claim dialog shows claimant name
- [ ] 14.10 Verify WCAG AA: all new interactive elements are reachable via Tab; focus rings visible; all buttons have accessible labels; `CnEmptyState` speech panel readable by screen reader
