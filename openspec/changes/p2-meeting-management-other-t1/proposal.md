## Why

Governance bodies — municipal councils, water boards, corporate boards, and associations — generate over 1,600 market signals for two unimplemented capabilities that sit directly above the meeting lifecycle foundation delivered in p2-meeting-management. **Digital Council Meetings** (demand 897, 296 tender mentions) tops the entire p2-meeting-management feature list: every Dutch municipality that adopted digital or hybrid sessions since 2020 now expects a governance platform to make video participation a first-class experience, not a bolt-on link buried in a description field. **Enforce speaking time rules for council/parliament debates** (demand 729, 243 tender mentions) is the second-highest, directly requested by presiding officers and council clerks who spend meeting time managing speaker queues manually on paper. The third feature, **Indefinite Recurring Meeting Configuration** (demand 46, 15 tender mentions), addresses the operational reality that governance bodies meet on fixed schedules (weekly MT, monthly raadsvergadering, quarterly RvC) and should not need to create each instance by hand.

Without these capabilities, clerks run digital meetings by pasting a Teams or Jitsi link into a calendar event, debate chairs manage speaker queues on paper and enforce time limits by hand-watching a phone timer, and meeting organizers clone previous meeting records each month. These manual workflows cause missed participants, unequal speaking time, and scheduling errors — all of which lead to formal governance complaints. The Board Secretary / Company Secretary and the presiding officer (council chair, dijkgraaf, RvC chair) are the primary stakeholders who will benefit directly.

## What Changes

- **New**: Digital Council Meeting support — when `Meeting.meetingMode` is `digital` or `hybrid`, the Meeting detail page displays the `location` field as a prominent "Deelnemen" action button with a video conference icon; a "Live" badge appears when lifecycle is `opened`; a read-only `MeetingLiveView` public page shows the current agenda state for citizens and press without requiring a Nextcloud account; the active AgendaItem is marked via the OpenRegister built-in `status` field (`current`) and highlighted in the agenda list
- **New**: Speaking time management — a `SpeakingTimePanel.vue` component (visible when Meeting lifecycle is `opened`) provides: a countdown clock that reads `AgendaItem.estimatedDuration` as the speaking time budget for the current item; a speaker queue managed by the chair (add, move, remove participants by display name from the CalDAV ATTENDEE list); time balance bars showing total speaking time per participant accumulated during the session; configurable default speaking time per meeting session
- **New**: Indefinite recurring meeting configuration — `CalDavService` is extended to read and write the CalDAV `RRULE` property in VEVENTs; the Meeting create/edit form gains a "Herhaling" recurrence panel for frequency (weekly, bi-weekly, monthly, quarterly), day of week/month, and end condition (indefinite, until a date, maximum occurrences); the `X-DECIDESK-SERIES` property links instances; the Meeting index gains a "Reeks" filter to display all meetings in a series

## Capabilities

### New Capabilities

- `digital-meeting-support`: Join meeting action button for `digital` and `hybrid` meetings rendered from `Meeting.location`; public live view page (`/apps/decidesk/meetings/{id}/live`) showing current lifecycle, active agenda item, and remaining time without authentication; "Live" lifecycle badge on Meeting list and detail when lifecycle is `opened`; chair and secretary can set the current AgendaItem via the OpenRegister built-in `status` field
- `speaking-time-management`: Per-item speaking time countdown clock driven by `AgendaItem.estimatedDuration`; speaker queue with chair controls (add by name, reorder, remove, skip); cumulative time balance bars per participant for session-level fairness tracking; visual indicators for overrun (red countdown using Nextcloud CSS variables — no hardcoded colours)
- `recurring-meeting-configuration`: CalDAV RRULE support for standard recurrence frequencies (FREQ=WEEKLY, FREQ=MONTHLY with BYDAY/BYMONTHDAY); indefinite recurrence or UNTIL/COUNT bounds; `X-DECIDESK-SERIES` property set on all instances; series filter in Meeting index using the `series` field on the OpenRegister wrapper; series identifier shown on Meeting detail page

### Modified Capabilities

- `meeting-lifecycle` *(from p2-meeting-management)*: Extended to fire a series-update action when lifecycle transitions to `closed` on a recurring instance — the next instance in the series is pre-populated and displayed in the meeting list as `scheduled`
- `meeting-crud` *(from p1-crud-operations)*: Meeting create/edit form extended with a "Herhaling" recurrence panel and a "Deelname" tab showing the video link prominently; Meeting detail page extended with `SpeakingTimePanel` section when lifecycle is `opened`

## Impact

- No schema changes — `Meeting` (CalDAV VEVENT + OpenRegister wrapper), `AgendaItem`, and `GovernanceBody` from ADR-000 are used as-is
- RRULE is a standard CalDAV VEVENT property — no new X-DECIDESK-* extension; `CalDavService` extended to read/write `RRULE` and expose it via the Meeting API response
- Live agenda item tracking uses the OpenRegister built-in `status` field on `AgendaItem` (values: `upcoming`, `current`, `completed`) — no schema change
- Speaking time state is session-local in the Vue component; no new backend API or persistence required for the clock or queue for T1
- New route: `GET /api/meetings/{id}/live` (`#[PublicPage] #[NoCSRFRequired]`) returns current meeting state (lifecycle, current agenda item, title, meetingMode, location) for the public live view
- New route: `POST /api/meetings/{id}/agenda-items/{itemId}/set-current` sets the active AgendaItem via `ObjectService`
- Frontend: new components `SpeakingTimePanel.vue`, `SpeakingTimeClock.vue`, `SpeakerQueue.vue`, `TimeBalanceBar.vue`, `MeetingVideoLink.vue`, `MeetingLiveView.vue`, `RecurrencePanel.vue`
- Downstream: `p3-ori-publication` reads Meeting instances from CalDAV; recurring instances appear as individual ORI events with the same series identifier
