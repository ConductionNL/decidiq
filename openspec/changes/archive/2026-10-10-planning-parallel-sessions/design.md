# Design: planning-parallel-sessions

Read at decidiq development `c0b2f5bb`.

## What exists

| Piece | Where |
|---|---|
| Meeting | `lib/Settings/decidesk_register.json:757` `Meeting`: `title`, `meetingType`, `governanceBody`, `scheduledDate`, `endDate`, `location`, `meetingMode`, `lifecycle`, `chair`, `isPublic`, `series` (a string, used by recurrence), `submissionDeadline`; no parent or session |
| Agenda, minutes, decisions | `AgendaItem.meeting`, `Minutes.meeting`, `Decision.meeting` (`lib/Settings/register.d/67-model-debt-cleanup.json`): everything already hangs off one meeting |
| Meeting page | `src/manifest.json:561` `MeetingDetail`, edited directly per its own note because a `manifest.d` fragment would replace the page whole; widget `meeting-series` (`:581`, `MeetingSeriesTab`) handles recurrence |
| Meetings list and calendar | `src/manifest.json:129` `Meetings`; `:546` `MeetingsCalendar` (`src/views/meetings/MeetingCalendarView.vue:274` loads meetings with `getMeetings({ _limit: 500 })`) |
| Broadcast per meeting | `openspec/changes/live-public-livestream` (merged in batch 1): `MeetingBroadcast` (slug `meeting-broadcast`) per meeting, with subtitles from the meeting's transcript, reaching residents through the anonymous portal collection `publicBroadcasts` (its design D6) |
| Object events | `lib/AppInfo/Registrar/ObjectListenerRegistrar.php` (`ObjectCreatingEvent` and friends) |

## Approach

### Field

Fragment `lib/Settings/register.d/92-parallel-sessions.json` adds `Meeting.parentMeeting` (uuid, `$ref` Meeting, facetable, nullable) and `Meeting.room` (string, the session's room, shown beside `location`).

### Rules

`lib/Listener/ParallelSessionListener.php` on `ObjectCreatingEvent` and `ObjectUpdatingEvent` for schema `meeting` refuses:

- a `parentMeeting` that itself has a `parentMeeting` (no nesting);
- a session whose `scheduledDate` falls outside the parent's `scheduledDate` to `endDate`;
- a parent pointing at itself.

On creating a session without `governanceBody`, `isPublic` or `meetingMode`, it copies them from the parent.

### Screens

- `MeetingDetail` gets `meeting-sessions`, a custom widget `MeetingSessionsTab.vue` (the side-by-side view is a column per session, which the object-list primitive cannot draw): one column per session with title, room, chair, time, lifecycle, its first agenda items and, when a broadcast exists, its live status; each column links to the session's page. It has an Add session action for the secretariat, which opens the meeting create form with `parentMeeting`, the date and the body preset. On a session's own page the widget shows "Part of <evening>" with a link and the sibling sessions.
- `MeetingCalendarView` groups sessions under their parent: the parent renders as the event, its sessions as rows inside it; a session whose parent is not loaded renders on its own.
- `Meetings` list gets `parentMeeting` as a facet, so a clerk can list one evening's sessions.
- Public side: `live-public-livestream` gives residents the portal collection `publicBroadcasts` over `MeetingBroadcast` rows (its design D6). This change adds `eveningTitle` to `MeetingBroadcast`, filled from the parent meeting's title when a session's broadcast is created, and adds it to that collection's fields and sort, so an evening's sessions list together. The broadcast itself stays per session.

## Declarative or imperative

| Behaviour | Path | Why |
|---|---|---|
| The relation | Declarative schema field | Plain reference |
| No nesting, time inside the evening, inherited defaults | Imperative listener | Cross-object date and parent checks, which no dialect expresses |
| Side-by-side view | One custom widget | Columns per related object are not a manifest primitive |

## Seed data

Municipality example set: "Commissieavond 3 november" (18:00 to 23:00) with three sessions, "Commissie Ruimte" (room Raadzaal, 19:30), "Commissie Bestuur" (room Commissiekamer 1, 19:30) and "Commissie Samenleving" (room Commissiekamer 2, 19:30), each with two agenda items.

## Files

- `lib/Settings/register.d/92-parallel-sessions.json`, `lib/Settings/profiles/municipality.json`
- `lib/Listener/ParallelSessionListener.php`, `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`
- `lib/Portal/PortalContributionProvider.php` (`publicBroadcasts` fields), the `MeetingBroadcast` fragment from `live-public-livestream` (`eveningTitle`)
- `src/components/tabs/MeetingSessionsTab.vue`, `src/registry.js`, `src/manifest.json` (`MeetingDetail` widget, layout and slot; `Meetings` facet), `src/views/meetings/MeetingCalendarView.vue`
- `tests/Unit/Listener/ParallelSessionListenerTest.php`, `tests/vitest/meetingCalendarSessions.spec.js`, `tests/e2e/parallel-sessions.spec.ts`

## Built (5 Oct 2026)

- The fragment is `lib/Settings/register.d/121-parallel-sessions.json`, not `92-`: fragments merge in file-name order and the newest Meeting delta must come last so its version (1.11.0) wins. It also adds `MeetingBroadcast.eveningTitle` (0.2.0).
- The rules run in `ParallelSessionListener`, subscribed through `SaveGuardSubscriptions` on meeting creates and updates, after `MeetingDefaultsListener` so a type's body is not overwritten by the evening's. Beyond the three refusals planned here it also refuses a meeting that already has sessions from becoming a session (the other side of no nesting), a session whose evening does not exist, and a session that runs past the end of its evening.
- OpenRegister applies the schema default `isPublic: false` before the pre-save hook, so through the object API a session's publicity arrives filled and the listener cannot tell it was left empty. The Add session action therefore presets the evening's body, mode and publicity itself (`newSession` in `src/utils/meetingSessions.js`); the listener fills body and mode for any other caller.
- The `Meetings` facet needs no manifest change: the index sidebar builds facets from the schema's facetable properties.
- `eveningTitle` is in the `publicBroadcasts` fields; the default sort stays on `scheduledDate`, because an evening's sessions start at the same time and so already list together.

