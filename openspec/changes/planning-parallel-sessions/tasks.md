# Tasks: planning-parallel-sessions

## Implementation tasks

### Task 1: A session points at its evening
- **spec_ref**: `openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-001-an-evening-holds-parallel-sessions-and-each-session-is-a-meeting`
- **files**: `lib/Settings/register.d/121-parallel-sessions.json`, `lib/Settings/profiles/municipality.json`, `lib/Listener/ParallelSessionListener.php`, `lib/AppInfo/Registrar/SaveGuardSubscriptions.php` (the pre-save guards ObjectListenerRegistrar subscribes), `tests/Unit/Listener/ParallelSessionListenerTest.php`
- **acceptance_criteria**:
  - GIVEN the register WHEN imported THEN `meeting` carries `parentMeeting` and `room`
  - GIVEN a session of a session WHEN saved THEN 422 "A session cannot have sessions of its own"
  - GIVEN a session at 23:30 of an evening ending at 23:00 WHEN saved THEN 422 naming the evening's times
  - GIVEN a new session without a body WHEN saved THEN it takes the evening's body, publicity and mode
- [x] Implement
- [x] Test (real OpenRegister events, red first): ParallelSessionListenerTest, 9 tests

### Task 2: Sessions side by side on the evening's page
- **spec_ref**: `openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side`
- **files**: `src/components/tabs/MeetingSessionsTab.vue`, `src/utils/meetingSessions.js`, `src/registry.js`, `src/icons.js`, `src/manifest.json` (`MeetingDetail` widget, layout row and slot), `tests/vitest/meetingSessionsTab.spec.js`, `tests/e2e/parallel-sessions.spec.ts`
- **acceptance_criteria**:
  - GIVEN the example evening WHEN the griffier opens it THEN three columns show room, chair, time and the first agenda items per session
  - GIVEN a session's page WHEN opened THEN it names its evening and links the other two sessions
  - GIVEN the secretariat WHEN it chooses Add session THEN the create form opens with the evening, date and body preset
- [x] Implement
- [ ] Test (Playwright: tests/e2e/parallel-sessions.spec.ts written, not run; the columns, siblings and presets are covered by vitest meetingSessionsTab.spec.js)

### Task 3: The calendar and the list group sessions
- **spec_ref**: `openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-003-the-calendar-and-the-meetings-list-group-sessions-under-their-evening`
- **files**: `src/views/meetings/MeetingCalendarView.vue`, `src/utils/meetingSessions.js`, `tests/vitest/meetingCalendarSessions.spec.js`. The `Meetings` facet needs no manifest change: the index sidebar builds its facets from the schema's facetable properties, and `parentMeeting` is facetable.
- **acceptance_criteria**:
  - GIVEN the example evening WHEN the calendar renders 3 November THEN one event shows with its three sessions inside it
  - GIVEN the Meetings list WHEN filtered on the evening THEN the three sessions are listed
- [x] Implement
- [ ] Test (vitest on the grouping: done, meetingCalendarSessions.spec.js; Playwright on the calendar: written, not run)

### Task 4: Residents see the evening's broadcasts together
- **spec_ref**: `openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-004-each-sessions-broadcast-names-its-evening-for-residents`
- **files**: `lib/Settings/register.d/121-parallel-sessions.json` (adds `eveningTitle` to the `MeetingBroadcast` schema of `live-public-livestream`), `lib/Service/MeetingBroadcastService.php`, `lib/Portal/PortalContributionProvider.php`, `tests/Unit/Service/MeetingBroadcastServiceTest.php`, `tests/Unit/Portal/PortalContributionProviderTest.php`
- **acceptance_criteria**:
  - GIVEN a broadcast created for a session WHEN saved THEN `eveningTitle` is the evening's title
  - GIVEN the portal contribution WHEN read THEN `publicBroadcasts` carries `eveningTitle`
- [x] Implement (after `live-public-livestream`)
- [x] Test

## Verification

- `composer check:strict` and `npm run lint` once before push.
