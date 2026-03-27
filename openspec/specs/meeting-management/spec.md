---
status: idea
---

# Meeting Management Specification

**Status**: idea
**Standards**: Schema.org (`Event`, `EventAttendanceModeEnumeration`), Akoma Ntoso (`debate`, `session`), OpenRaadsinformatie (`Vergadering`, `Zitting`), Gemeentewet, BW Boek 2, Provinciewet, Waterschapswet, Woo, Digitoegankelijk (EN 301 549)
**Feature tier**: MVP

## Purpose

Meeting management covers the full lifecycle of governance meetings: creation, scheduling, attendance tracking, quorum verification, and meeting conduct. Meetings are the primary container for agenda items, decisions, and minutes. The system supports physical, digital, and hybrid meeting formats for governance bodies, associations (ALV/ledenraad), corporate boards, and operational teams. Market research shows $541B is wasted on meetings globally (insight #1), AI meeting tool usage grew 17x in 2024 (insight #5), and 78% of workers say they have too many meetings. No self-hosted AI meeting solution exists (insight #9).

**Legal reference**: BW 2:38 (association ALV), Gemeentewet 17-20 (council meetings), BW 2:227 (BV shareholder meeting), Provinciewet (provincial meetings), Waterschapswet (water board meetings)

## Data Model

See [ARCHITECTURE.md](../../architecture/README.md) for the full Meeting entity definition including property tables, Schema.org mappings, and OpenRaadsinformatie alignment.

| Entity | Schema.org Type | Key Properties |
|--------|----------------|----------------|
| Meeting | `schema:Event` | title, startDate, endDate, location, virtualLocation, eventAttendanceMode, body, meetingType, status |
| Attendance | `schema:JoinAction` | participant, meeting, attendanceType (present/remote/proxy), checkInTime |
| Proxy | `schema:AuthorizeAction` | grantor, grantee, meeting, scope |
| MeetingRecording | `schema:VideoObject` | meeting, contentUrl, duration, captions |

## Requirements

---

### Requirement: Meeting Creation and Scheduling [MVP]

The system MUST support creating meetings with a title, date/time, location (physical/digital/hybrid), governing body, and meeting type. Meetings MUST be stored as OpenRegister objects in the `decidesk` register using the `meeting` schema. The system MUST support scheduling recurring meetings.

#### Scenario: Create a board meeting with physical location

- GIVEN a user with meeting management access
- WHEN they create a meeting with title "Board Meeting Q1 2026", date "2026-04-15 14:00", location "Boardroom A", body "Board of Directors", type "regular"
- THEN the system MUST create an OpenRegister object with `@type` set to `schema:Event`
- AND the `eventAttendanceMode` MUST be set to `schema:OfflineEventAttendanceMode`
- AND the meeting MUST appear in the meeting list

#### Scenario: Create a hybrid ALV meeting

- GIVEN a user with meeting management access
- WHEN they create a meeting with title "ALV 2026", type "general_assembly", and attendance mode "hybrid" with both physical address and video conference link
- THEN the `eventAttendanceMode` MUST be set to `schema:MixedEventAttendanceMode`
- AND both `location` (physical) and `virtualLocation` (video link) MUST be stored

#### Scenario: Schedule a recurring monthly meeting

- GIVEN a user with meeting management access
- WHEN they create a meeting with recurrence "monthly, every 2nd Tuesday at 14:00"
- THEN the system MUST generate individual meeting instances for the specified period
- AND each instance MUST be independently editable
- AND each instance MUST automatically get a new agenda object linked to it

#### Scenario: Sync meetings to Nextcloud Calendar via CalDAV

- GIVEN a meeting is created in Decidesk
- WHEN the meeting is saved
- THEN it MUST appear in the user's Nextcloud Calendar as a virtual calendar via OpenRegister _calendar metadata
- AND CalDAV sync MUST work to any device (insight #26)

---

### Requirement: Meeting Convocation and Notice [MVP]

The system MUST support sending meeting convocations (uitnodigingen) to all members of the governing body within configurable notice periods. The system MUST track delivery status per recipient.

**Legal reference**: BW 2:225 (42-day notice for NV, 15-day for BV), BW 2:38 (ALV notice per statutes), BW 2:41 (extraordinary ALV within 4 weeks)

#### Scenario: Send ALV convocation within statutory deadline

- GIVEN an ALV meeting scheduled for 2026-06-01 and a notice period of 15 days
- WHEN the secretary sends the convocation on 2026-05-10
- THEN the system MUST distribute the convocation to all voting members
- AND the system MUST record the send timestamp per recipient
- AND a warning MUST be shown if sending within 3 days of the deadline

#### Scenario: Include agenda and supporting documents in convocation

- GIVEN a meeting with a finalized agenda and attached documents
- WHEN the convocation is sent
- THEN the convocation MUST include the complete agenda
- AND links to all supporting documents MUST be included
- AND recipients MUST be able to access documents via the member portal

#### Scenario: Send AGM convocation with 42-day notice for NV

- GIVEN an NV shareholder meeting scheduled for 2026-09-15
- WHEN the secretary prepares the convocation
- THEN the system MUST enforce the 42-day minimum notice period (BW 2:225)
- AND the system MUST warn if the convocation is being sent too late
- AND the convocation MUST include all resolution proposals

#### Scenario: Handle extraordinary ALV request from members

- GIVEN 10%+ of members have signed a request for an extraordinary ALV
- WHEN the secretary receives the validated request
- THEN the system MUST create an extraordinary meeting within the 4-week deadline (BW 2:41)
- AND members who signed the request MUST be notified of the scheduled date
- AND if the board does not comply, the system MUST warn that members may convene themselves

---

### Requirement: Attendance and Quorum Tracking [MVP]

The system MUST track attendance for each meeting and automatically calculate quorum based on the governing body's rules. Proxy votes (volmachten) MUST count toward quorum. The system MUST prevent voting from starting until quorum is confirmed.

**Legal reference**: BW 2:38 (ALV quorum), Gemeentewet Art. 20 (council quorum >50% of seated members), Art. 29 (second meeting without quorum)

#### Scenario: Register attendance and verify quorum is met

- GIVEN a meeting for a body with 15 members and quorum requirement of 50%+1 (8 members)
- WHEN 10 members check in (8 present, 2 via proxy)
- THEN the system MUST show quorum status as "met" with 10/15 (67%)
- AND voting MUST be enabled for the meeting

#### Scenario: Quorum not met -- meeting cannot proceed to voting

- GIVEN a meeting for a body with 15 members and quorum requirement of 50%+1
- WHEN only 5 members have checked in
- THEN the system MUST show quorum status as "not met" with 5/15 (33%)
- AND the voting button MUST be disabled with a message explaining quorum is not met
- AND the chair MUST be offered the option to adjourn or wait

#### Scenario: Track proxy votes toward quorum

- GIVEN a member who has granted a digital proxy (volmacht) to another member
- WHEN the proxy holder checks in to the meeting
- THEN both the proxy holder and the represented member MUST count toward quorum
- AND the proxy relationship MUST be visible in the attendance list

#### Scenario: Second meeting proceeds without quorum (Gemeentewet Art. 29)

- GIVEN a council meeting that was adjourned due to lack of quorum
- WHEN a second meeting is called for the same subject
- THEN the system MUST allow the meeting to proceed regardless of attendance count per Gemeentewet Art. 29
- AND this exception MUST be explicitly flagged in the meeting record

#### Scenario: Verify member voting rights

- GIVEN an ALV with members of different categories
- WHEN members check in
- THEN the system MUST verify each member's voting rights (paid-up membership, correct category)
- AND only eligible members MUST count toward quorum
- AND non-voting members MUST be marked separately in the attendance list

#### Scenario: Digital identity verification for remote participants

- GIVEN a hybrid meeting with remote participants
- WHEN a remote member joins
- THEN the system MUST verify their identity through a configured authentication method
- AND their attendance MUST be recorded as "remote" with verification timestamp
- AND they MUST count toward quorum equally with physical attendees

---

### Requirement: Proxy Vote Management [MVP]

The system MUST support digital proxy (volmacht) management where members grant voting authority to another member or representative.

**Legal reference**: BW 2:38 (proxy allowed if statutes permit), statutes may limit proxies per person

#### Scenario: Grant proxy vote digitally

- GIVEN a member who cannot attend the ALV
- WHEN they grant a proxy to another member via the platform
- THEN the proxy MUST be digitally signed and recorded
- AND the proxy holder MUST receive notification of the granted proxy
- AND the system MUST enforce any statutory limits on proxies per person

#### Scenario: Revoke proxy before meeting

- GIVEN a member who previously granted a proxy
- WHEN they decide to attend the meeting themselves
- THEN they MUST be able to revoke the proxy before the meeting starts
- AND the former proxy holder MUST be notified of the revocation

---

### Requirement: Meeting List and Calendar View [MVP]

The system MUST provide a list view and calendar view of meetings. Users MUST be able to filter by body, status, date range, and meeting type.

#### Scenario: View upcoming meetings in calendar format

- GIVEN the user navigates to the meetings calendar view
- WHEN meetings exist for the current month
- THEN meetings MUST be displayed on their scheduled dates
- AND each meeting MUST show title, time, body, and status indicator

#### Scenario: Filter meetings by body and type

- GIVEN meetings exist for multiple bodies (council, committees, board)
- WHEN the user filters by body "Municipal Council" and type "regular"
- THEN only regular council meetings MUST be displayed
- AND the filter MUST persist across navigation

---

### Requirement: Hybrid and Digital Meeting Support [MVP]

The system MUST support fully digital meetings with identity verification, live participation (audio/video link), and real-time voting. Remote participants MUST have equal participation rights.

**Legal reference**: WDAV (Wet Digitale Algemene Vergadering) passed Tweede Kamer Dec 2025, enables fully digital meetings for associations if statutes permit. WBTR full compliance required by July 2026 (insight #20).

#### Scenario: Join meeting remotely with full participation rights

- GIVEN a hybrid meeting with a video conference link
- WHEN a member joins remotely via the meeting link
- THEN they MUST be able to view the agenda, participate in discussions, cast votes, and submit motions
- AND their attendance MUST be recorded as "remote"
- AND they MUST count toward quorum

#### Scenario: Configure meeting for fully digital mode

- GIVEN an association whose statutes permit digital meetings (post-WDAV)
- WHEN the secretary creates a fully digital meeting
- THEN the `eventAttendanceMode` MUST be set to `schema:OnlineEventAttendanceMode`
- AND identity verification MUST be required for all participants
- AND the system MUST ensure all participants can follow debates, ask questions, and cast votes (BW 2:227a requirements)

#### Scenario: Integrate with Nextcloud Talk for meeting audio/video

- GIVEN a digital meeting
- WHEN the secretary configures the meeting
- THEN the system SHOULD offer Nextcloud Talk as the default video conference provider
- AND a Talk conversation MUST be auto-created and linked to the meeting (insight #27)

---

### Requirement: Meeting Conduct and Speaking Order [MVP]

The system MUST support managing meeting proceedings including speaking order, time tracking, and agenda item progression.

**Legal reference**: Gemeentewet -- Reglement van Orde defines speaking rules. Standard rules: max 5 min speaking time, max 2 speaking terms per topic per faction (source #339).

#### Scenario: Manage speaking order during committee meeting

- GIVEN a committee meeting in progress on a discussion item
- WHEN members request to speak
- THEN the chair MUST be able to manage the speaking list
- AND the system MUST track speaking time per member
- AND time alerts MUST fire when the allocated time is exceeded

#### Scenario: Register citizen to speak at committee meeting (inspreekrecht)

- GIVEN an upcoming committee meeting with public agenda items
- WHEN a citizen registers online to speak about an agenda item
- THEN their registration MUST be recorded with name, topic, and contact details
- AND the committee secretary MUST be notified
- AND the citizen MUST receive a confirmation with time slot and instructions

#### Scenario: Track speaking time for DEI insights

- GIVEN a meeting in progress with speaking time tracking enabled
- WHEN the meeting concludes
- THEN the system MUST provide analytics on speaking time distribution
- AND the system SHOULD highlight imbalances (insight #24: tracking speaking time increased women's participation by 65%)

---

### Requirement: Meeting Recording and Webcasting [V1]

The system SHOULD support recording meetings (audio/video) and publishing them with searchable indexes linked to agenda items.

**Evidence**: Citizens and journalists follow meetings via live webcast (journey #84). Meeting recordings must be captioned for accessibility (DB #185).

#### Scenario: Record meeting and link to agenda items

- GIVEN a council meeting with webcasting enabled
- WHEN the meeting is recorded
- THEN the recording MUST be segmented by agenda item with timestamp markers
- AND citizens MUST be able to jump to specific agenda items in the recording

#### Scenario: Auto-transcribe meeting recordings

- GIVEN a recorded meeting
- WHEN transcription is triggered
- THEN the system MUST use self-hosted speech-to-text (privacy-first)
- AND the transcript MUST be searchable and linked to agenda items
- AND captions MUST be generated for accessibility (WCAG AA compliance)

---

### Requirement: Meeting Templates [V1]

The system SHOULD support meeting templates for recurring meeting types (e.g., ALV template with required statutory items, MT template with standing items).

#### Scenario: Create meeting from ALV template

- GIVEN a meeting template "ALV" with required statutory items (opening, annual report, financial statements, kascommissie report, board elections, any other business, closing)
- WHEN the secretary creates a new ALV meeting from the template
- THEN the agenda MUST be pre-populated with all required items
- AND the secretary MUST be able to add additional items

#### Scenario: Create meeting from MT template

- GIVEN a meeting template "MT Weekly" with standing items (action item review, decisions pending, department updates)
- WHEN a new meeting instance is created from the recurring schedule
- THEN the standing items MUST be pre-populated
- AND open action items from previous meetings MUST be automatically carried over

---

### Requirement: Meeting Minutes and Action Items [MVP]

The system MUST support creating meeting minutes linked to agenda items and extracting action items with owners and deadlines.

#### Scenario: Generate minutes from real-time notes

- GIVEN a meeting in progress with notes captured per agenda item
- WHEN the secretary generates the meeting minutes
- THEN the minutes MUST be structured by agenda item
- AND decisions, voting results, and action items MUST be highlighted
- AND the minutes MUST be available for review within hours, not days

#### Scenario: Auto-create tasks from action items

- GIVEN minutes with action items assigned to specific people
- WHEN the minutes are finalized
- THEN each action item MUST automatically become a CalDAV VTODO task via OpenRegister _todos metadata
- AND the task MUST be assigned to the responsible person with the deadline
- AND completion status MUST sync back to the meeting record

#### Scenario: Submit draft minutes for approval

- GIVEN draft minutes prepared by the griffier
- WHEN submitted for approval at the next meeting
- THEN tracked corrections MUST be visible
- AND the approval process MUST be transparent with version history

---

### Requirement: Meeting Notifications [MVP]

The system MUST send notifications for meeting lifecycle events to relevant stakeholders.

#### Scenario: Notify members of upcoming meeting

- GIVEN a meeting scheduled for next week
- WHEN the configurable reminder period is reached (e.g., 7 days, 1 day, 1 hour before)
- THEN all body members MUST receive a Nextcloud notification
- AND the notification MUST include meeting title, date, location/link, and agenda link
- AND email notifications MUST be sent to external stakeholders (insight #28)

#### Scenario: Notify when meeting documents are published

- GIVEN a meeting with documents just published
- WHEN the documents are made available
- THEN all meeting participants MUST be notified
- AND the system SHOULD track which members have opened the documents (DB #1848)

---

### Requirement: Meeting Cost and Efficiency Analytics [V1]

The system SHOULD track meeting efficiency metrics based on evidence that $37B/year is wasted on unnecessary meetings (source #325).

**Evidence**: 67% of meeting time is wasted (HBR), meeting decision rate and action item completion are key KPIs (source #323), 44% of action items are never completed (source #342).

#### Scenario: Calculate meeting cost in real-time

- GIVEN a meeting in progress with known attendee count
- WHEN the meeting is ongoing
- THEN the system SHOULD display a running cost estimate based on attendee count and configurable hourly rates
- AND historical meeting cost data MUST be available for analytics

#### Scenario: Generate periodic meeting audit report

- GIVEN a set of meetings over a quarter
- WHEN the director requests a meeting audit
- THEN the system MUST produce: meetings per week/person, average duration, decision rate, action item completion rate, attendance rate, agenda adherence
- AND meetings without agendas MUST be flagged (only 37% of meetings use agendas -- source #319)

---

### Requirement: No-Meeting Days and Focus Time [V1]

The system SHOULD support configurable no-meeting days and focus time blocks.

#### Scenario: Configure and enforce no-meeting days

- GIVEN an organization that designates Wednesdays as no-meeting days
- WHEN a user attempts to schedule a meeting on Wednesday
- THEN the system MUST display a warning
- AND compliance tracking MUST show whether no-meeting days are being respected

---

### Requirement: Meeting Document Read Tracking [V1]

The system SHOULD track whether meeting participants have read the distributed documents.

**Evidence**: DB #1847, #1848, #1849 -- chairs want to know if members are prepared

#### Scenario: Track document read confirmation

- GIVEN a meeting with distributed documents
- WHEN members open and review the documents
- THEN the system MUST track read/unread status per member per document
- AND the chair MUST be able to see preparation compliance before the meeting

---

### Requirement: Meeting Accessibility [MVP]

The system MUST comply with Digitoegankelijk (EN 301 549 with WCAG 2.1) for all meeting interfaces.

#### Scenario: Accessible meeting interface for screen readers

- GIVEN a user with a screen reader accessing the meeting view
- WHEN they navigate the agenda, attendance list, and voting interface
- THEN all elements MUST be properly labeled with ARIA attributes
- AND keyboard navigation MUST be fully functional
- AND contrast ratios MUST meet WCAG AA standards

#### Scenario: Add captions to meeting recordings

- GIVEN a published meeting recording
- WHEN a hearing-impaired citizen accesses it
- THEN accurate captions MUST be available
- AND captions MUST be synchronised with the audio track

---

### Requirement: Meeting Data Export and Interoperability [MVP]

The system MUST export meetings in standardized formats for interoperability.

#### Scenario: Export meeting as OpenRaadsinformatie Vergadering/Zitting

- GIVEN a completed council meeting
- WHEN the system exports the meeting data
- THEN the export MUST conform to the OpenRaadsinformatie `Vergadering`/`Zitting` schema
- AND attendance, decisions, and linked documents MUST be included

#### Scenario: Export meeting to iCalendar format

- GIVEN a meeting with date, time, location, and agenda
- WHEN a user exports the meeting
- THEN the system MUST produce a valid .ics file with all meeting details
- AND the file MUST be importable into any calendar application

---

## User Stories (from intelligence database)

### Legislative Domain

1. As a griffier, I want to create a meeting agenda by selecting and ordering proposals from the backlog so that council members and the public can see what will be discussed. (DB #136)
2. As a griffier, I want to publish the complete agenda with all accompanying documents in one action so that everyone can access everything they need. (DB #138)
3. As a voorzitter, I want to classify agenda items as hamerstuk or bespreekstuk so that meeting time is used efficiently. (DB #158)
4. As a burger, I want to register online to speak at a committee meeting about an agenda item that affects me so that my perspective is heard. (DB #160)
5. As a commissiegriffier, I want meetings to be automatically transcribed using speech-to-text so that I can focus on key points. (DB #173)
6. As a griffier, I want to submit draft minutes for approval at the next meeting with tracked corrections. (DB #177)
7. As an archivaris, I want to verify that all council meeting records are complete in the archive. (DB #183)
8. As a toegankelijkheidsmedewerker, I want meeting recordings to have accurate captions for hearing-impaired citizens. (DB #185)
9. As a dijkgraaf, I want to chair water board general assembly meetings with proper procedures. (DB #103, journey: Water Board General Assembly Meeting)
10. As a burger, I want to follow council meetings via live webcast and track which item is being discussed. (DB #84, journey: Live Meeting Following)

### Association Domain

11. As a secretary, I want to send the ALV convocation to all voting members via their preferred channel so that I can prove proper notification within the statutory deadline. (DB #46)
12. As a member, I want to receive the ALV invitation with the complete agenda and supporting documents so that I can prepare for the meeting. (DB #47)
13. As a secretary, I want to verify that an extraordinary ALV request is valid (signed by 10%+ of members) and convene within 4 weeks per BW 2:41. (DB #48)
14. As a secretary, I want to register member attendance (physical and digital) and automatically calculate quorum including proxy votes. (DB #55)
15. As a member, I want to join the ALV remotely via video/audio with ability to speak, vote, and submit motions. (DB #64)
16. As a secretary, I want to prepare a digital meeting package with agenda, previous minutes, action items, and new documents. (DB #65)
17. As a member who cannot attend, I want to grant a proxy to another member digitally. (DB #63)
18. As a cooperative member, I want to participate in the cooperative ALV with proper consideration of liability structure (UA/BA/WA). (DB #31, journey: Cooperative Member Meeting)
19. As a ledenraad member, I want to review the agenda and consult my constituency before the council meeting. (DB #82)

### Corporate Governance Domain

20. As a board secretary, I want to create and manage the AGM agenda with drag-and-drop resolution ordering within statutory deadlines. (DB #1)
21. As a board secretary, I want to distribute convocation notices to all shareholders via their preferred channel meeting the 42-day (NV) or 15-day (BV) deadline. (DB #2)
22. As a shareholder, I want to access all AGM documents through a secure online portal. (DB #3)
23. As a board secretary, I want to configure a hybrid or fully digital AGM with identity verification, live voting, and Q&A. (DB #13)
24. As a board secretary, I want to assemble board packs from multiple sources into a structured, indexed package. (DB #17)
25. As a supervisory board member, I want to access the board pack on my tablet with offline capability. (DB #18)
26. As a board secretary, I want to assign, track, and report on board action items with due dates and owners. (DB #19)
27. As a notary, I want secure access to the meeting agenda, attendee list, and voting results for accurate notarial minutes. (DB #12)

### Corporate Operations Domain

28. As a management assistant, I want to compile submitted agenda items into a structured agenda and distribute the complete package to all MT members. (DB #88)
29. As a secretary, I want to record decisions in real-time during the MT meeting with a structured format. (DB #89)
30. As a management assistant, I want to generate structured minutes from notes and decisions captured during the meeting. (DB #93)
31. As an MT member, I want to update the status of my action items directly from the platform. (DB #92)
32. As a project manager, I want to prepare a steering committee meeting with project status, decision items, and risk overview. (DB #108)
33. As a meeting organizer, I want visible countdown timers per agenda item with configurable time allocations. (DB #334)
34. As a meeting facilitator, I want to display a live cost ticker showing the running cost of the current meeting. (DB #335)
35. As a manager, I want to designate no-meeting days and focus time blocks with compliance tracking. (DB #344)

### Citizen Participation Domain

36. As a coordinator, I want to plan and manage the assembly meeting schedule including information sessions, expert hearings, and deliberation rounds. (DB #223)
37. As a neighbourhood council member, I want residents to submit agenda items digitally so meetings address what the neighbourhood cares about. (DB #247)

### Cross-Domain (Nextcloud Integration)

38. As a member, I want all meetings to appear in my Nextcloud Calendar via CalDAV sync. (DB #1824)
39. As a secretary, I want action items from meeting minutes to automatically become CalDAV VTODO tasks assigned to the responsible person. (DB #1836)
40. As any user, I want to search for meetings, minutes, and related documents from the Nextcloud unified search bar. (DB #1871)
41. As a chair, I want to know which members have opened and read the meeting documents. (DB #1847, #1848, #1849)

## Evidence Sources

### Legal Standards (Mandatory)

| Standard | Scope | Key Requirements |
|----------|-------|-----------------|
| **Gemeentewet** | Municipal meetings | Art. 17-20: meeting rules; Art. 20: quorum >50%; Art. 29: second meeting without quorum |
| **BW Boek 2** | Legal entity meetings | Art. 2:38: ALV rules; Art. 2:225: 42/15-day notice; Art. 2:227: BV meetings; Art. 2:41: extraordinary ALV |
| **Provinciewet** | Provincial States | Similar structure to Gemeentewet for provincial level |
| **Waterschapswet** | Water board meetings | Oldest democratic institutions in NL with unique governance structure |
| **Woo** | Public transparency | Active publication of meeting documents, agendas, decision lists |
| **Digitoegankelijk** | Accessibility | EN 301 549 with WCAG 2.1 for all meeting interfaces |
| **WDAV** | Digital meetings | Wet Digitale Algemene Vergadering -- enables fully digital association meetings (pending Eerste Kamer, insight #20) |

### Forum Standaardisatie Standards (Recommended)

| Standard | Relevance |
|----------|-----------|
| **WebDAV en CalDAV** | Meeting calendar sync and document sharing |
| **PDF/UA** | Accessible meeting document publication |
| **ODF** | Editable meeting document format |
| **Akoma Ntoso** | XML representation of parliamentary sessions |

### External Research & Market Evidence

- **$541B wasted on meetings globally** -- 67% of meeting time is wasted, 23 hrs/week for executives (Doodle/HBR) -- insight #1
- **AI meeting tool usage grew 17x in 2024** -- Otter.ai, Fireflies, Grain, Fathom leading -- insight #5
- **No self-hosted AI meeting solution exists** -- class-action lawsuits over recording consent -- insight #9
- **Women's speaking time increased 65% when tracked** -- DEI impact feature -- insight #24
- **Only 37% of meetings use agendas** -- Fellow State of Meetings 2024 -- source #319
- **US workers spend 20%+ of week in meetings** (35% for senior leaders) -- source #319
- **$37B/year in US on unnecessary meetings** -- mid-level employee $25K/year meeting cost -- source #325
- **44% of action items never completed** -- 71% of meetings fail objectives due to poor follow-through -- source #342
- **67% of professionals say clear agenda is most important meeting element** -- source #320

### Competitor Analysis

| Competitor | Key Meeting Features | Gap |
|-----------|---------------------|-----|
| **Notubiz** | Council meeting publication, webcasting | Called "a true maze" (doolhof) by citizens -- poor search, missing voting records (source #361) |
| **iBabs** | Board portal, document distribution | Rated 4.6/5 but large documents cause problems; limited AI/analytics (source #372) |
| **Parlaeus** | Council decision workflow, AI search (MAAT) | Legislative-only, no corporate/association |
| **Diligent** | Enterprise board governance, 700K+ directors | AI minutes generation; closed source, enterprise pricing |
| **ConveneAGM** | Virtual/hybrid AGM, live voting, Q&A | Corporate governance only |
| **Fellow** | AI meeting notes, agenda collaboration | No formal voting or governance compliance |
| **OpenSlides** | Open source assembly system, 4 voting modes | Assembly-focused, no broader governance |
| **Congressus** | Dutch association software, ALV support | Member administration focus, limited meeting management |
| **Sherpany** | Swiss enterprise meeting management, 45% productivity boost | Corporate boards only, ISO 27001 certified |
| **BoardEffect** | Board management, G2 4.5/5 rating | Limited meeting minutes, server downtime issues |

### Tender Requirements

- **W-BESL**: "Beschrijf ondersteuning bestuurlijk besluitvormingsproces inclusief agendabeheer, vergaderingen, publiceren en archiveren" (70 pts)
- **W9**: "Beschrijf hoe de Oplossing het proces van bestuurlijke besluitvorming ondersteunt" (68 pts)
- **SGC 3**: "Volgen en terugkijken van vergaderingen" (15 pts) -- RIS/BIS tender
- **W11**: "Bestuurlijk besluitvormingsproces in Oplossing. College en gemeenteraad. Paraferen. iBabs voor agendabeheer" (48 pts)
- **W13**: "Beschrijf ondersteuning voor het volledige BBV-proces (10 stappen) inclusief agendabeheer, vergaderbehandeling, publicatie" (56 pts)

## Customer Journeys

### Legislative Domain
- **Agenda Preparation & Setting** -- Griffier creates meeting agenda from proposal backlog
- **Document Package Review & Preparation** -- Council members review documents on tablet
- **Presidium Agenda Setting** -- Faction leaders set agenda, classify items
- **Committee Information Gathering (Beeldvorming)** -- Citizens speak at committee meetings
- **Plenary Debate & Decision-Making (Besluitvorming)** -- Full council meeting proceedings
- **Minute Taking & Recording** -- Secretary records proceedings
- **Minutes Finalization & Approval** -- Draft minutes approved at next meeting
- **Meeting Recording Publication** -- Video indexed by agenda item, captioned
- **Live Meeting Following & Webcasting** -- Real-time public access
- **Records Archiving & Compliance** -- Archiefwet-compliant archiving
- **Water Board General Assembly Meeting** -- Dijkgraaf-chaired, 6x/year minimum
- **Provincial States Meeting Cycle** -- CdK-chaired, larger scale

### Association Domain
- **ALV Convocation & Scheduling** -- Statutory notice periods, member notification
- **ALV Attendance & Quorum Verification** -- Presentielijst, digital check-in, proxy counting
- **Digital/Hybrid ALV Participation** -- Remote participation with identity verification
- **Board Meeting Preparation** -- Agenda, documents, WBTR compliance
- **Board Action Item Tracking** -- Post-meeting follow-through
- **Written Board Resolution (Outside Meeting)** -- BW 2:40 written procedure
- **Meeting Minutes Preparation & Approval** -- Notulen within statutory timeframe
- **Cooperative Member Meeting (ALV)** -- UA/BA/WA liability considerations
- **Member Council Meeting** -- Ledenraad as representative body (KNVB, FNV, ANWB)
- **Extraordinary ALV Request by Members** -- BW 2:41, 10% threshold

### Corporate Governance Domain
- **AGM Convocation & Agenda Setting** -- 42-day notice, resolution drafting
- **Digital / Hybrid AGM Execution** -- Identity verification, live voting, Q&A
- **Board Pack Preparation & Distribution** -- Structured, indexed package 5-7 days before
- **Board Minutes & Action Item Tracking** -- Decisions, action items, completion tracking
- **AGM Minutes & Legal Documentation** -- Notarial minutes for contentious meetings
- **Proxy Voting & Power of Attorney** -- Digital proxy management

### Corporate Operations Domain
- **MT Agenda Preparation** -- Collect items, compile package, distribute
- **MT Decision Making During Meeting** -- Real-time structured decisions
- **MT Minutes and Decision Distribution** -- Minutes within hours, cascade to departments
- **MT Recurring Review Cycle** -- Monthly/quarterly/annual strategic reviews
- **Steering Committee Meeting** -- Project decisions and risk reviews
- **Urgent Escalation Between Meetings** -- Cannot wait for next meeting
- **Meeting Efficiency & Analytics** -- Cost tracking, DEI speaking time, KPIs

### Cross-Domain
- **Hybrid & Virtual Meeting Facilitation** -- Applicable across all 5 domains
- **AI-Powered Meeting Intelligence** -- Transcription, summarization, action extraction
- **Smart Agenda & Meeting Scheduling** -- Templates and calendar integration
- **Secure Document Management & Distribution** -- Version control, annotations
- **Meeting Analytics & Performance Insights** -- Effectiveness, costs, patterns
- **Platform Integration & Interoperability** -- APIs, Nextcloud ecosystem
- **Open Source & Digital Sovereignty** -- Self-hosted, full data sovereignty

## Acceptance Criteria

1. Meetings are stored as OpenRegister objects with `@type` of `schema:Event`
2. Physical, digital, and hybrid meeting modes are supported with correct Schema.org attendance modes
3. Convocation tracks delivery status per recipient and respects statutory notice periods
4. Statutory notice periods are configurable per body type (42 days NV, 15 days BV, custom for associations)
5. Quorum is automatically calculated including proxy votes
6. Quorum rules are configurable per body (>50%, 2/3, custom)
7. Voting is blocked when quorum is not met
8. Second meeting without quorum is supported per Gemeentewet Art. 29
9. Proxy (volmacht) management supports granting, revoking, and limits per person
10. Meeting list supports search, filter, and calendar view
11. Meetings sync to Nextcloud Calendar via CalDAV
12. Recurring meetings auto-generate individual editable instances with linked agendas
13. Meeting templates pre-populate agendas with required items per meeting type
14. Real-time notes are captured per agenda item and structured into minutes
15. Action items auto-create CalDAV VTODO tasks with owners and deadlines
16. Speaking order and time tracking is supported with DEI analytics
17. Meeting recordings are segmented by agenda item with searchable transcripts
18. Captions are generated for accessibility (WCAG AA)
19. Meeting documents track read/unread status per participant
20. Meeting cost and efficiency KPIs are trackable
21. No-meeting days can be configured with compliance tracking
22. OpenRaadsinformatie `Vergadering`/`Zitting` mapping is available
23. Citizen registration for committee speaking (inspreekrecht) is supported
24. Extraordinary ALV request validation is supported (10% threshold, 4-week deadline)
25. All meeting interfaces comply with Digitoegankelijk (EN 301 549 with WCAG 2.1)

## Notes

### Open Questions

- Should we build video conferencing directly or integrate with Nextcloud Talk? Talk integration is recommended (insight #27) to avoid reinventing the wheel.
- How to handle the transition when WDAV passes Eerste Kamer? Expected July 2026 deadline for full WBTR compliance.
- What is the minimum viable AI transcription quality for meeting minutes? Self-hosted Whisper vs. external API?
- How to handle document annotation sync when iBabs reports losing annotations on document replacement (source #372)?

### Legal Risks

- Digital identity verification for remote meeting participants has no standardized approach -- each organization may have different requirements.
- WDAV is pending in Eerste Kamer -- build for it now but have fallback for hybrid-only mode.
- Archiefwet requires archiving meeting discussions when they occur on platform -- Talk integration must consider this (insight #27).

### Technical Decisions

- Use OpenRegister _calendar metadata for virtual calendar sync (no separate calendar backend needed)
- Use OpenRegister _todos metadata for action item CalDAV sync
- Use OpenRegister _talk metadata for auto-creating Talk conversations per meeting
- Integrate Nextcloud Contacts for stakeholder-aware meeting workflows (insight #29: 438 stories reference participants)
- Self-hosted AI for transcription and summarization to avoid privacy lawsuits (insight #9)
