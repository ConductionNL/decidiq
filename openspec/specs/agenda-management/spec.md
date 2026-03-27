---
status: idea
---

# Agenda Management Specification

**Status**: idea
**Standards**: Schema.org (`ItemList`, `ListItem`), Akoma Ntoso (`debateSection`, `pointOfOrder`), OpenRaadsinformatie (`AgendaPunt`), Woo, Digitoegankelijk (EN 301 549)
**Feature tier**: MVP

## Purpose

Agenda management handles the creation, ordering, and conduct of meeting agendas. An agenda is a structured list of items to be discussed at a meeting, each with a type (informational, discussion, decision), allocated time, and attached documents. The system supports drag-and-drop reordering, legally required items for specific meeting types (e.g., ALV statutory items), and real-time agenda tracking during meetings. Research shows that 67% of professionals say a clear agenda is the most important meeting element (source #320), yet only 37% of meetings use agendas (source #319).

## Data Model

See [ARCHITECTURE.md](../../architecture/README.md) for the full AgendaItem entity definition including property tables, Schema.org mappings, and OpenRaadsinformatie alignment.

| Entity | Schema.org Type | Key Properties |
|--------|----------------|----------------|
| AgendaItem | `schema:ListItem` | title, itemType (informational/discussion/decision), position, allocatedTime, presenter, meeting |
| Agenda | `schema:ItemList` | meeting, items, totalDuration, status (draft/published/in-progress/completed) |
| DocumentPackage | `schema:CreativeWork` | meeting, tableOfContents, documents |

## Requirements

---

### Requirement: Agenda Item Creation [MVP]

The system MUST support creating agenda items with a title, type (informational, discussion, decision), description, allocated time, presenter, and attached documents. Agenda items MUST be stored as OpenRegister objects in the `decidesk` register using the `agendaItem` schema.

#### Scenario: Create a decision agenda item

- GIVEN a user preparing an agenda for the board meeting
- WHEN they add an agenda item with title "Approve Q1 Budget", type "decision", allocated time 20 minutes, and presenter "CFO"
- THEN the system MUST create an OpenRegister object with the `agendaItem` schema
- AND the item MUST appear at the end of the agenda list
- AND the item MUST have a sequential order number

#### Scenario: Create an informational agenda item with documents

- GIVEN a user preparing a meeting agenda
- WHEN they add an agenda item with title "Management Report" and type "informational" and attach a PDF document
- THEN the document MUST be linked to the agenda item via Nextcloud Files
- AND meeting participants MUST be able to access the document

#### Scenario: Submit a member proposal for the agenda

- GIVEN a member of a governing body with an upcoming meeting
- WHEN they submit a motion or proposal for the agenda with title and supporting arguments
- THEN the proposal MUST be submitted for chair review
- AND the chair MUST be notified of the pending proposal
- AND the chair MUST be able to accept or reject the agenda addition

#### Scenario: Submit agenda item with structured form

- GIVEN an MT member preparing items for the next meeting
- WHEN they submit an agenda item through a structured form with title, description, decision required (yes/no), time needed, and supporting documents
- THEN the secretary MUST receive the submission for compilation
- AND the system MUST validate that all required fields are completed

#### Scenario: Shareholder agenda item request (NV/BV)

- GIVEN a shareholder with 3% (NV) or 1% (BV) of share capital
- WHEN they request an item to be added to the AGM agenda
- THEN the board MUST include the item unless it conflicts with vital company interests
- AND the system MUST track the request with the shareholder's identity and share percentage

---

### Requirement: Agenda Ordering and Structure [MVP]

The system MUST support drag-and-drop reordering of agenda items. The system MUST enforce legally required items for specific meeting types. Sub-items MUST be supported for grouping related topics.

#### Scenario: Reorder agenda items via drag-and-drop

- GIVEN a meeting agenda with 5 items
- WHEN the user drags item 4 to position 2
- THEN the order numbers MUST update automatically for all items
- AND the new order MUST persist immediately

#### Scenario: Enforce legally required ALV agenda items

- GIVEN a meeting of type "general_assembly" for an association
- WHEN the user creates the agenda
- THEN the system MUST prompt to include required items: opening, approval of previous minutes, annual report, financial statements, kascommissie report, board elections, any other business, closing
- AND missing required items MUST be highlighted with a warning

#### Scenario: Enforce required items for cooperative ALV

- GIVEN a meeting of type "general_assembly" for a cooperative (cooperatie)
- WHEN the user creates the agenda
- THEN the system MUST additionally prompt for: profit distribution/loss allocation, member liability considerations (UA/BA/WA), and cooperative-specific business items

#### Scenario: Group agenda items with sub-items

- GIVEN an agenda item "Committee Reports"
- WHEN the user adds sub-items "Finance Committee" and "Audit Committee"
- THEN the sub-items MUST appear nested under the parent item
- AND each sub-item MUST have its own allocated time, type, and presenter

#### Scenario: Classify items as hamerstuk or bespreekstuk

- GIVEN a council meeting agenda with multiple items
- WHEN the voorzitter classifies agenda items as hamerstuk (consent) or bespreekstuk (discussion)
- THEN hamerstukken MUST be grouped together for batch processing
- AND bespreekstukken MUST have full speaking time allocation
- AND the classification MUST be visible in the published agenda

#### Scenario: Set document submission deadlines per agenda item

- GIVEN an agenda item requiring supporting documents
- WHEN the griffier sets a document submission deadline
- THEN document owners MUST be notified of the deadline
- AND the system MUST warn when deadlines are missed
- AND the agenda package MUST NOT be published until all required documents are received

---

### Requirement: Agenda Templates [MVP]

The system MUST support agenda templates for recurring meeting types that pre-populate required items.

#### Scenario: Create agenda from ALV template

- GIVEN an ALV meeting template with required statutory items
- WHEN the secretary creates a new ALV from the template
- THEN the agenda MUST be pre-populated with: opening, approval of previous minutes, annual report, financial statements, kascommissie report, board elections, any other business, closing
- AND the secretary MUST be able to add, remove, or reorder additional items
- AND removed required items MUST trigger a compliance warning

#### Scenario: Create agenda from MT weekly template

- GIVEN an MT weekly meeting template with standing items (action item review, decisions pending, department updates)
- WHEN a new meeting instance is created from the recurring schedule
- THEN standing items MUST be pre-populated
- AND open action items from previous meetings MUST be automatically carried over as a sub-item

#### Scenario: Create agenda from council meeting template

- GIVEN a council meeting template following the BOB model
- WHEN the griffier creates a new council meeting agenda
- THEN items MUST be tagged with their BOB phase (Beeldvorming/Oordeelsvorming/Besluitvorming)
- AND the template MUST include: opening, hamerstukken, bespreekstukken, moties/amendementen, closing

---

### Requirement: Agenda Time Management [MVP]

The system MUST calculate total meeting duration from individual item allocations. The system MUST warn when total allocated time exceeds a configured meeting length. During meetings, the system MUST track time spent per agenda item.

#### Scenario: Calculate total agenda duration

- GIVEN agenda items with allocated times of 10, 20, 15, 30, and 5 minutes
- WHEN the user views the agenda summary
- THEN the total duration MUST be displayed as "1 hour 20 minutes"
- AND if the meeting is scheduled for 1 hour, a warning MUST indicate the agenda exceeds the scheduled duration by 20 minutes

#### Scenario: Track time during meeting conduct

- GIVEN a meeting in progress on agenda item 3 of 5
- WHEN the allocated time for item 3 (15 minutes) has elapsed
- THEN the system MUST display a time-over indicator
- AND the chair MUST be able to extend the time or move to the next item

#### Scenario: Use per-item countdown timers

- GIVEN a meeting with agenda items having specific time allocations
- WHEN the chair starts an agenda item
- THEN a visible countdown timer MUST display the remaining time
- AND audio/visual alerts MUST fire when time is up
- AND the chair MUST be able to extend or skip to the next item

#### Scenario: Track agenda adherence metrics

- GIVEN a completed meeting with time-tracked agenda items
- WHEN the meeting analytics are generated
- THEN the system MUST show: actual vs allocated time per item, total meeting overrun/underrun, and agenda adherence percentage
- AND this MUST feed into the meeting efficiency dashboard

---

### Requirement: Agenda Document Package (Vergaderstukken) [MVP]

The system MUST support assembling all agenda item documents into a single meeting package (vergaderstukken) for distribution to participants.

#### Scenario: Assemble meeting package from agenda documents

- GIVEN a meeting with 5 agenda items, each with one or more attached documents
- WHEN the secretary triggers "Assemble meeting package"
- THEN the system MUST create a structured document package with a table of contents
- AND documents MUST be organized by agenda item number and title
- AND the package MUST be available for download and distribution via convocation

#### Scenario: Publish agenda package in one action

- GIVEN a complete agenda with all documents attached
- WHEN the griffier triggers "Publish agenda package"
- THEN the complete agenda with all accompanying documents MUST be published in one action
- AND council members and the public MUST be able to access everything they need
- AND the publication timestamp MUST be recorded for Woo compliance

#### Scenario: Board pack assembly from multiple sources

- GIVEN a board meeting with documents from finance, legal, HR, and operations
- WHEN the secretary assembles the board pack
- THEN documents MUST be combined into a structured, indexed package
- AND the package MUST be accessible on tablet with offline capability
- AND version tracking MUST ensure only the latest document versions are included

---

### Requirement: Agenda Item Proposals and Member Submissions [MVP]

The system MUST support members submitting agenda item proposals through a structured workflow.

#### Scenario: Member submits motion for ALV agenda

- GIVEN a member of an association with an upcoming ALV
- WHEN they submit a motion with title, supporting arguments, and the desired outcome
- THEN the board/chair MUST be notified of the pending proposal
- AND the proposal MUST be reviewed before the statutory deadline for agenda additions
- AND if accepted, the motion MUST appear on the published agenda with all supporting materials

#### Scenario: Citizen submits initiative for council agenda (burgerinitiatief)

- GIVEN a citizen with a proposal that has collected the required signatures
- WHEN they submit a formal citizen initiative to the municipal council
- THEN the system MUST validate the signature count against the requirement
- AND the initiative MUST be placed on the council agenda per the burgerinitiatief procedure
- AND the citizen MUST be notified of the scheduled date

#### Scenario: Neighbourhood council collects agenda items from residents

- GIVEN a neighbourhood council with an upcoming meeting
- WHEN residents submit agenda items digitally
- THEN items MUST be collected and presented to the council for prioritization
- AND residents who submitted items MUST be notified of whether their item was included

---

### Requirement: Agenda Item Linking and Cross-References [MVP]

The system MUST support linking agenda items to decisions, motions, amendments, and previous meeting items.

#### Scenario: Link motion to agenda item

- GIVEN a raadslid who has drafted a motion
- WHEN they link the motion to a specific agenda item
- THEN the motion MUST be automatically included in the debate on that topic
- AND the motion MUST appear in the agenda view under its linked item

#### Scenario: Link agenda item to previous meeting decision

- GIVEN an agenda item that is a follow-up to a previous decision
- WHEN the secretary links it to the original decision
- THEN the linked decision MUST be visible on the agenda item
- AND the implementation status of the previous decision MUST be shown

#### Scenario: Committee advice attached to plenary agenda item

- GIVEN a committee that has discussed an item and formed an advice
- WHEN the commissievoorzitter formally records the committee advice
- THEN the advice MUST be linked to the corresponding plenary agenda item
- AND the presidium MUST be able to see the advice when planning the plenary agenda

---

### Requirement: Speaking Order and Inspreekrecht [V1]

The system SHOULD support managing the speaking order for agenda items and citizen speaking rights (inspreekrecht) for committee meetings.

#### Scenario: Manage committee speaking order

- GIVEN a committee meeting with members and registered insprekers
- WHEN the chair manages the speaking order
- THEN insprekers MUST be scheduled first per standard practice
- AND each speaker MUST have a configurable time limit (default 5 minutes per Reglement van Orde)
- AND time tracking MUST be visible to the chair and speakers

#### Scenario: Schedule hearing speakers

- GIVEN a hearing with multiple registered speakers
- WHEN the hearing secretary schedules speakers
- THEN each speaker MUST receive a time slot and topic confirmation
- AND the schedule MUST account for breaks and transitions
- AND late registrations MUST be handled per the hearing rules

---

### Requirement: Real-Time Agenda Tracking During Meetings [MVP]

The system MUST support real-time tracking of which agenda item is currently being discussed during a meeting.

#### Scenario: Track current agenda item during meeting

- GIVEN a meeting in progress
- WHEN the chair moves to the next agenda item
- THEN the system MUST update the current item indicator in real-time
- AND all participants (including remote) MUST see which item is active
- AND citizens following via webcast MUST be able to see the current item

#### Scenario: Auto-create Talk conversation per agenda item

- GIVEN an agenda item flagged for deliberation
- WHEN the agenda is finalized
- THEN the system SHOULD auto-create a Nextcloud Talk conversation for informal discussion (insight #27)
- AND the conversation MUST be linked to the agenda item and accessible to body members

---

### Requirement: Agenda Publication and Transparency [MVP]

The system MUST support publishing agendas for public access per Woo requirements.

**Legal reference**: Woo Art. 3.3 -- active publication of agendas of councils, B&W, and other public bodies

#### Scenario: Publish council meeting agenda for public access

- GIVEN a finalized council meeting agenda
- WHEN the griffier publishes the agenda
- THEN the agenda MUST be publicly accessible with all non-confidential documents
- AND confidential items MUST be marked but not publicly visible
- AND the publication MUST comply with Digitoegankelijk (WCAG 2.1)

#### Scenario: Notify citizens of agenda topics of interest

- GIVEN citizens who have set up keyword-based alerts
- WHEN a new agenda is published containing matching keywords
- THEN those citizens MUST receive notifications about relevant agenda items
- AND the notification MUST link directly to the agenda item

---

### Requirement: Consent Agenda (Hamerstukken) Processing [MVP]

The system MUST support grouping non-controversial items as hamerstukken for batch adoption.

#### Scenario: Process consent agenda items in one batch

- GIVEN a council meeting agenda with 4 items classified as hamerstukken
- WHEN the voorzitter calls the consent agenda
- THEN all hamerstukken MUST be votable as a single batch
- AND if any member requests to remove an item from the consent agenda, it MUST be moved to the discussion items
- AND adopted hamerstukken MUST be recorded as individual decisions

---

### Requirement: Automatic Agenda Generation for Recurring Meetings [V1]

The system SHOULD support automatic agenda generation for recurring meetings with carried-over items.

#### Scenario: Generate agenda for next recurring meeting

- GIVEN a weekly MT meeting with standing items
- WHEN the next meeting instance is auto-created
- THEN the agenda MUST include: standing items from template, open action items from previous meeting, and items submitted since last meeting
- AND the secretary MUST be able to review and adjust before distribution

#### Scenario: Carry over unfinished agenda items

- GIVEN a meeting that ended with 2 agenda items not discussed
- WHEN the next meeting's agenda is prepared
- THEN the system MUST offer to carry over the unfinished items
- AND carried-over items MUST be marked as such with the original meeting reference

---

### Requirement: Agenda Annotations and Preparation [V1]

The system SHOULD support annotating agenda documents for meeting preparation.

#### Scenario: Annotate meeting documents on tablet

- GIVEN a raadslid reviewing meeting documents
- WHEN they highlight text and add notes to agenda documents
- THEN annotations MUST be saved privately for the user
- AND annotations MUST be accessible during the meeting
- AND annotations MUST NOT be lost when documents are replaced (addressing iBabs limitation -- source #372)

---

### Requirement: Agenda Data Export and Interoperability [MVP]

The system MUST export agendas in standardized formats.

#### Scenario: Export agenda as OpenRaadsinformatie AgendaPunt

- GIVEN a finalized council meeting agenda
- WHEN the system exports the agenda
- THEN each agenda item MUST conform to the OpenRaadsinformatie `AgendaPunt` schema
- AND linked documents, decisions, and motions MUST be included

#### Scenario: Export agenda as iCalendar with item times

- GIVEN a meeting agenda with timed items
- WHEN exported as iCalendar
- THEN each agenda item MUST appear as a sub-event with start/end times
- AND the export MUST be importable into calendar applications

---

## User Stories (from intelligence database)

### Legislative Domain

1. As a griffier, I want to create a meeting agenda by selecting and ordering proposals from the backlog so that council members and the public can see what will be discussed. (DB #136)
2. As a griffier, I want to set deadlines for document submission per agenda item so that all required documents are available before publication. (DB #137)
3. As a griffier, I want to publish the complete agenda with all accompanying documents in one action. (DB #138)
4. As a raadslid, I want to highlight text and add notes to meeting documents on my tablet so that I can prepare for debate. (DB #140)
5. As a raadslid, I want to link my motion to a specific agenda item so that it is automatically included in the debate. (DB #147)
6. As a voorzitter, I want to classify agenda items as hamerstuk or bespreekstuk so that meeting time is used efficiently. (DB #158)
7. As a commissievoorzitter, I want to manage the speaking order for committee members and insprekers. (DB #159)
8. As a burger, I want to register online to speak at a committee meeting about an agenda item that affects me. (DB #160)
9. As a commissievoorzitter, I want to formally record the committee advice on whether an item is ready for plenary decision. (DB #162)
10. As a voorzitter, I want to process all hamerstukken in one batch without debate. (DB #164)
11. As a commissiegriffier, I want minutes to be automatically structured by agenda item. (DB #174)
12. As a fractievoorzitter, I want to record the agreed faction position on each agenda item. (DB #153)
13. As a burger, I want to set up keyword-based alerts for council agenda items so I am notified when topics that affect me are being discussed. (DB #208)

### Association Domain

14. As a chair, I want to compose the ALV agenda ensuring all legally required items are included so that the meeting is legally valid. (DB #49)
15. As a treasurer, I want to upload the financial statements and budget proposal to the ALV agenda so that members can review them before the meeting. (DB #50)
16. As a member, I want to receive the ALV invitation with the complete agenda and supporting documents so that I can prepare. (DB #47)
17. As a member, I want to submit a motion or proposal for the ALV agenda with supporting arguments. (DB #54)
18. As a secretary, I want to prepare a digital meeting package with agenda, previous minutes, action items, and new documents. (DB #65)
19. As a board member, I want to declare a conflict of interest for a specific agenda item so I am properly excluded per WBTR. (DB #69)
20. As a ledenraad member, I want to review the agenda and consult my constituency before the council meeting. (DB #82)
21. As a secretary, I want recurring meeting series where each occurrence automatically gets a new agenda. (DB #1827)

### Corporate Governance Domain

22. As a board secretary, I want to create and manage the AGM agenda with drag-and-drop resolution ordering within statutory deadlines. (DB #1)
23. As a shareholder, I want to access all AGM documents (agenda, annual report, resolution proposals) through a secure online portal. (DB #3)
24. As a proxy advisor, I want to receive standardized AGM agenda and resolution data digitally for analysis. (DB #6)
25. As a notary, I want secure access to the meeting agenda, attendee list, and voting results. (DB #12)

### Corporate Operations Domain

26. As an MT member, I want to submit agenda items with supporting documents through a structured form. (DB #87)
27. As a management assistant, I want to compile submitted agenda items into a structured agenda and distribute the complete package. (DB #88)
28. As a CEO, I want to review major budget requests on the MT agenda with financial controller assessment. (DB #99)
29. As a meeting facilitator, I want visible countdown timers per agenda item with configurable time allocations. (DB #334)
30. As a director, I want a periodic meeting audit report showing which meetings have agendas and achieve their goals. (DB #333)
31. As a council secretary, I want to tag each meeting or agenda item with its BOB phase and track progress across meetings. (DB #341)

### Citizen Participation Domain

32. As a coordinator, I want to plan and manage the assembly meeting schedule including information sessions and deliberation rounds. (DB #223)
33. As a neighbourhood council member, I want residents to submit agenda items digitally so our meetings address what the neighbourhood cares about. (DB #247)
34. As a hearing secretary, I want to schedule speakers with time slots and topics so that the hearing runs efficiently. (DB #238)

### Cross-Domain (Nextcloud Integration)

35. As a member, I want each major agenda item to have a linked Talk conversation for informal deliberation. (DB #1851, #1852, #1853)
36. As any user, I want to search for agenda items and related documents from the Nextcloud unified search bar. (DB #1873)

## Evidence Sources

### Legal Standards (Mandatory)

| Standard | Scope | Key Requirements |
|----------|-------|-----------------|
| **Woo** | Public transparency | Art. 3.3: active publication of agendas of councils, B&W, and public bodies |
| **Gemeentewet** | Municipal councils | Reglement van Orde defines agenda structure, speaking rules (max 5 min, max 2 terms) |
| **BW Boek 2** | Legal entities | Art. 2:38: ALV agenda requirements; Art. 2:225: AGM agenda notice |
| **Digitoegankelijk** | Accessibility | Published agendas must comply with EN 301 549 / WCAG 2.1 |

### Forum Standaardisatie Standards (Recommended)

| Standard | Relevance |
|----------|-----------|
| **PDF/UA** | Accessible agenda document publication |
| **ODF** | Editable agenda document format |
| **Akoma Ntoso** | XML representation of parliamentary agenda items |

### External Research & Market Evidence

- **67% of professionals say clear agenda is most important meeting element** (source #320)
- **Only 37% of meetings use agendas** -- massive compliance/quality gap (source #319)
- **Agenda adherence is a key KPI** tracked by leading meeting analytics platforms (source #323)
- **158 stories reference scheduling, deadlines, or agenda items** -- Calendar integration is high-value (insight #26)
- **iBabs**: users praise ease of use, but replacing documents loses annotations (source #372) -- we should do better
- **OnBoard**: easy agenda building, centralized docs, e-signatures (source #462)
- **Sherpany**: 45% productivity boost through structured meeting lifecycle including agenda management (source #108)
- **Lucid Meetings**: in-meeting timers, sub-topic support, decision tracking for process-driven meetings (source #327)
- **Flowtrace**: agenda compliance tracking as core metric across 2.2M+ meetings (source #322)

### Competitor Agenda Features

| Competitor | Agenda Features | Gap |
|-----------|----------------|-----|
| **Notubiz** | Agenda publication for council meetings | Poor search, documents barely findable (source #361) |
| **iBabs** | Board agenda building | Annotations lost on document replacement |
| **OnBoard** | Easy agenda building, centralized docs | Font customization limited, all-or-nothing downloads |
| **Fellow** | Collaborative agendas, AI note-taking | No formal governance compliance |
| **Sherpany** | End-to-end meeting lifecycle | Corporate boards only |
| **Lucid Meetings** | In-meeting timers, sub-topics | No legislative/association support |
| **Decisions (Teams)** | AI-driven agenda management | Microsoft ecosystem only |
| **Confluence** | Meeting templates, decision macros | No real-time agenda tracking |

### Tender Requirements

- **W-BESL** (70 pts): "Beschrijf ondersteuning bestuurlijk besluitvormingsproces inclusief agendabeheer"
- **W9** (68 pts): Agenda management as part of full BBV process
- **W11** (48 pts): "iBabs voor agendabeheer" -- municipalities expect agenda management integration
- **W13** (56 pts): "inclusief agendabeheer, vergaderbehandeling, publicatie"
- **W2-22** (5 pts): "Automatisch een welstandsagenda genereren uit de applicatie"

## Customer Journeys

### Legislative Domain
- **Agenda Preparation & Setting** -- Griffier creates meeting agenda from proposal backlog
- **Document Package Review & Preparation** -- Council members review documents, prepare notes
- **Presidium Agenda Setting** -- Faction leaders classify items, set meeting planning
- **Council Proposal Preparation** -- Civil servants draft proposals for agenda inclusion
- **Faction Position Coordination** -- Faction leaders coordinate voting positions per agenda item
- **Live Meeting Following & Webcasting** -- Citizens track which agenda item is being discussed
- **Meeting Recording Publication** -- Video indexed by agenda item
- **Document & Decision Search** -- Searching across agendas, minutes, decisions
- **Active Disclosure (Actieve Openbaarmaking)** -- Woo Art. 3.3 mandatory publication
- **Submit a citizen initiative (Burgerinitiatief)** -- Formal placement on council agenda

### Association Domain
- **ALV Convocation & Scheduling** -- Agenda distribution with convocation
- **ALV Agenda & Document Preparation** -- Board prepares ALV agenda with all documents
- **Member Proposal / Motion Submission** -- Members submit proposals for agenda
- **Financial Statements & Budget Preparation** -- Treasurer prepares items for ALV agenda
- **Annual Report Preparation** -- Board prepares annual report as ALV agenda item
- **Board Meeting Preparation** -- Secretary drafts agenda with chair
- **Meeting Minutes Preparation & Approval** -- Minutes structured by agenda item
- **Smart Agenda & Meeting Scheduling** -- Templates and calendar integration

### Corporate Governance Domain
- **AGM Convocation & Agenda Setting** -- End-to-end AGM agenda and convocation process
- **Shareholder Agenda Item Request** -- 3% (NV) / 1% (BV) threshold for agenda items
- **Board Pack Preparation & Distribution** -- Structured, indexed document package
- **Smart Agenda & Meeting Scheduling** -- Templates and calendar integration

### Corporate Operations Domain
- **MT Agenda Preparation** -- Collect items, compile package, distribute
- **MT Decision Making During Meeting** -- Discussing agenda items, forming positions
- **Meeting Efficiency & Analytics** -- Agenda adherence as KPI
- **Smart Agenda & Meeting Scheduling** -- Templates and calendar integration

### Citizen Participation Domain
- **Run neighbourhood council meeting cycle** -- Collect agenda items from residents
- **Submit a citizen initiative** -- Agenda placement via burgerinitiatief

## Acceptance Criteria

1. Agenda items are stored as OpenRegister objects with sequential ordering
2. Drag-and-drop reordering persists immediately with automatic order number recalculation
3. Legally required items are enforced per meeting type with warnings for missing items
4. ALV required items: opening, approval of previous minutes, annual report, financial statements, kascommissie report, board elections, AOB, closing
5. Agenda templates pre-populate required items and can be customized per body
6. Time allocation is tracked per item with real-time countdown timers during meetings
7. Over-time warnings fire with audio/visual alerts
8. Total agenda duration is calculated and compared against meeting schedule
9. Meeting document packages can be assembled from agenda attachments with table of contents
10. Document packages are publishable in one action with Woo-compliant timestamps
11. Member proposal workflow supports submission, review, acceptance/rejection
12. Shareholder agenda requests track identity and share percentage
13. Sub-items are supported for hierarchical agenda structure
14. Items can be classified as hamerstuk/bespreekstuk with batch processing
15. Hamerstukken can be adopted as a batch with individual decision records
16. Motions and amendments can be linked to specific agenda items
17. Speaking order is managed per agenda item with configurable time limits
18. Citizen speaking registration (inspreekrecht) is supported for committee meetings
19. Real-time current agenda item indicator is visible to all participants
20. Agenda items can be tagged with BOB phase (Beeldvorming/Oordeelsvorming/Besluitvorming)
21. Document submission deadlines can be set per agenda item with notifications
22. Unfinished agenda items can be carried over to the next meeting
23. Document annotations are preserved across document replacements
24. Published agendas comply with Digitoegankelijk (WCAG 2.1)
25. OpenRaadsinformatie `AgendaPunt` mapping is available for each agenda item
26. Agenda items and documents are searchable from Nextcloud unified search
27. Talk conversations can be auto-created per agenda item for informal deliberation
28. Agenda adherence metrics feed into the meeting efficiency dashboard

## Notes

### Open Questions

- Should the system enforce a maximum number of agenda items per meeting to prevent overloading? Research suggests meetings with 5-7 items are most effective.
- How to handle confidential agenda items in public agendas? Woo requires transparency but allows exceptions for specific categories.
- Should we support "consent-based agenda setting" (sociocratic practice) in addition to chair-driven agenda setting?

### Legal Risks

- Woo requires active publication of agendas -- the system must ensure publication timestamps are recorded and documents are accessible.
- Missing required ALV agenda items could invalidate the meeting and all decisions taken -- the warning system must be prominent and non-dismissable.
- Shareholder agenda requests have specific legal thresholds (3% NV, 1% BV) -- incorrect validation could expose the organization to legal challenges.

### Technical Decisions

- Use OpenRegister _calendar metadata to sync agenda items as calendar sub-events
- Use OpenRegister _talk metadata for auto-creating Talk conversations per agenda item
- Drag-and-drop implemented with Vue.js sortable library, persisted via immediate API call
- Real-time agenda tracking uses WebSocket or Nextcloud Push for live updates
- Document package assembly uses Nextcloud Files API for document aggregation
- Annotations stored per-user in OpenRegister with document version tracking to survive replacements
