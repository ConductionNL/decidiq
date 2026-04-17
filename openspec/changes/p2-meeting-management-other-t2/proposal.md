<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: p2-meeting-management (Meeting Management)
     This spec extends the existing `p2-meeting-management` capability. Do NOT define new entities or build new CRUD — reuse what `p2-meeting-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

## Why

Decidesk's meeting management foundation (p2-meeting-management) introduced the Meeting entity lifecycle and the core BOB workflow. The six features in this T2 tier address a distinct cluster of operational meeting needs identified through market research and competitive analysis: participants joining virtual-only sessions, venue capacity awareness, post-meeting attendance recording, speech-to-text transcription, geographic attendance zone visibility for school-governance bodies, and a shared task inbox with claim prevention to eliminate duplicate work.

Virtual-Only Meeting Support (demand: 1, confirmed via the Wet digitaal vergaderen requirement and competitor issue trackers) removes the implicit requirement for a physical location — a growing need since the 2022 amendment enabling permanent digital governance meetings. The meeting-participant-list-limit and meeting-space-indicator features (both sourced from competitor issue trackers with unknown demand) address usability gaps in large governance bodies where participant lists exceed screen capacity and venue over-subscription is a recurring logistical problem. Track event attendance closes the loop between who was invited and who actually attended — a requirement shared across all five governance domains (democratic bodies, associations, corporate boards, corporate operations, and citizen participation) that current RIS platforms handle only superficially.

Speech recognition meeting integration uses the Popolo-aligned Speech entity (ADR-000) to generate and store transcripts using the browser Web Speech API, without building a custom ASR pipeline or incurring external API costs. School Attendance Zone Visualization links Meeting location to the Area entity (ADR-000) so that school board governance bodies can visualise their catchment area and attendance distribution in the meeting detail view. The single linked user story — claiming a task from the shared ActionItem inbox — ensures that unclaimed follow-up actions from any meeting are assigned without duplication across distributed teams operating under the Board Secretary, Team Lead, or MT Member personas.

## What Changes

- **New**: Virtual-Only Meeting Support — enforce `meetingMode: "virtual-only"` on Meeting with video URL validation in `location`; render a "Deelnemen aan vergadering" button in MeetingDetail that opens the video conferencing link; suppress physical-address validation for virtual-only meetings; display a virtual-mode badge on the meeting list and detail views
- **New**: Participant list limit — cap the participant list displayed in MeetingDetail at 10 rows with a "Toon alle (N)" pagination toggle using `CnPagination`; show total participant count in section header; no new API endpoint required
- **New**: Meeting space indicator — display a capacity badge in the MeetingDetail header computed from `quorumRequired` vs actual linked participant count; green when below 80 %, amber at 80–99 %, red at or above 100 %; hidden when `quorumRequired` is unset
- **New**: Track event attendance — allow the secretary or chair to mark each Participant as aanwezig (present), afgemeld (left), or verontschuldigd (excused) during or after the meeting; store arrival and departure via `joinedAt` / `leftAt` on Participant; store excused status via built-in `tags` (`excused`); display an attendance summary table in MeetingDetail
- **New**: Speech recognition — start a browser-side Web Speech API session from the LiveMeeting view; recognised speech segments are saved as Speech objects (ADR-000) linked to the active AgendaItem and Meeting via OpenRegister relations; session controls (start / stop / clear) visible to chair and secretary only; graceful degradation when browser API unavailable
- **New**: School Attendance Zone Visualization — when a Meeting's `location` value matches the `identifier` of an Area object (CBS gemeentecode or province code), render a read-only OpenLayers map tile from the PDOK WMS service showing the Area boundary in a `CnDetailCard` on the MeetingDetail page
- **New**: Shared task inbox with claim — a dedicated "Actiepunten inbox" view lists unclaimed ActionItems (no `assignee`); a "Claimen" button sets `assignee` to the current user's display name with optimistic locking via `ObjectService.lockObject()` to prevent double-claiming; claimed items move to the user's personal task view; double-claim attempt shows who already claimed the item

## Capabilities

### New Capabilities

- `virtual-meeting-support`: Enforce virtual-only meeting mode and render a "Deelnemen aan vergadering" join button from the `location` URL; validate video URL on Meeting save; extend MeetingDetail and the Meeting list with a virtual-mode indicator badge
- `participant-list-limit`: Paginate participant lists in MeetingDetail at 10-per-page using `CnPagination`; show total count in section header; preserve existing filter and sort behaviour
- `meeting-space-indicator`: Capacity badge in MeetingDetail header — green / amber / red based on `quorumRequired` vs linked Participant count; pure frontend computed property; no additional API endpoint
- `attendance-tracking`: Secretary or chair marks Participant presence status per Meeting; `joinedAt` / `leftAt` timestamps stamped on mark-present / mark-left actions; `excused` tag applied for verontschuldigd; attendance summary table displayed in MeetingDetail
- `speech-recognition`: Browser-based Web Speech API session saves recognised segments as Speech objects linked to the active AgendaItem and Meeting; start / stop controls in LiveMeeting view; requires Meeting lifecycle `opened`; graceful degradation when API unavailable
- `attendance-zone-visualization`: OpenLayers map tile in MeetingDetail renders the Area boundary whose `identifier` matches the Meeting `location` value; fetches Area objects via `ObjectService.findAll()`; map shown only when a match exists
- `shared-task-inbox`: ActionItem index view filtered to unclaimed items; "Claimen" button with `ObjectService.lockObject()` for double-claim prevention; claimed items routed to personal task list; toast message on lock conflict showing claimant name

### Modified Capabilities

- `meeting-detail` *(from p2-meeting-management)*: Extended with capacity badge, virtual join button, attendance summary section, speech transcript tab, and area map widget
- `action-item-tracking` *(from p2-minutes-and-decisions)*: Extended with shared inbox view and claim workflow
- `p2-meeting-management`: extended by `p2-meeting-management-other-t2` — adds configuration, workflow, or seed data


## Impact

- No new entities or schema changes — all features use Meeting, Participant, ActionItem, Speech, and Area from ADR-000; `excused` tag uses the built-in `tags` array on Participant
- Backend: adds `AttendanceService.php` for mark-present / mark-left / mark-excused business logic and authorization; adds `AttendanceController.php` exposing `POST /api/meetings/{id}/attendance/{participantId}` (mark present/left/excused) and `DELETE /api/meetings/{id}/attendance/{participantId}` (remove mark); no new controllers for list-limit, space-indicator, speech, or area map (pure frontend)
- Frontend: adds `VirtualMeetingJoin.vue`, `MeetingSpaceIndicator.vue`, `AttendanceTracker.vue`, `SpeechRecognitionPanel.vue`, `AttendanceZoneMap.vue`, and `SharedTaskInbox.vue`; extends `MeetingDetail.vue` and `LiveMeeting.vue` (from p2-agenda-management)
- Speech recognition is browser-side (Web Speech API); Speech objects saved via existing `objectStore.saveObject()` — no custom ASR endpoint needed
- Area map reads Area objects via `objectStore.findAll('area')` — no new controller; PDOK WMS tile is a public endpoint
- Downstream: p3-minutes-and-decisions can include the attendance summary in generated minutes; p3-ori-publication can include Speech transcripts in open data output; p3-governance-bodies uses Area entity for jurisdiction display
