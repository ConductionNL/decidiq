---
status: idea
---

# Nextcloud Integration Specification

## Purpose
@e2e exclude V1 feature — all scenarios are OCP backend integrations (Calendar IManager, Files IRootFolder, Talk IBroker, Activity IManager, Notification IManager, Search IProvider). None of the integrations have a dedicated UI page built in the SPA; they are server-side hooks with no Playwright-accessible surface.

Decidesk leverages Nextcloud's platform capabilities to provide a seamless governance experience without reinventing existing functionality. This specification covers integration with Nextcloud Calendar (meeting scheduling), Files (document management), Mail (convocation delivery), Talk (meeting communication), Tasks (action item tracking), Activity (audit feed), Notifications (alerts), Search (universal search), and References (rich link previews). Each integration uses the appropriate OCP interface.

**Standards**: Nextcloud OCP interfaces, CalDAV (RFC 4791), WebDAV
**Feature tier**: V1

## Requirements

---

### Requirement: Calendar Integration

The system MUST create Nextcloud Calendar events for scheduled meetings via `OCP\Calendar\IManager`. Calendar events MUST include meeting title, date/time, location, body, and a link back to the Decidesk meeting. Changes to the meeting schedule MUST update the calendar event.

**Feature tier**: V1

#### Scenario: Create calendar event when meeting is scheduled

- GIVEN a meeting "Board Meeting Q2" scheduled for 2026-07-15 14:00-16:00 in "Boardroom A"
- WHEN the meeting is created in Decidesk
- THEN a calendar event MUST be created in each attendee's Nextcloud Calendar
- AND the event MUST include the meeting link, agenda summary, and document links
- AND the event MUST have a reminder set to the user's configured preference

#### Scenario: Update calendar event when meeting is rescheduled

- GIVEN a meeting with associated calendar events
- WHEN the meeting date is changed from July 15 to July 22
- THEN all attendee calendar events MUST be updated to the new date
- AND attendees MUST receive a notification about the schedule change

#### Scenario: Cancel calendar event when meeting is cancelled

- GIVEN a meeting with associated calendar events
- WHEN the meeting is cancelled
- THEN all attendee calendar events MUST be cancelled
- AND attendees MUST receive a cancellation notification

---

### Requirement: Files Integration

The system MUST store and manage meeting documents using Nextcloud Files via `OCP\Files\IRootFolder`. Each meeting MUST have a dedicated folder in a configurable location. Agenda item attachments MUST be stored in the meeting folder.

**Feature tier**: V1

#### Scenario: Create meeting folder structure on meeting creation

- GIVEN a meeting "Board Meeting Q2 2026" for body "Board of Directors"
- WHEN the meeting is created
- THEN a folder MUST be created at the configured path (default: `Decidesk/Board of Directors/2026-07-15 Board Meeting Q2/`)
- AND subfolders MUST be created for "Agenda Documents" and "Minutes"
- AND all body members MUST have read access; secretary and chair MUST have write access

#### Scenario: Attach document to agenda item via file picker

- GIVEN a user editing an agenda item
- WHEN they click "Attach Document" and select a file from Nextcloud Files
- THEN the file MUST be linked to the agenda item
- AND the file MUST be accessible to all meeting participants
- AND the file MUST appear in the meeting document package

---

### Requirement: Talk Integration

The system MUST create Nextcloud Talk conversations for meetings via `OCP\Talk\IBroker`. Meeting participants MUST be automatically added to the conversation. The conversation MUST serve as the communication channel for meeting preparation and follow-up.

**Feature tier**: V1

#### Scenario: Create Talk conversation for a meeting

- GIVEN a meeting "ALV 2026" with 50 participants
- WHEN the meeting is created
- THEN a Talk conversation MUST be created with all participants
- AND the conversation name MUST be "ALV 2026"
- AND the conversation description MUST include the meeting date and agenda link

---

### Requirement: Activity Integration

The system MUST publish Decidesk events to the Nextcloud Activity feed via `OCP\Activity\IManager`. Events MUST include: decision created/status changed, meeting scheduled/started/ended, vote initiated/completed, and resolution adopted.

**Feature tier**: V1

#### Scenario: Decision status change appears in Activity feed

- GIVEN a decision "Approve Budget 2026" transitions from "deliberating" to "voting"
- WHEN the transition is completed
- THEN an Activity entry MUST be created: "Decision 'Approve Budget 2026' moved to voting"
- AND the entry MUST be visible to all members of the governing body
- AND clicking the activity MUST navigate to the decision in Decidesk

---

### Requirement: Notification Integration

The system MUST send Nextcloud Notifications via `OCP\Notification\IManager` for time-sensitive governance events: upcoming meeting reminders, pending votes, voting deadlines approaching, and action item due dates.

**Feature tier**: V1

#### Scenario: Send pending vote notification

- GIVEN a new vote has been initiated for decision "Policy Update"
- WHEN the vote opens
- THEN all eligible voters MUST receive a Nextcloud notification
- AND the notification MUST include the decision title, body, and voting deadline
- AND tapping the notification MUST open the voting interface

#### Scenario: Send voting deadline reminder

- GIVEN a vote with deadline tomorrow and user has not yet voted
- WHEN the reminder timing is triggered (24 hours before deadline)
- THEN the user MUST receive a notification: "Reminder: Your vote on 'Policy Update' is due tomorrow"
- AND the notification MUST use `variant="warning"`

---

### Requirement: Search Integration

The system MUST register a search provider via `OCP\Search\IProvider` so that decisions, meetings, and resolutions are findable from Nextcloud's universal search.

**Feature tier**: V1

#### Scenario: Find a decision via Nextcloud search

- GIVEN decisions exist including "Budget 2026 Approval"
- WHEN the user searches for "budget" in Nextcloud's universal search
- THEN the Decidesk search provider MUST return "Budget 2026 Approval" as a result
- AND the result MUST show the decision title, status, body, and a thumbnail icon
- AND clicking the result MUST navigate to the decision detail view in Decidesk

## User Stories

1. **Board member accessing board pack on mobile**: As a supervisory board member, I want to access the board pack on my tablet or smartphone with offline capability, so that I can prepare for meetings while traveling. (Source: intelligence DB #18)

2. **Shareholder accessing AGM documents online**: As a shareholder, I want to access all AGM documents (agenda, annual report, resolution proposals) through a secure online portal, so that I can prepare for the meeting at my convenience. (Source: intelligence DB #3)

3. **Secretary assembling board pack**: As a board secretary, I want to assemble board packs by combining documents from multiple sources into a structured, indexed package, so that board members receive a complete, well-organized set of meeting materials. (Source: intelligence DB #17)

4. **Board member declaring conflict of interest**: As a board member, I want to formally declare a conflict of interest for a specific agenda item so that I am properly excluded from the decision and this is recorded per WBTR. (Source: intelligence DB #69)

5. **Member accessing decision history**: As a member, I want to access meeting minutes, financial reports, and decision history through a self-service portal so that I can stay informed about association governance. (Source: intelligence DB #80)

## Acceptance Criteria

- Calendar events are created/updated/cancelled for meetings via OCP\Calendar\IManager
- Meeting folders are created in Nextcloud Files with correct access controls
- Talk conversations are created for meetings with participant auto-enrollment
- Activity feed entries are published for all major governance events
- Notifications are sent for pending votes, meeting reminders, and deadlines
- Search provider returns decisions, meetings, and resolutions from universal search
- All integrations use OCP interfaces (not direct database access or internal APIs)
- Integrations degrade gracefully when optional apps (Talk, Mail) are not installed
