# Specs: Meeting Management — Other T1

**Change:** p2-meeting-management-other-t1
**App:** Decidesk
**Entities:** Meeting (CalDAV VEVENT + OpenRegister wrapper), AgendaItem

---

## Purpose

This spec defines digital council meetings, speaking time management, and recurring meeting series for Decidesk.

# Requirements

## REQ-DCM: Digital Council Meetings

The system SHALL satisfy the REQ-DCM (Digital Council Meetings) requirements specified below.

### REQ-DCM-001 — Display Join Meeting button for digital and hybrid meetings

A participant can join a digital or hybrid meeting directly from the Meeting detail page.

**GIVEN** a Meeting detail page is open
**WHEN** `meetingMode` is `digital` or `hybrid` and the `location` field contains a URL
**THEN** the Meeting detail page renders the `location` field as a primary `NcButton` labelled "Deelnemen aan vergadering" with a video conference icon
**AND** clicking the button opens the URL in a new browser tab
**AND** the button is keyboard-accessible and has an `aria-label` in both Dutch and English

**GIVEN** a Meeting detail page is open
**WHEN** `meetingMode` is `in-person`
**THEN** the `location` field is displayed as plain text without a join button

### REQ-DCM-002 — Show Live badge when meeting is in progress

A participant can immediately see whether a meeting is currently taking place.

**GIVEN** the Meeting index page or Meeting detail page is displayed
**WHEN** a Meeting has `lifecycle: opened`
**THEN** a "Live" `CnStatusBadge` in the success colour (Nextcloud CSS variable `--color-success`) is shown next to the meeting title
**AND** the badge label is "Live" in English and "Live" in Dutch (same word)

**GIVEN** a Meeting has any lifecycle state other than `opened`
**WHEN** the Meeting index or detail page renders
**THEN** no "Live" badge is shown

### REQ-DCM-003 — Mark the active AgendaItem as current

The chair or secretary can mark which AgendaItem is currently being discussed, visible to all participants.

**GIVEN** a Meeting detail page is open with `lifecycle: opened`
**AND** the current user has role `chair` or `secretary` in the linked GovernanceBody
**WHEN** the user clicks the "Huidige punt" action on an AgendaItem in the agenda list
**THEN** `ObjectService.saveObject()` sets the selected AgendaItem's built-in `status` field to `current`
**AND** any previously `current` AgendaItem for this meeting is set back to `upcoming`
**AND** the updated item is visually highlighted in the agenda list with a "Huidig" badge (`CnStatusBadge`)
**AND** the change is persisted via OpenRegister and reflected immediately in the UI

**GIVEN** the current user has role `member`, `observer`, or `guest`
**WHEN** the user views the Meeting detail page with `lifecycle: opened`
**THEN** the "Huidige punt" action button is not visible (agenda items are read-only for non-chairs)

### REQ-DCM-004 — Public live meeting view without authentication

Citizens, journalists, and remote participants can follow a live meeting without a Nextcloud account.

**GIVEN** a Meeting has `lifecycle: opened` and `meetingMode` is `digital` or `hybrid`
**WHEN** a browser requests `GET /api/meetings/{id}/live` without authentication
**THEN** the endpoint returns HTTP 200 with: `{ title, lifecycle, meetingMode, location, scheduledDate, currentAgendaItem: { title, orderNumber, estimatedDuration } }`
**AND** the response excludes all ATTENDEE data, internal notes, and other non-public fields
**AND** `MeetingLiveView.vue` (loaded at `/apps/decidesk/meetings/{id}/live`) displays this data in a clean public-facing layout with the meeting title, the current agenda item title, and (if `digital`/`hybrid`) a "Deelnemen" link

**GIVEN** the Meeting has `lifecycle` other than `opened`
**WHEN** a browser requests `GET /api/meetings/{id}/live`
**THEN** the endpoint returns HTTP 200 with the current state data (showing lifecycle: `scheduled` or `closed`) so viewers can see the meeting has not started or has ended

**GIVEN** the `id` does not match any Meeting
**WHEN** a browser requests `GET /api/meetings/{id}/live`
**THEN** the endpoint returns HTTP 404 with `{ "message": "Meeting not found" }`

---

## REQ-STM: Speaking Time Management

The system SHALL satisfy the REQ-STM (Speaking Time Management) requirements specified below.

### REQ-STM-001 — Configure speaking time limit per agenda item

The chair can see the speaking time limit for each agenda item, drawn from its `estimatedDuration`.

**GIVEN** a Meeting detail page is open with `lifecycle: opened`
**AND** the `SpeakingTimePanel.vue` component is visible in the session panel
**WHEN** the chair selects an AgendaItem from the agenda list
**THEN** the speaking time clock is loaded with the item's `estimatedDuration` value (in minutes) as the time budget
**AND** if `estimatedDuration` is null or zero, a configurable session-default speaking time (2 minutes) is used as the starting value
**AND** the time budget is displayed clearly as "Spreektijd: X minuten" above the countdown clock

### REQ-STM-002 — Start and stop the speaking time clock for a speaker

The chair can start, pause, and stop the speaking time clock for the current speaker.

**GIVEN** the `SpeakingTimePanel.vue` is visible with a speaker selected from the queue
**WHEN** the chair clicks "Starten"
**THEN** the countdown clock begins decrementing from the configured speaking time
**AND** the current speaker's name is displayed prominently above the clock
**AND** when 30 seconds remain, the clock indicator changes to the warning colour (Nextcloud CSS variable `--color-warning`)
**AND** when 0 seconds are reached, the clock indicator changes to the error colour (`--color-error`) and an audible browser beep is triggered (if browser audio is available)

**GIVEN** the clock is running
**WHEN** the chair clicks "Pauzeren"
**THEN** the countdown pauses and a "Gepauzeerd" label appears
**AND** clicking "Hervatten" resumes the countdown from the paused value

**GIVEN** the clock is running or paused
**WHEN** the chair clicks "Stoppen"
**THEN** the clock stops and the elapsed time for this speaker is recorded in the cumulative balance for that speaker's display name
**AND** the next speaker in the queue (if any) is highlighted as ready to begin

### REQ-STM-003 — Manage speaker queue

The chair can add, reorder, and remove participants from the speaking queue.

**GIVEN** the `SpeakingTimePanel.vue` is visible and the Meeting has `lifecycle: opened`
**WHEN** the chair types a participant name in the "Spreker toevoegen" input and presses Enter or clicks "Toevoegen"
**THEN** the participant is added to the end of the speaker queue
**AND** the queue displays the participant's name and their position number

**GIVEN** the speaker queue has two or more entries
**WHEN** the chair drags a queue entry to a new position using the drag handle
**THEN** the queue reorders immediately and position numbers update

**GIVEN** a speaker is in the queue
**WHEN** the chair clicks the "Verwijderen" icon on that speaker's row
**THEN** the speaker is removed from the queue without affecting other entries

**GIVEN** the speaker queue is empty
**WHEN** the chair views the `SpeakingTimePanel`
**THEN** an empty state message "Wachtrij leeg — voeg een spreker toe" is shown

### REQ-STM-004 — Display cumulative speaking time balance per participant

The chair can see how much total time each participant has spoken during the session.

**GIVEN** one or more speakers have used the speaking clock during the session
**WHEN** the `SpeakingTimePanel.vue` displays the "Tijdbalans" (time balance) section
**THEN** each participant who has spoken is listed with: display name, total speaking time in minutes and seconds, and a horizontal progress bar proportional to their share of total session speaking time
**AND** participants are sorted by total speaking time descending (highest first)
**AND** the progress bar colour uses Nextcloud CSS variables — no hardcoded colours

**GIVEN** the session has just started with no completed speaker turns
**WHEN** the chair views the "Tijdbalans" section
**THEN** the section shows "Nog geen sprekers actief geweest" as an empty state

---

## REQ-RMS: Recurring Meeting Series

The system SHALL satisfy the REQ-RMS (Recurring Meeting Series) requirements specified below.

### REQ-RMS-001 — Configure a meeting as recurring when creating or editing

A meeting organizer can configure a meeting to recur on a regular schedule.

**GIVEN** the Meeting create or edit form is open
**WHEN** the user opens the "Herhaling" panel and selects a frequency
**THEN** the available frequency options are: "Wekelijks", "Tweewekelijks", "Maandelijks", "Driemaandelijks"
**AND** for weekly/bi-weekly frequencies, a day-of-week selector is shown (defaulting to the scheduledDate's weekday)
**AND** for monthly/quarterly frequencies, a day-of-month or weekday-of-month selector is shown
**AND** an end condition selector is shown with options: "Onbepaald" (indefinite), "Tot datum" (until a date), "Maximaal X keer"

**GIVEN** the user has configured a recurrence and saves the Meeting
**WHEN** `CalDavService::createOrUpdateVEvent()` writes the VEVENT
**THEN** an `RRULE` property is included in the VEVENT ICS blob with the configured frequency and end condition
**AND** an `X-DECIDESK-SERIES` property is set to a generated slug (`{bodySlug}-{meetingType}-{startYear}`) if not already set
**AND** the Meeting's `series` field in the OpenRegister wrapper is populated with the same slug
**AND** the recurring meetings appear in the Nextcloud Calendar app as individual instances

**GIVEN** the user selects "Onbepaald" as the end condition
**WHEN** the VEVENT is created
**THEN** no `UNTIL` or `COUNT` component is included in the RRULE
**AND** the recurrence continues indefinitely (as per RFC 5545 when neither UNTIL nor COUNT is present)

### REQ-RMS-002 — View all meetings in a series from the Meeting index

A user can filter the Meeting index to show only the instances of a specific recurring series.

**GIVEN** the Meeting index page is open
**WHEN** the user applies the "Reeks" filter from `CnFilterBar` and selects a series slug
**THEN** only Meeting objects with `series` matching the selected slug are displayed
**AND** the results are sorted by `scheduledDate` ascending (upcoming instances first)
**AND** a banner shows: "Reeks: {series slug} — {count} vergaderingen"

**GIVEN** the user has applied a series filter
**WHEN** the user clicks a meeting in the filtered list
**THEN** the Meeting detail page opens and shows the "Reeks" badge with the series identifier and a link to clear the filter and show all instances

### REQ-RMS-003 — Display series information on Meeting detail page

A user can see that a meeting is part of a recurring series from its detail page.

**GIVEN** a Meeting detail page is open
**WHEN** the Meeting has a non-null `series` field
**THEN** a "Reeks" row is displayed in the Meeting properties section showing the series identifier
**AND** the row includes a "Bekijk alle vergaderingen in reeks" link that applies the series filter to the Meeting index

**GIVEN** a Meeting detail page is open
**WHEN** the Meeting's `series` field is null or empty
**THEN** no "Reeks" row is displayed

### REQ-RMS-004 — Edit or cancel a single instance without affecting the series

The chair can make changes to a single meeting in a series without modifying other instances.

**GIVEN** a Meeting detail page is open for a recurring instance (has a non-null `series` field)
**WHEN** the user edits the meeting and saves
**THEN** a dialog asks: "Wijzig alleen deze vergadering of de hele reeks?"
**AND** selecting "Alleen deze vergadering" saves changes to the single VEVENT without modifying the master RRULE
**AND** selecting "Hele reeks" navigates to the master VEVENT edit form (future — deferred to T2; button is shown but disabled with tooltip "Bewerken van de hele reeks is beschikbaar in een volgende versie")

---

## Non-Functional Requirements

The implementation MUST satisfy the non-functional requirements (REQ-NFR) specified below.

### REQ-NFR-001 — Accessibility (ADR-010)
All new UI components introduced by this change MUST meet WCAG 2.1 AA: the "Deelnemen" button MUST have an `aria-label` that includes the meeting title; the speaking time clock MUST announce time changes to screen readers via `aria-live="polite"`; the speaker queue MUST be keyboard-navigable with focus management when items are added or removed; colour is not the sole indicator of state (Live badge includes text; clock overrun uses both colour change AND icon change).

### REQ-NFR-002 — Internationalisation (ADR-007)
All user-visible strings introduced by this change MUST use `t(appName, 'text')`. Dutch (`nl`) and English (`en`) translations MUST be provided for all new strings before merging. Dutch is the primary language for this feature area; English keys are the canonical key form.

### REQ-NFR-003 — No hardcoded colours (ADR-004 / ADR-010)
All status indicators — Live badge, speaking time clock warning/error states, time balance bars — MUST use Nextcloud CSS variables (`--color-success`, `--color-warning`, `--color-error`, `--color-primary-element`). No hardcoded hex values or `--nldesign-*` tokens in component styles.

### REQ-NFR-004 — Spec traceability (ADR-003)
Every new PHP class and public method introduced by this change MUST carry a `@spec openspec/changes/p2-meeting-management-other-t1/tasks.md#task-N` PHPDoc tag. File-level `@spec` in the header docblock.

### REQ-NFR-005 — Public endpoint security (ADR-005)
The `GET /api/meetings/{id}/live` endpoint MUST NOT expose: attendee UIDs, email addresses, internal notes, audit trail data, or any PII. The response schema is fixed and must not be extended to include sensitive fields. The endpoint is annotated `#[PublicPage] #[NoCSRFRequired]` and must not perform any write operation.

### REQ-NFR-006 — No custom CRUD, audit, or search code (ADR-001)
All data retrieval for Meeting and AgendaItem uses `ObjectService` and `CalDavService`. Audit trail for AgendaItem `status` changes is automatic via `AuditTrailService`. Filtering by `series` field uses `ObjectService.findAll()` with the `series` filter parameter + `CnFilterBar`. No custom database queries, custom audit log handlers, or custom search endpoints.

### REQ-NFR-007 — Speaking time panel visible only during active meeting (ADR-004)
The `SpeakingTimePanel.vue` MUST only be rendered when `Meeting.lifecycle === 'opened'`. If the lifecycle changes while the panel is visible (e.g., meeting is adjourned), the component MUST disable all controls and show a "Vergadering is niet meer actief" message. The component MUST NOT be visible in the public live view.
