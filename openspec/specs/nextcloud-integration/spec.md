---
status: idea
---

# Nextcloud Integration Specification

## Purpose

Decidesk leverages Nextcloud's platform capabilities to provide a seamless governance experience without reinventing existing functionality. This specification covers integration with 10 Nextcloud subsystems: Calendar (meeting scheduling), Files (document management), Mail (convocation delivery and vote-by-email), Talk (deliberation channels), Tasks (action item tracking), Contacts (participant matching), Activity (audit feed), Notifications (state-change alerts), Search (unified search), and References (Smart Picker rich link previews). Each integration uses the appropriate OCP interface and degrades gracefully when optional apps are not installed.

**Standards**: Nextcloud OCP interfaces, CalDAV (RFC 4791), WebDAV, CardDAV
**Feature tier**: V1

**Evidence sources**: Intelligence DB user stories #1, #2, #3, #7, #8, #11, #12, #15, #17, #19, #20, #46, #47, #49, #50, #54, #57, #65, #67, #69, #75, #80, #87, #88, #91, #92, #93, #129, #136, #137, #138, #140, #141, #142, #143, #147, #151, #152, #164, #171, #172, #174, #176, #177, #182, #183, #184; Requirement clusters #34 (Notifications, 451 reqs/153 tenders), #44 (Teams integration, 315 reqs/133 tenders), #54 (e-Depot, 247 reqs/117 tenders), #55 (Document creation/generation, 298 reqs/116 tenders); Category features: calendar-integration, document-storage, document-linking, notifications, search-filter, full-text-search

## Roadmap

### Phase 1 - MVP Foundation (V1)
Calendar, Files, Notifications, Activity, Search

### Phase 2 - Collaboration (V1.1)
Talk, Tasks, Contacts, References

### Phase 3 - Advanced Communication (V2)
Mail (convocation delivery, vote-by-email), advanced file workflows (board pack assembly, document comparison)

## Requirements

---

### REQ-NI-01: Calendar Integration

The system MUST create Nextcloud Calendar events for scheduled meetings via `OCP\Calendar\IManager`. Calendar events MUST include meeting title, date/time, location, body, and a link back to the Decidesk meeting. Changes to the meeting schedule MUST update the calendar event. Cancellations MUST cancel the calendar event.

**Feature tier**: V1

#### Scenario: Create calendar event when meeting is scheduled

- GIVEN a meeting "Board Meeting Q2" scheduled for 2026-07-15 14:00-16:00 in "Boardroom A"
- WHEN the meeting is created in Decidesk
- THEN a calendar event MUST be created in each attendee's Nextcloud Calendar
- AND the event MUST include the meeting link, agenda summary, and document links in the description
- AND the event MUST have a reminder set to the user's configured preference (default: 24h + 1h before)
- AND the event location MUST be set to the meeting location or video call URL

#### Scenario: Update calendar event when meeting is rescheduled

- GIVEN a meeting with associated calendar events
- WHEN the meeting date is changed from July 15 to July 22
- THEN all attendee calendar events MUST be updated to the new date
- AND attendees MUST receive a notification about the schedule change
- AND the event description MUST reflect any updated agenda information

#### Scenario: Cancel calendar event when meeting is cancelled

- GIVEN a meeting with associated calendar events
- WHEN the meeting is cancelled
- THEN all attendee calendar events MUST be cancelled (status CANCELLED in CalDAV)
- AND attendees MUST receive a cancellation notification
- AND the calendar event MUST NOT be deleted (preserved for history)

#### Scenario: Add new participant to existing meeting

- GIVEN a meeting with calendar events for 5 existing participants
- WHEN a 6th participant is added to the meeting
- THEN a calendar event MUST be created for the new participant
- AND existing participants' events MUST NOT be affected
- AND the new participant MUST receive a meeting invitation notification

---

### REQ-NI-02: Files Integration

The system MUST store and manage meeting documents using Nextcloud Files via `OCP\Files\IRootFolder`. Each meeting MUST have a dedicated folder in a configurable location. Document access controls MUST follow governance roles.

**Feature tier**: V1

#### Scenario: Create meeting folder structure on meeting creation

- GIVEN a meeting "Board Meeting Q2 2026" for body "Board of Directors"
- WHEN the meeting is created
- THEN a folder MUST be created at the configured path (default: `Decidesk/Board of Directors/2026-07-15 Board Meeting Q2/`)
- AND subfolders MUST be created for "Agenda Documents", "Minutes", and "Resolutions"
- AND all body members MUST have read access; secretary and chair MUST have write access

#### Scenario: Attach document to agenda item via file picker

- GIVEN a user editing an agenda item
- WHEN they click "Attach Document" and select a file from Nextcloud Files
- THEN the file MUST be linked to the agenda item in the OpenRegister object
- AND the file MUST be accessible to all meeting participants via the meeting folder
- AND the file MUST appear in the meeting document package (board pack)

#### Scenario: Assemble board pack from multiple sources

- GIVEN a secretary preparing documents for a board meeting with 8 agenda items
- WHEN they compile the board pack
- THEN the system MUST assemble all agenda item documents into a structured package
- AND the package MUST include a cover page with the agenda overview
- AND the package MUST be distributable as a single PDF or as individual files in the meeting folder

#### Scenario: Compare document versions

- GIVEN a document attached to an agenda item that has been updated
- WHEN a user views the document history
- THEN the system MUST show all versions of the document via Nextcloud Files versioning
- AND the user MUST be able to compare versions to see what changed
- AND the latest version MUST be clearly indicated

---

### REQ-NI-03: Talk Integration

The system MUST create Nextcloud Talk conversations for meetings via `OCP\Talk\IBroker`. Meeting participants MUST be automatically added. The conversation MUST serve as the communication channel for meeting preparation, deliberation, and follow-up.

**Feature tier**: V1.1

#### Scenario: Create Talk conversation for a meeting

- GIVEN a meeting "ALV 2026" with 50 participants
- WHEN the meeting is created
- THEN a Talk conversation MUST be created with all participants
- AND the conversation name MUST be "ALV 2026"
- AND the conversation description MUST include the meeting date and agenda link
- AND the conversation type MUST be "group" (not public)

#### Scenario: Create Talk channel for a governing body

- GIVEN a body "Bestuur" with 5 members
- WHEN the body is created (or when Talk integration is first enabled)
- THEN a persistent Talk conversation MUST be created for the body
- AND all body members MUST be added as participants
- AND the channel MUST persist across meetings for ongoing governance communication
- AND member changes in the body MUST automatically update the Talk participant list

#### Scenario: Link Talk messages to agenda items

- GIVEN a meeting Talk conversation
- WHEN participants discuss specific agenda items before the meeting
- THEN the system SHOULD allow tagging messages with agenda item references
- AND tagged messages MUST be retrievable from the agenda item detail view
- AND this provides a discussion thread per agenda item

---

### REQ-NI-04: Activity Integration

The system MUST publish Decidesk events to the Nextcloud Activity feed via `OCP\Activity\IManager`. Events MUST provide a complete audit trail of governance actions.

**Feature tier**: V1

#### Scenario: Decision status change appears in Activity feed

- GIVEN a decision "Approve Budget 2026" transitions from "deliberating" to "voting"
- WHEN the transition is completed
- THEN an Activity entry MUST be created: "Decision 'Approve Budget 2026' moved to voting"
- AND the entry MUST be visible to all members of the governing body
- AND clicking the activity MUST navigate to the decision in Decidesk

#### Scenario: Meeting lifecycle events in Activity feed

- GIVEN a meeting progressing through its lifecycle
- WHEN the meeting is created, convoked, started, and completed
- THEN Activity entries MUST be created for each transition
- AND the entries MUST include: meeting title, body, date, and actor (who performed the action)
- AND the Activity provider MUST use Decidesk-specific icons and formatting

#### Scenario: Vote completion appears in Activity feed

- GIVEN a vote on "Policy Update" that has just closed
- WHEN the vote results are finalized
- THEN an Activity entry MUST be created: "Vote on 'Policy Update' completed: Adopted (12 for, 3 against, 1 abstain)"
- AND the entry MUST be visible to all body members
- AND for secret ballots, individual votes MUST NOT be included in the activity

#### Scenario: Resolution adoption in Activity feed

- GIVEN a resolution formally adopted
- WHEN the resolution status changes to "adopted"
- THEN an Activity entry MUST be created with the resolution title, sequence number, and adopting body
- AND the entry MUST include a link to the published resolution

---

### REQ-NI-05: Notification Integration

The system MUST send Nextcloud Notifications via `OCP\Notification\IManager` for time-sensitive governance events. Notifications MUST respect user preferences configured in user settings.

**Feature tier**: V1

#### Scenario: Send pending vote notification

- GIVEN a new vote has been initiated for decision "Policy Update"
- WHEN the vote opens
- THEN all eligible voters MUST receive a Nextcloud notification
- AND the notification MUST include the decision title, body, voting method, and deadline
- AND tapping the notification MUST open the voting interface

#### Scenario: Send voting deadline reminder

- GIVEN a vote with deadline tomorrow and user has not yet voted
- WHEN the reminder timing is triggered (24 hours before deadline)
- THEN the user MUST receive a notification: "Reminder: Your vote on 'Policy Update' is due tomorrow"
- AND the notification MUST be styled with warning priority

#### Scenario: Send meeting convocation notification

- GIVEN a meeting "ALV 2026" is being convoked
- WHEN the secretary sends the convocation
- THEN all body members MUST receive a notification with the meeting date, agenda link, and document package link
- AND the notification MUST include the statutory deadline information
- AND delivery MUST be tracked (notification read/dismissed)

#### Scenario: Send action item due notification

- GIVEN an action item "Submit financial report" assigned to the treasurer with due date tomorrow
- WHEN the reminder timing is triggered
- THEN the treasurer MUST receive a notification with the action item title, source decision, and due date
- AND overdue action items MUST generate escalating notifications (24h overdue, 72h overdue)

#### Scenario: Send quorum-at-risk notification

- GIVEN a meeting in progress with quorum currently met
- WHEN a member leaves and quorum drops to within 1 member of the threshold
- THEN the chair and secretary MUST receive an immediate notification: "Quorum at risk: 11/21 present (minimum 11 required)"
- AND the notification MUST be high priority

---

### REQ-NI-06: Search Integration

The system MUST register a search provider via `OCP\Search\IProvider` so that decisions, meetings, resolutions, motions, and minutes are findable from Nextcloud's unified search.

**Feature tier**: V1

#### Scenario: Find a decision via Nextcloud search

- GIVEN decisions exist including "Budget 2026 Approval"
- WHEN the user searches for "budget" in Nextcloud's universal search
- THEN the Decidesk search provider MUST return "Budget 2026 Approval" as a result
- AND the result MUST show the decision title, status, body, and date
- AND clicking the result MUST navigate to the decision detail view in Decidesk

#### Scenario: Search across multiple entity types

- GIVEN the user searches for "sustainability"
- WHEN results are returned
- THEN the search MUST find matches across decisions, meetings, resolutions, motions, and minutes
- AND results MUST be grouped by entity type with clear labels
- AND the search MUST cover both titles and full-text content

#### Scenario: Search respects access controls

- GIVEN a user who is a member of body "Bestuur" but not body "RvC"
- WHEN they search for a decision that exists in both bodies
- THEN only the "Bestuur" decision MUST appear in results
- AND "RvC" decisions MUST be filtered out based on body membership

---

### REQ-NI-07: Tasks Integration

The system MUST create Nextcloud Tasks for action items via `OCP\AppFramework\IAppContainer` or direct CalDAV VTODO. Action items from decisions MUST be trackable with owners, deadlines, and status updates.

**Feature tier**: V1.1

#### Scenario: Create task from decision action item

- GIVEN a decision "Approve Budget" with action item "Submit quarterly report" assigned to the treasurer, due 2026-09-30
- WHEN the action item is created in Decidesk
- THEN a Nextcloud Task MUST be created in the treasurer's task list
- AND the task MUST include the action item title, description, due date, and a link back to the source decision
- AND completing the task in Nextcloud Tasks MUST update the action item status in Decidesk

#### Scenario: Track action item completion across meetings

- GIVEN 5 open action items from the previous board meeting
- WHEN the secretary prepares the agenda for the next meeting
- THEN a "Review action items" agenda item MUST show all open items with their current status
- AND overdue items MUST be highlighted
- AND the status MUST be synchronized from Nextcloud Tasks

#### Scenario: Automatic rollover of incomplete action items

- GIVEN a meeting has ended with 2 action items still open
- WHEN the next meeting is created for the same body
- THEN open action items MUST be automatically added to the new meeting agenda
- AND the items MUST show which meeting they originated from

---

### REQ-NI-08: Contacts Integration

The system MUST use Nextcloud Contacts via `OCP\Contacts\IManager` to match and enrich participant data. External participants (notaries, auditors, guests) MUST be resolvable from the address book.

**Feature tier**: V1.1

#### Scenario: Match member to Nextcloud contact

- GIVEN a body member "Jan de Vries" with email jan@example.nl
- WHEN the system resolves the member's contact information
- THEN it MUST search Nextcloud Contacts for a matching entry
- AND if found, the member profile MUST be enriched with contact photo, phone number, and organization
- AND the contact link MUST be maintained for ongoing synchronization

#### Scenario: Add external participant from contacts

- GIVEN a meeting that requires a notary's attendance
- WHEN the secretary adds an external participant
- THEN the system MUST offer a Nextcloud Contacts search to find the notary
- AND the notary MUST be added as a non-voting guest participant
- AND the notary MUST receive meeting notifications but NOT have access to confidential body documents

---

### REQ-NI-09: References (Smart Picker) Integration

The system MUST register a reference provider via `OCP\Collaboration\Reference\IReferenceProvider` so that Decidesk entities can be embedded as rich link previews in Nextcloud Text, Talk, and Mail.

**Feature tier**: V1.1

#### Scenario: Embed decision reference in Talk message

- GIVEN a user discussing a decision in a Talk conversation
- WHEN they paste a Decidesk decision URL
- THEN the URL MUST be rendered as a rich preview showing: decision title, status badge, body, date, and vote result summary
- AND clicking the preview MUST navigate to the decision in Decidesk

#### Scenario: Embed meeting reference in Nextcloud Text

- GIVEN a user writing meeting preparation notes in a Nextcloud Text document
- WHEN they use the Smart Picker to search for a Decidesk meeting
- THEN the picker MUST search Decidesk meetings by title and date
- AND the selected meeting MUST be embedded as a rich card with title, date, body, agenda summary, and status

#### Scenario: Embed resolution in email

- GIVEN a secretary drafting an email about an adopted resolution
- WHEN they paste a Decidesk resolution URL
- THEN the URL MUST be rendered as a rich preview with resolution title, sequence number, adoption date, and body

---

### REQ-NI-10: Mail Integration

The system MUST support sending governance communications via Nextcloud Mail or configured SMTP. This includes convocation delivery, meeting document distribution, and vote-by-email for authorized scenarios.

**Feature tier**: V2

#### Scenario: Send convocation via email

- GIVEN a meeting "ALV 2026" being convoked to 200 members
- WHEN the secretary triggers convocation delivery
- THEN each member MUST receive an email with: meeting title, date/time, location, agenda, document links, and RSVP link
- AND the email MUST be sent from the configured governance email address
- AND delivery status (sent, delivered, bounced) MUST be tracked per recipient
- AND the convocation MUST include the statutory deadline calculation

#### Scenario: Distribute minutes via email

- GIVEN approved minutes for "Board Meeting Q2 2026"
- WHEN the secretary distributes the minutes
- THEN all body members MUST receive an email with the minutes attached as PDF
- AND the email MUST include a link to the online version in Decidesk
- AND distribution MUST be logged in the Activity feed

#### Scenario: Vote-by-email for circular resolution

- GIVEN a circular resolution requiring unanimous board consent
- WHEN a board member receives the vote-by-email
- THEN they MUST be able to reply with "FOR", "AGAINST", or "ABSTAIN"
- AND the system MUST parse the reply and record the vote
- AND the member MUST receive confirmation of their recorded vote
- AND vote-by-email MUST only be available for bodies where the administrator has enabled it

---

### REQ-NI-11: Graceful Degradation

The system MUST detect which optional Nextcloud apps are installed and gracefully disable integrations for missing apps. Core Decidesk functionality MUST work without any optional apps installed.

**Feature tier**: V1

#### Scenario: Talk app not installed

- GIVEN a Nextcloud instance without the Talk app
- WHEN Decidesk creates a meeting
- THEN the Talk conversation creation MUST be silently skipped
- AND the meeting interface MUST NOT show "Open Talk channel" buttons
- AND no errors MUST be logged for the missing integration

#### Scenario: Calendar app not installed

- GIVEN a Nextcloud instance without the Calendar app
- WHEN Decidesk creates a meeting
- THEN calendar event creation MUST be silently skipped
- AND the meeting MUST still function for scheduling, agenda, and voting
- AND the admin settings MUST show which integrations are available vs. unavailable

#### Scenario: Check integration availability at runtime

- GIVEN an administrator in integration settings
- WHEN they view the integration configuration page
- THEN each integration MUST show its current status: "Available" (app installed), "Not available" (app not installed), or "Disabled" (manually disabled)
- AND a description MUST explain what each integration provides

---

### REQ-NI-12: Audit Trail Completeness

The system MUST ensure that all governance-critical actions are recorded in an immutable audit trail via the Activity integration. The audit trail MUST satisfy WBTR documentation requirements.

**Feature tier**: V1

#### Scenario: Complete audit trail for a decision lifecycle

- GIVEN a decision that progresses through its full lifecycle
- WHEN the decision is created, deliberated, voted on, adopted, and a resolution is published
- THEN the Activity feed MUST contain entries for every status transition
- AND each entry MUST include: timestamp, actor (who), action (what), and context (decision title, body)
- AND the audit trail MUST be exportable as a PDF report for compliance purposes

#### Scenario: Vote audit trail preserves integrity

- GIVEN a completed vote with 15 ballots
- WHEN the vote results are reviewed
- THEN the audit trail MUST show when the vote was opened, each ballot timestamp, and when the vote was closed
- AND for open/roll call votes, each voter's choice MUST be recorded
- AND for secret ballots, only aggregate results MUST be in the trail (not individual choices)

---

### REQ-NI-13: Archive Integration

The system MUST support archiving governance records to Nextcloud Files and optionally to e-Depot (digital archive) systems. Archived records MUST include complete metadata (MDTO where applicable).

**Feature tier**: V2

#### Scenario: Auto-archive completed meeting records

- GIVEN a meeting that has been completed with approved minutes
- WHEN the archival period triggers (configurable, e.g., 30 days after meeting)
- THEN all meeting records (agenda, documents, minutes, decisions, votes, resolutions) MUST be archived
- AND the archive MUST include complete metadata for each document
- AND archived records MUST remain searchable but marked as archived

#### Scenario: Verify archive completeness

- GIVEN a request to verify the archive for body "Gemeenteraad" year 2025
- WHEN the archivist runs the completeness check
- THEN the system MUST verify that all meetings have: agenda, minutes, all decisions with vote results, and all resolutions
- AND missing records MUST be flagged with specific gaps identified
- AND a completeness report MUST be generated

---

### REQ-NI-14: Video Recording Index

The system MUST support linking video recordings of meetings and indexing them by agenda item for efficient navigation.

**Feature tier**: V2

#### Scenario: Index video recording by agenda item

- GIVEN a recorded council meeting with 12 agenda items
- WHEN the secretary adds timestamp markers for each agenda item
- THEN viewers MUST be able to jump directly to the discussion of any agenda item
- AND the video timeline MUST show agenda item markers
- AND the meeting detail page MUST link each agenda item to its video segment

## User Stories

1. **Board secretary creating AGM agenda**: As a board secretary, I want to create and manage the AGM agenda with drag-and-drop ordering, so that I can efficiently prepare a compliant meeting agenda. (Source: intelligence DB #1, priority: must)

2. **Board secretary distributing convocation notices**: As a board secretary, I want to distribute convocation notices via email with delivery tracking, so that I meet statutory notice requirements. (Source: intelligence DB #2, priority: must)

3. **Shareholder accessing AGM documents online**: As a shareholder, I want to access all AGM documents through a secure online portal, so that I can prepare for the meeting. (Source: intelligence DB #3, priority: must)

4. **Board secretary conducting live voting**: As a board secretary, I want to conduct live voting during the AGM with real-time results. (Source: intelligence DB #7, priority: must)

5. **Shareholder tracking resolution outcomes**: As a shareholder, I want to see voting results for each resolution immediately after the vote. (Source: intelligence DB #8, priority: must)

6. **Board secretary taking digital minutes**: As a board secretary, I want to take structured minutes during the AGM using a digital template. (Source: intelligence DB #11, priority: must)

7. **Notary accessing meeting information**: As a notary, I want secure read-only access to meeting agenda, attendee list, and voting results for notarial minutes. (Source: intelligence DB #12, priority: should)

8. **CEO creating structured decision proposal**: As a CEO, I want to create a structured decision proposal with options analysis and risk assessment. (Source: intelligence DB #15, priority: must)

9. **Board secretary assembling board pack**: As a board secretary, I want to assemble board packs by combining documents from multiple sources into a structured package. (Source: intelligence DB #17, priority: should)

10. **Board secretary tracking action items**: As a board secretary, I want to assign, track, and report on board action items with due dates and owners. (Source: intelligence DB #19, priority: must)

11. **CEO approving board minutes digitally**: As a CEO, I want to review and approve board minutes digitally with tracked changes. (Source: intelligence DB #20, priority: should)

12. **Secretary sending ALV convocation**: As secretary, I want to send the ALV convocation to all voting members with delivery confirmation. (Source: intelligence DB #46, priority: critical)

13. **Member receiving ALV invitation with agenda**: As a member, I want to receive the ALV invitation with complete agenda and supporting documents. (Source: intelligence DB #47, priority: high)

14. **Chair composing ALV agenda with required items**: As chair, I want to compose the ALV agenda ensuring all legally required items are included. (Source: intelligence DB #49, priority: critical)

15. **Treasurer attaching financial documents**: As treasurer, I want to upload financial statements and budget proposal to the ALV agenda. (Source: intelligence DB #50, priority: critical)

16. **Member submitting motion for ALV agenda**: As a member, I want to submit a motion for the ALV agenda with supporting arguments. (Source: intelligence DB #54, priority: high)

17. **Secretary preparing digital meeting package**: As secretary, I want to prepare a digital meeting package with agenda, previous minutes, and action items. (Source: intelligence DB #65, priority: high)

18. **Secretary tracking action items from decisions**: As secretary, I want to assign action items with owners and deadlines from meeting decisions. (Source: intelligence DB #67, priority: medium)

19. **Board member declaring conflict of interest**: As a board member, I want to formally declare a conflict of interest for a specific agenda item. (Source: intelligence DB #69, priority: high)

20. **Secretary drafting and distributing ALV minutes**: As secretary, I want to draft the ALV minutes and distribute them to members within the statutory timeframe. (Source: intelligence DB #75, priority: high)

21. **Member accessing documents and decision history**: As a member, I want to access meeting minutes, financial reports, and decision history through a self-service portal. (Source: intelligence DB #80, priority: medium)

22. **MT member submitting agenda item with documents**: As an MT member, I want to submit agenda items with supporting documents through a structured form. (Source: intelligence DB #87, priority: high)

23. **Management assistant compiling MT agenda package**: As a management assistant, I want to compile agenda items into a structured agenda and distribute to all MT members. (Source: intelligence DB #88, priority: high)

24. **Management assistant assigning action items**: As a management assistant, I want to assign action items to MT members with deadlines and track completion. (Source: intelligence DB #91, priority: high)

25. **MT member updating action item status**: As an MT member, I want to update the status of my action items directly from the platform. (Source: intelligence DB #92, priority: medium)

26. **Management assistant generating minutes**: As a management assistant, I want to generate structured minutes from notes and decisions captured during the meeting. (Source: intelligence DB #93, priority: high)

27. **Employee searching decision register**: As an employee, I want to search a central decision register by topic, date, meeting, or decision-maker. (Source: intelligence DB #129, priority: high)

28. **Griffier creating and publishing meeting agenda**: As a griffier, I want to create a meeting agenda and publish the complete package. (Source: intelligence DB #136, #138, priority: must)

29. **Raadslid annotating meeting documents**: As a raadslid, I want to highlight text and add notes to meeting documents on my tablet. (Source: intelligence DB #140, priority: must)

30. **Citizen subscribing to decision notifications**: As a burger, I want to receive notifications when the council makes decisions on topics I follow. (Source: intelligence DB #176, priority: could)

31. **Griffier submitting draft minutes for approval**: As a griffier, I want to submit draft minutes with tracked corrections for approval at the next meeting. (Source: intelligence DB #177, priority: must)

32. **Archivaris auto-archiving council decisions**: As an archivaris, I want council decisions to be automatically archived with complete metadata. (Source: intelligence DB #182, priority: must)

33. **Archivaris verifying archive completeness**: As an archivaris, I want to verify that all council meeting records are complete in the archive. (Source: intelligence DB #183, priority: must)

34. **Raadsadviseur indexing video by agenda item**: As a raadsadviseur, I want video recordings to be indexed by agenda item for direct navigation. (Source: intelligence DB #184, priority: should)

## Acceptance Criteria

1. Calendar events are created/updated/cancelled for meetings via `OCP\Calendar\IManager`
2. Calendar events include meeting link, agenda summary, document links, and configured reminders
3. Meeting folders are created in Nextcloud Files with correct access controls (read for members, write for secretary/chair)
4. Board pack assembly compiles agenda documents into a structured package
5. Document version comparison is supported via Nextcloud Files versioning
6. Talk conversations are created for meetings with participant auto-enrollment
7. Persistent body Talk channels are maintained with automatic membership sync
8. Activity feed entries are published for all governance lifecycle events (decision, meeting, vote, resolution)
9. Activity entries include actor, action, timestamp, and navigable link to the entity
10. Notifications are sent for pending votes, meeting reminders, action item due dates, and quorum-at-risk
11. Notification priority and timing respect user preferences from user settings
12. Search provider returns decisions, meetings, resolutions, motions, and minutes from unified search
13. Search respects body membership access controls
14. Tasks are created for action items with bidirectional status sync
15. Open action items automatically roll over to next meeting agenda
16. Contacts integration enriches member profiles and supports external participant lookup
17. Reference provider renders rich previews for decisions, meetings, and resolutions in Talk, Text, and Mail
18. Mail integration supports convocation delivery with delivery tracking and statutory deadline calculation
19. Vote-by-email is supported for circular resolutions with configurable per-body enablement
20. All integrations degrade gracefully when optional apps are not installed
21. Admin settings show integration availability status (available/not available/disabled)
22. Audit trail satisfies WBTR documentation requirements with exportable reports
23. Archive completeness verification checks all meeting record types
24. Video recording indexing by agenda item is supported with timestamp markers
25. All integrations use OCP interfaces (not direct database access or internal APIs)
