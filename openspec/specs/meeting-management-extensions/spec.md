---
status: done
---

# Specs: Meeting Management — Other T2

**Change:** p2-meeting-management-other-t2
**App:** Decidiq
**Entities:** Meeting, Participant, ActionItem, Speech, Area

---

## Purpose

This spec defines meeting management extensions for Decidiq: virtual-only meetings, participant list limits, space indicators, attendance tracking, speech recognition, area visualization, and the shared task inbox.

# Requirements

## REQ-VOM: Virtual-Only Meeting Support

The system SHALL satisfy the REQ-VOM (Virtual-Only Meeting Support) requirements specified below.

### REQ-VOM-001 — Virtual-only meeting requires a video conferencing URL in `location`
When saving a Meeting with `meetingMode: "virtual-only"`, the system SHALL validate that the `location` field contains a valid HTTPS URL.

**GIVEN** a Meeting create or edit form is open with `meetingMode` set to `virtual-only`
**WHEN** the user submits the form with a `location` value that is not a valid HTTPS URL (e.g., a physical address or empty)
**THEN** the form shows a validation error: "Een videoconferentie-URL is verplicht voor digitale vergaderingen"
**AND** the Meeting is not saved

**GIVEN** a Meeting create or edit form is open with `meetingMode` set to `in-person`
**WHEN** the user submits the form without a valid HTTPS URL in `location`
**THEN** no video-URL validation error is shown (physical address format is accepted)

---

### REQ-VOM-002 — "Deelnemen aan vergadering" button appears on virtual-only meetings
The MeetingDetail page SHALL display a "Deelnemen aan vergadering" primary button when the Meeting's `meetingMode` is `virtual-only` and `location` is a non-empty HTTPS URL.

**GIVEN** a Meeting with `meetingMode: "virtual-only"` and `location: "https://teams.microsoft.com/..."` is open in MeetingDetail
**WHEN** the page is rendered
**THEN** a "Deelnemen aan vergadering" `NcButton` (primary, external link) is displayed in the meeting header actions
**AND** clicking the button opens the video URL in a new browser tab

**GIVEN** a Meeting with `meetingMode: "in-person"` is open in MeetingDetail
**WHEN** the page is rendered
**THEN** no "Deelnemen aan vergadering" button is shown

---

### REQ-VOM-003 — Virtual-mode badge displayed in Meeting list and detail
Meetings with `meetingMode: "virtual-only"` SHALL display a "Digitaal" badge using `CnStatusBadge` in both the Meeting index list and the MeetingDetail header.

**GIVEN** the Meeting index list is displayed
**WHEN** a Meeting has `meetingMode: "virtual-only"`
**THEN** a "Digitaal" `CnStatusBadge` is shown in the meeting type column alongside the meeting title
**AND** the badge is not shown for `in-person` or `hybrid` meetings

**GIVEN** a Meeting detail page for a `virtual-only` meeting is open
**WHEN** the page is rendered
**THEN** a "Digitaal" `CnStatusBadge` is visible in the meeting header below the title

---

## REQ-PLL: Participant List Limit

The system SHALL satisfy the REQ-PLL (Participant List Limit) requirements specified below.

### REQ-PLL-001 — Participant section in MeetingDetail paginates at 10 rows
The participant list in MeetingDetail SHALL display at most 10 participants per page using `CnPagination`. The section header SHALL always show the total participant count.

**GIVEN** a Meeting with 15 linked Participants is open in MeetingDetail
**WHEN** the Participants section is rendered
**THEN** exactly 10 participant rows are displayed
**AND** the section header reads "Deelnemers (15)"
**AND** a `CnPagination` control is shown below the list

**GIVEN** a Meeting with 8 linked Participants is open in MeetingDetail
**WHEN** the Participants section is rendered
**THEN** all 8 participant rows are displayed
**AND** the section header reads "Deelnemers (8)"
**AND** no pagination control is shown

---

### REQ-PLL-002 — "Toon alle" toggle bypasses pagination
A "Toon alle (N)" link SHALL allow the user to expand the participant list beyond the 10-row limit within the same view, without navigating to a separate page.

**GIVEN** a Meeting with more than 10 linked Participants is displayed with pagination active
**WHEN** the user clicks "Toon alle (N)" at the bottom of the section
**THEN** all participants are shown in a single scrollable list
**AND** the link changes to "Toon minder" to allow collapsing back to paginated view

---

## REQ-MSI: Meeting Space Indicator

The system SHALL satisfy the REQ-MSI (Meeting Space Indicator) requirements specified below.

### REQ-MSI-001 — Capacity badge shows fill ratio in MeetingDetail header
The MeetingDetail page SHALL display a capacity badge next to the participant count when `quorumRequired` is set. The badge colour SHALL reflect the fill ratio.

**GIVEN** a Meeting with `quorumRequired: 23` has 18 linked Participants
**WHEN** the MeetingDetail header is rendered
**THEN** a green `CnStatusBadge` labelled "18 / 23 deelnemers" is shown
**AND** the badge colour is green (fill ratio 78 % < 80 %)

**GIVEN** a Meeting with `quorumRequired: 23` has 20 linked Participants
**WHEN** the MeetingDetail header is rendered
**THEN** an amber `CnStatusBadge` labelled "20 / 23 deelnemers" is shown
**AND** the badge colour is amber (fill ratio 87 % ≥ 80 % and < 100 %)

**GIVEN** a Meeting with `quorumRequired: 23` has 24 linked Participants
**WHEN** the MeetingDetail header is rendered
**THEN** a red `CnStatusBadge` labelled "24 / 23 deelnemers" is shown
**AND** the badge colour is red (fill ratio ≥ 100 %)

---

### REQ-MSI-002 — Space indicator hidden when `quorumRequired` is unset
The capacity badge SHALL NOT be rendered when the Meeting has no `quorumRequired` value.

**GIVEN** a Meeting with `quorumRequired: null` is open in MeetingDetail
**WHEN** the MeetingDetail header is rendered
**THEN** no capacity badge is displayed
**AND** the participant count is shown as plain text: "N deelnemers"

---

## REQ-EAT: Event Attendance Tracking

The system SHALL satisfy the REQ-EAT (Event Attendance Tracking) requirements specified below.

### REQ-EAT-001 — Secretary or chair can mark a Participant as present
The attendance tracking interface in MeetingDetail SHALL allow the secretary or chair to mark a Participant as present by recording `joinedAt` with the current timestamp.

**GIVEN** a Participant is linked to a Meeting and has `joinedAt: null`
**WHEN** the secretary clicks "Aanwezig melden" next to the Participant row
**THEN** `AttendanceService::markJoined()` is called with the Participant ID and Meeting ID
**AND** the Participant's `joinedAt` is set to the current UTC timestamp via `ObjectService.saveObject()` (patch)
**AND** the Participant row updates to show the arrival time
**AND** a Nextcloud notification is sent to the Meeting chair

**GIVEN** the logged-in user is a regular Participant (not secretary or chair)
**WHEN** they view the attendance section in MeetingDetail
**THEN** "Aanwezig melden" for other Participants is hidden; only their own self-check-in button is visible

---

### REQ-EAT-002 — Secretary or chair can mark a Participant as left
The attendance tracking interface SHALL allow the secretary or chair to record the departure of a Participant by setting `leftAt`.

**GIVEN** a Participant has `joinedAt` set and `leftAt: null`
**WHEN** the secretary clicks "Afmelden" next to the Participant row
**THEN** `AttendanceService::markLeft()` is called
**AND** the Participant's `leftAt` is set to the current UTC timestamp via `ObjectService.saveObject()` (patch)
**AND** the Participant row updates to show the departure time

---

### REQ-EAT-003 — Secretary can mark a Participant as excused
The attendance tracking interface SHALL allow the secretary to mark a Participant as verontschuldigd (excused) by adding the `excused` tag to the Participant's built-in `tags` array.

**GIVEN** a Participant is linked to a Meeting
**WHEN** the secretary clicks "Verontschuldigd" next to the Participant row
**THEN** `AttendanceService::markExcused()` is called
**AND** the tag `excused` is added to the Participant's `tags` array via `ObjectService.saveObject()` (patch)
**AND** the Participant row displays a "Verontschuldigd" `CnStatusBadge`

**GIVEN** a Participant already has the `excused` tag
**WHEN** the secretary clicks "Verontschuldigd intrekken"
**THEN** the `excused` tag is removed from the `tags` array
**AND** the Participant row reverts to "Niet aanwezig" status

---

### REQ-EAT-004 — Attendance summary table shown in MeetingDetail
A summary table of attendance status SHALL be displayed in the MeetingDetail Participants section, showing presence status per Participant.

**GIVEN** a Meeting detail page is open with a mix of present, absent, and excused Participants
**WHEN** the user views the "Deelnemers" section
**THEN** each Participant row shows: display name, role, party, arrival time (or "—"), departure time (or "—"), and a presence status badge (Aanwezig / Afwezig / Verontschuldigd)
**AND** the section header shows a summary: "aanwezig: N | afwezig: M | verontschuldigd: K"

---

## REQ-SRM: Speech Recognition Meetings

The system SHALL satisfy the REQ-SRM (Speech Recognition Meetings) requirements specified below.

### REQ-SRM-001 — Chair or secretary can start a speech recognition session
The LiveMeeting view SHALL allow the chair or secretary to start a speech recognition session that captures spoken text and saves it as a Speech object linked to the active AgendaItem.

**GIVEN** a Meeting is open in LiveMeeting view with lifecycle `opened`
**AND** the browser supports the Web Speech API
**AND** the active AgendaItem is set
**WHEN** the chair clicks "Spraakherkenning starten"
**THEN** the browser requests microphone permission (if not already granted)
**AND** the `SpeechRecognitionPanel.vue` starts listening and displays a live transcript area
**AND** recognised speech segments append to the transcript in real-time

**GIVEN** a speech recognition session is active
**WHEN** the chair clicks "Spraakherkenning stoppen"
**THEN** the session ends
**AND** a Speech object is created via `objectStore.saveObject('speech', { text, startDate, endDate, role: 'chair' })`
**AND** the Speech object is linked to the active AgendaItem and Meeting via OpenRegister relations
**AND** the transcript is cleared from the panel

---

### REQ-SRM-002 — Speech recognition degrades gracefully when unavailable
When the browser Web Speech API is not available, the SpeechRecognitionPanel SHALL display a clear empty state rather than failing silently.

**GIVEN** the user's browser does not support `window.SpeechRecognition` or `window.webkitSpeechRecognition`
**WHEN** the SpeechRecognitionPanel is rendered in LiveMeeting view
**THEN** a `CnEmptyState` is shown with message "Spraakherkenning is niet beschikbaar in deze browser. Gebruik Chrome of Edge."
**AND** the "Spraakherkenning starten" button is not shown

**GIVEN** the browser supports the Web Speech API but the Meeting lifecycle is not `opened`
**WHEN** the SpeechRecognitionPanel is rendered
**THEN** the start button is disabled with tooltip "Vergadering moet geopend zijn om spraakherkenning te starten"

---

### REQ-SRM-003 — Speech transcripts are viewable on Meeting and AgendaItem detail pages
Saved Speech objects SHALL be visible in a "Toespraken" section on both MeetingDetail and AgendaItemDetail pages.

**GIVEN** a Meeting has linked Speech objects saved during a session
**WHEN** the user opens MeetingDetail and navigates to the "Toespraken" tab or section
**THEN** all Speech objects linked to this Meeting are listed with: start time, end time, role badge, and transcript text preview (first 200 characters, expandable)

**GIVEN** an AgendaItem has linked Speech objects
**WHEN** the user opens the AgendaItemDetail page
**THEN** Speech objects linked to this AgendaItem are shown in a "Bijdragen" section with full transcript text

---

## REQ-AZV: Attendance Zone Visualization

The system SHALL satisfy the REQ-AZV (Attendance Zone Visualization) requirements specified below.

### REQ-AZV-001 — Area boundary map shown when Meeting location matches an Area identifier
When a Meeting's `location` value exactly matches the `identifier` of an Area object, the MeetingDetail page SHALL display a read-only map tile showing the Area boundary.

**GIVEN** a Meeting has `location: "0503"` and an Area object exists with `identifier: "0503"` (Gemeente Delft)
**WHEN** the Meeting detail page is opened
**THEN** a `CnDetailCard` labelled "Vergadergebied" is rendered with an OpenLayers map tile
**AND** the map shows the Delft municipality boundary from the PDOK WMS service
**AND** the Area name "Gemeente Delft" is displayed above the map

**GIVEN** a Meeting has `location: "https://teams.microsoft.com/..."` (a URL, not a CBS code)
**WHEN** the Meeting detail page is opened
**THEN** no "Vergadergebied" card is rendered
**AND** the "Deelnemen aan vergadering" button is shown instead (per REQ-VOM-002)

---

### REQ-AZV-002 — Area map is read-only and non-interactive
The attendance zone map widget SHALL be a read-only display — users cannot interact with the map to edit Area boundaries.

**GIVEN** the "Vergadergebied" map card is visible in MeetingDetail
**WHEN** the user attempts to click or drag within the map
**THEN** no editing controls are available
**AND** basic pan and zoom interactions are allowed (read-only navigation)
**AND** a "Bekijk op PDOK" external link is shown below the map, opening the PDOK viewer in a new tab

---

## REQ-TCI: Shared Task Inbox (Claim)

The system SHALL satisfy the REQ-TCI (Shared Task Inbox) requirements specified below.

### REQ-TCI-001 — Shared inbox displays unclaimed ActionItems
A dedicated "Actiepunten inbox" view SHALL display all ActionItems where `assignee` is null or empty and `taskStatus` is `"open"`, accessible to all authenticated users.

**GIVEN** an authenticated user navigates to the "Actiepunten inbox" route (`/action-items/inbox`)
**WHEN** the page loads
**THEN** all ActionItems with `assignee: null` (or `""`) and `taskStatus: "open"` are displayed in a `CnDataTable`
**AND** the columns shown are: title, description (truncated), due date, and age (days since creation)
**AND** items are sorted by `dueDate` ascending (soonest due first)

---

### REQ-TCI-002 — User can claim an unclaimed ActionItem
A user SHALL be able to claim an unclaimed ActionItem by clicking "Claimen", which atomically sets `assignee` to their display name using `ObjectService.lockObject()` for double-claim prevention.

**GIVEN** an unclaimed ActionItem is in the shared inbox
**WHEN** the user clicks "Claimen" on an ActionItem row
**THEN** `ObjectService.lockObject(actionItemId)` is called to acquire the lock
**AND** on lock success, `ObjectService.saveObject()` sets `assignee` to the current user's display name
**AND** `ObjectService.unlockObject(actionItemId)` is called in a `finally` block
**AND** the ActionItem disappears from the inbox immediately (optimistic update)
**AND** the ActionItem appears in the user's personal task list (filtered by `assignee`)

---

### REQ-TCI-003 — Double-claim attempt is blocked and shows claimant name
When a user attempts to claim an ActionItem that another user has already locked or claimed, the system SHALL prevent the double-claim and inform the user of who claimed it.

**GIVEN** another user has already claimed the ActionItem (or holds the lock)
**WHEN** a second user clicks "Claimen" on the same ActionItem
**THEN** `ObjectService.lockObject()` returns a lock-failure error
**AND** a `NcDialog` notification is shown: "Deze taak is al geclaimd door [naam claimant]"
**AND** the ActionItem row is refreshed to show the `assignee` display name
**AND** the "Claimen" button changes to a disabled state with label "Geclaimd door [naam]"

---

### REQ-TCI-004 — Claimed ActionItem moves to user's personal task list
After claiming, the ActionItem SHALL appear in the user's personal task list (the standard ActionItem index filtered to their name) alongside other assigned items.

**GIVEN** a user has successfully claimed an ActionItem (assignee set to their display name)
**WHEN** the user navigates to the "Mijn actiepunten" view (ActionItem index filtered by `assignee = currentUser`)
**THEN** the claimed ActionItem appears in the list with all its fields
**AND** the item is no longer shown in the shared "Actiepunten inbox"

---

### REQ-TCI-005 — Accessibility: Claimen button and status visible with keyboard navigation
All interactive elements in the shared task inbox SHALL be reachable and operable via keyboard, meeting WCAG AA requirements.

**GIVEN** a user navigates the "Actiepunten inbox" using only keyboard (Tab, Enter, Space)
**WHEN** they focus on a row's "Claimen" button using Tab
**THEN** the button has a visible focus indicator
**AND** pressing Enter or Space triggers the claim action
**AND** the success or failure notification (NcDialog) receives focus after the action completes
