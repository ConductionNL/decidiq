---
kind: code
depends_on: []
---

# Proposal: live-meeting-shared-current-item

## Summary

The live screen's current item is local to the chair's browser, the room projection endpoint has no page, the live decision endpoint has no caller, and speeches are not tied to items. This change saves the current item on the meeting so every member and a room screen follow it, records a decision from the live screen, and logs speeches and questions per item.

## The rows this closes

Source matrix: decidiq `openspec/parity/capabilities.json` (comparedOn 2026-09-26). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### liv-01, run a meeting from a live view that shows the chair and members the current item

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> src/manifest.json:720 LiveMeeting page /meetings/:id/live -> src/views/LiveMeeting.vue; chair 'Activate item' buttons set a LOCAL activeItemId (LiveMeeting.vue:477 activateItem), never saved, so members' browsers never learn the current item; isChair (LiveMeeting.vue:356) matches participants filtered on @self.relations.meeting (LiveMeeting.vue:330) but the Participant schema has no meeting property and MeetingParticipantsTab writes a `meetings` array instead

Matrix note, verbatim:

> A live view exists with agenda, chair controls, BOB phase and hamerstukken. The current item is per-browser state and is not shared with members. Chair detection depends on a participant->meeting relation the UI does not write (e2e seeds it via API). Nothing in the app navigates to the page.

Competitor cells rated `yes`, verbatim:

- diligent-boards: https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/boards-web-director/book-navigation/follow-the-presenter-bwd.htm and https://help.diligentoneplatform.com/helpdocs/boards/en-us/Content/release_notes/release-notes-bwd.htm (Sep 05, 2025) describe Present and Follow the Presenter modes that keep directors on the presenter's page
- openslides: source read at 4.3.4, not driven: openslides-client/client/src/app/site/pages/meetings/pages/autopilot/components/autopilot/autopilot.component.html:63 the autopilot view (routed at meetings/autopilot, meeting.can_see_autopilot) follows the current projection with its list of speakers, moderation note (line 148), running polls (line 160) and the live projector (line 184).

### liv-04, show the current item or vote on a screen in the room

Own rating `no`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> lib/Controller/ProjectionController.php:80 publicState() (#[PublicPage]) serves GET /api/voting-rounds/{id}/public-state; grep for 'public-state' under src/ finds no caller and no projection page in manifest

Matrix note, verbatim:

> A public JSON state endpoint exists for a projection screen but no screen/page consumes it. Nothing shows the current item in the room.

Competitor cells rated `yes`, verbatim:

- go-raadsinformatie: https://www.gemeenteoplossingen.nl/producten/papierloos_vergaderen/go._stemgedrag/ states the griffie closes the vote and publishes the result on a screen in the room and on the website
- openslides: source read and driven at 4.3.4 on 2026-09-26 (lab-decidiq-openslides): projector 1 (/1/projectors/1, stream /system/projector/subscribe/1) showed the projected poll slide with meeting name and clock. Source: openslides-backend/meta/collections/projector.yml:2 projectors with current, preview and history projections (projection.yml:42 content_object_id covers agenda, motions, polls, lists of speakers), shown full screen at openslides-client/client/src/app/site/pages/meetings/pages/projectors/modules/fullscreen-projector/fullscreen-projector-routing.module.ts:9.

### liv-05, record a decision the moment it is taken during the meeting

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> src/components/tabs/MeetingDecisionsTab.vue:143 createDecision() saves a decision with only title+meeting (schema requires title,text,decisionType in lib/Settings/decidesk_register.json Decision.required, so the save may be refused); src/components/minutesEditor/MinutesPanel.vue:121 free-text 'Decisions' notes per agenda item on the live page; lib/Controller/LiveMeetingController.php:122 recordLiveDecision() has no frontend caller

Matrix note, verbatim:

> Decisions can be created and linked to a meeting, and the secretary can type decisions into the live minutes. The dedicated live-decision endpoint is API only, and the meeting-page quick create omits required fields.

Competitor cells rated `yes`, verbatim:

- ibabs: https://support.ibabs.com/docs/besluitregistratie.md states the agenda manager registers decisions during or after the meeting via the hammer icon
- openslides: source read at 4.3.4, not driven: openslides-backend/meta/collections/motion.yml:136 state_id is set during the meeting through motion.set_state from the motion detail and autopilot, and poll results are stored on stop (poll.yml:83 votesvalid); motion_state.yml:83 set_workflow_timestamp stamps when a decisive state was reached.

### liv-11, log who spoke on which item and which questions were raised

Own rating `partial`, built.state `building`, owner `ConductionNL/decidiq`. Demand origin `code`, no originUrl.

Matrix evidence, verbatim:

> lib/Service/EngagementService.php:84 captureEngagement() appends speeches/questionsRaised per meeting+participant; UI only posts eventType 'speech' (src/components/liveMeeting/SpeakerQueuePanel.vue:307); EngagementRecord has no agenda-item field (decidesk_register.json EngagementRecord.properties); Engagement index page src/manifest.json:1277

Matrix note, verbatim:

> Speeches and durations are logged per meeting and participant. Not per agenda item, and questions can only be logged through the API.

Competitor cells rated `yes`, verbatim:

- notubiz: https://www.kennisbank.notubiz.nl/handleidingen/raadplegen-digitaal-verslag states speakers per agenda item are listed and filterable; https://amsterdam.raadsinformatie.nl/vergadering/1528626 lists speakers per item
- openslides: source read at 4.3.4, not driven: openslides-client/client/src/app/site/pages/meetings/pages/participants/pages/participant-speaker-list/components/participant-speaker-list/participant-speaker-list.component.html:4 the Contributions page (participants/speaker-list) lists every speech per participant and item with its kind, point of order (line 43), interposed question (line 78) and intervention (line 87), exportable as CSV (line 123); stored in speaker.yml:27 speech_state.

## Why

All four rows are rated yes by two or more competitors. They share one screen and one piece of state: which item is on.

## What is built today

- LiveMeeting page with agenda, chair controls, BOB phase and formalities; reachable through Open live meeting (#1441).
- ProjectionController public state for a voting round.
- LiveMeetingController::recordLiveDecision().
- EngagementService speeches per meeting and participant.

## What changes

1. Meeting.currentAgendaItem is saved when the chair activates an item; members' live screens poll and follow it.
2. A projection page (/meetings/:id/screen) shows the current item and, while a round is open, the round state; public for public meetings.
3. A Record decision action on the live screen calls recordLiveDecision() with title, text and decisionType for the current item.
4. EngagementRecord carries the agenda item; the speaker queue logs speeches with it, and a Question raised action logs questions.
5. Chair detection uses the meeting roles endpoint (my-roles) instead of the participant relation the UI does not write.

## Out of scope

- Conference system link (live-room-conference-system-link).
