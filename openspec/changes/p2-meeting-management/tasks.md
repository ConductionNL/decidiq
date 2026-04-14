## 1. Backend — Meeting Service

- [x] 1.1 Create `lib/Service/MeetingService.php` with lifecycle transition logic: define valid transitions map, `transitionLifecycle(meetingId, transition)` method that validates the transition is allowed from the current state, enforces chair/secretary authorization, saves the new lifecycle state, and sends notifications to active participants
- [x] 1.2 Add `getUserRole(meetingId)` method to MeetingService that returns the calling user's participant role for the meeting's governance body (reuse getActiveParticipants pattern from AgendaService)
- [x] 1.3 Add private helper methods: `getObjectService()`, `getNotificationService()`, `getActiveParticipants()`, `assertChairOrSecretary()` — following the same lazy-load container pattern as AgendaService

## 2. Backend — Meeting Controller

- [x] 2.1 Create `lib/Controller/MeetingController.php` — thin controller with `transitionLifecycle(id)` method that reads `transition` from request params and delegates to MeetingService; returns JSONResponse with proper HTTP status codes (400/403/404/503)
- [x] 2.2 Add `userRole(id)` endpoint to MeetingController — delegates to MeetingService.getUserRole(), returns `['role' => '...']`

## 3. Backend — Wiring

- [x] 3.1 Register MeetingService and MeetingController in `lib/AppInfo/Application.php` with explicit DI factory bindings
- [x] 3.2 Add routes to `appinfo/routes.php`: `PUT /api/meetings/{id}/lifecycle` and `GET /api/meetings/{id}/user-role` — before the SPA catch-all

## 4. Frontend — Meeting Store

- [x] 4.1 Create `src/store/modules/meeting.js` — Pinia store with `transitionLifecycle(meetingId, transition)` and `fetchUserRole(meetingId)` actions that call the backend API endpoints

## 5. Frontend — Meeting List View

- [x] 5.1 Replace placeholder `src/views/MeetingList.vue` with CnIndexPage implementation using `useListView('meeting', ...)` composable; columns: title, meetingType, scheduledDate, meetingMode, lifecycle; row click navigates to MeetingDetail

## 6. Frontend — Meeting Detail View

- [x] 6.1 Replace placeholder `src/views/MeetingDetail.vue` with CnDetailPage implementation using CnDetailCard sections for meeting properties (title, type, dates, location, mode, lifecycle, quorum, series)
- [x] 6.2 Add lifecycle transition controls: buttons for valid transitions from current state (e.g., "Open meeting" when scheduled, "Pause" / "Adjourn" / "Close" when opened); each button calls meetingStore.transitionLifecycle() and refreshes the detail view
- [x] 6.3 Add CnObjectSidebar for files, notes, and audit trail tabs
- [x] 6.4 Add Edit button using CnFormDialog and Delete button using CnDeleteDialog in header actions

## 7. Frontend — Navigation

- [x] 7.1 Add Meetings navigation item to `src/navigation/MainMenu.vue` with CalendarBlank icon, linking to MeetingList route

## 8. Testing

- [x] 8.1 Create `tests/Unit/Service/MeetingServiceTest.php` with ≥3 test methods: test valid lifecycle transition succeeds, test invalid transition throws RuntimeException, test non-chair user gets 403 on lifecycle transition
- [x] 8.2 Create `tests/Unit/Controller/MeetingControllerTest.php` with ≥3 test methods: test transitionLifecycle returns success JSON, test transitionLifecycle returns 403 for unauthorized user, test userRole returns role JSON

## 9. Quality

- [x] 9.1 Ensure all new PHP files have SPDX-License-Identifier header and @spec PHPDoc tags
- [x] 9.2 Ensure all user-visible strings use `t('decidesk', '...')` (frontend) or `$this->l10n->t(...)` (backend)
- [x] 9.3 Run `composer check:strict` — fix any issues
- [x] 9.4 Run `npm run lint` — fix any issues
