---
status: idea
---

# Meeting Efficiency Specification

## Purpose

Meeting efficiency features help governance bodies run productive meetings. This includes real-time timers for agenda items and speaking time, a meeting cost calculator (based on participant hourly rates), analytics on meeting duration and decision throughput, and tools to keep discussions focused. These features transform Decidesk from a compliance tool into a productivity platform that actively improves organizational decision-making.

**Standards**: Schema.org (`Duration`, `MonetaryAmount`), Dutch BOB model, Robert's Rules of Order, EOS Level 10 Meeting format
**Feature tier**: V1

## Evidence Base

### Market Research -- The Meeting Crisis

| Statistic | Source |
|-----------|--------|
| $541 billion wasted on pointless meetings globally per year | Intelligence DB insight #1 (Doodle, 19M meetings analyzed) |
| 67% of meeting time is wasted | Intelligence DB insight #1 (HBR) |
| Executives spend 23 hours/week in meetings | Intelligence DB insight #1 (HBR) |
| $37 billion/year wasted on unnecessary meetings in the US alone | External source #325 (MeetingKing) |
| Mid-level employee costs $25K/year in meetings (3hrs/day) | External source #325 |
| Large companies lose $100 million/year to unnecessary meetings | External source #325 (CBS) |
| 78% of workers say they attend too many meetings | External source #319 (Fellow State of Meetings 2024) |
| 60% of meetings are ad hoc (unplanned) | External source #321 (Microsoft Work Trend Index) |
| Workers are interrupted every 2 minutes; communication consumes 60% of workday | External source #321 |
| 44% of action items are never completed | External source #342 (Count.co) |
| 71% of meetings fail objectives due to poor follow-through | External source #342 |
| AI meeting tool usage grew 17x in 2024 | Intelligence DB insight #5 (Fellow) |
| Women's speaking time increased 65% when tracked and displayed | Intelligence DB insight #24 (Equal Time case study) |
| Only 37% of meetings use an agenda | External source #319 |
| Managers spend 13 hours/week in meetings | External source #320 |
| After-hours meetings up 16% year-over-year | External source #321 |
| 2.2 million+ meetings benchmarked by Flowtrace | External source #322 |

### Methodology Evidence

| Method | Description | Source |
|--------|-------------|--------|
| Dutch BOB model | 3-phase decision model: Beeldvorming (info gathering), Oordeelsvorming (opinion formation), Besluitvorming (decision). Used by Dutch municipalities. Hollands Kroon: separate BOB-phase meetings | External source #337 |
| Robert's Rules of Order | Parliamentary procedure for motions, amendments, debate, and voting. Speaking time limits, point of order, quorum rules | User stories #159, #163, #340 |
| Sociocracy / Consent-based | Structured rounds: proposal, reactions, objections, integration. "No objection" = consent. Timer per round | External source #256 |
| EOS Level 10 Meeting | Structured 90-min format with IDS (Identify, Discuss, Solve). Target >90% to-do completion rate | User story #337 |
| Dutch Reglement van Orde | Max 5 min speaking time (proportional if >6 speakers). Max 2 speaking terms per topic per faction | External source #339 |

### Competitor Analysis

| Competitor | Meeting Efficiency Features | Gap |
|------------|---------------------------|-----|
| Flowtrace | 2.2M meetings benchmarked, real-time cost, meeting audit dashboards, behavioral nudges | SaaS-only, no governance integration, no self-hosted |
| Fellow.app | AI meeting notes, action item tracking, meeting templates, 17x AI growth | No formal voting, no speaking time, no cost calculator |
| Decisions.com | Smart timer, AI agenda, engagement score, behavioral science | Microsoft Teams only, no self-hosted |
| iBabs | Quorum tracking, RSVP, document distribution | No timers, no cost calculator, no analytics dashboard |
| Lucid Meetings | Per-item timeboxing, sub-topics, meeting templates | Limited analytics, no governance features |
| Equal Time | Speaking time balance, gender breakdown, interruption detection | Single feature, no meeting management |

### Self-Hosted Advantage

No self-hosted AI meeting solution exists that combines transcription, action item extraction, and speaking time tracking with GDPR compliance (intelligence DB insight #9). Otter.ai and Fireflies.ai face class-action lawsuits over recording without consent and biometric data collection. Decidesk's Nextcloud-native approach keeps all data in the tenant.

## Requirements

---

### Requirement: Agenda Item Timer

The system MUST provide a visible countdown timer for each agenda item during meeting conduct. The timer MUST start when the chair opens an agenda item and alert when the allocated time is exceeded. The chair MUST be able to extend, pause, or skip the timer.

**Feature tier**: V1
**Evidence**: Only 37% of meetings use agendas (external source #319). Per-item timeboxing is the #1 most-requested meeting productivity feature (external source #334, user story #334).

#### Scenario: Timer alerts when time is exceeded

- GIVEN an agenda item with 15 minutes allocated and the timer running
- WHEN 15 minutes have elapsed
- THEN the system MUST display a visual alert (flashing timer, color change to red)
- AND the chair MUST be presented with options: "Extend 5 min", "Extend 10 min", "Close item"
- AND the actual time spent MUST be recorded for analytics
- AND a 1-minute warning MUST be shown before time expires (user story #334)

#### Scenario: Pause timer during procedural interruption

- GIVEN an active timer on an agenda item
- WHEN the chair pauses the timer for a procedural matter (e.g., point of order)
- THEN the countdown MUST freeze
- AND a "paused" indicator MUST be visible to all participants
- AND the pause duration MUST be recorded separately

#### Scenario: Skip timer for informational items

- GIVEN an informational agenda item with no time allocation
- WHEN the chair opens the item
- THEN no timer MUST be displayed
- AND the elapsed time MUST still be tracked in the background for analytics

#### Scenario: Sub-topic timeboxing

- GIVEN an agenda item with sub-topics (e.g., 2.1, 2.2, 2.3)
- WHEN the chair opens sub-topic 2.1
- THEN a separate sub-timer MUST be available within the parent item's allocation
- AND the parent timer MUST continue running (per Lucid Meetings pattern, user story #334)

---

### Requirement: Speaking Time Management

The system MUST track speaking time per participant during discussions. The chair MUST be able to set speaking time limits. The system MUST maintain a speaker queue for managing turn-taking.

**Feature tier**: V1
**Evidence**: Women's speaking time increased 65% when tracked (insight #24). Dutch Reglement van Orde mandates max 5 min per speaker, proportional allocation per faction (external source #339). Council debate speaking time is legally regulated (user story #340).

#### Scenario: Enforce speaking time limit

- GIVEN a speaking time limit of 3 minutes per speaker
- WHEN a speaker has been speaking for 3 minutes
- THEN the system MUST display a visual and optional audio alert
- AND the chair MUST be able to grant an extension or move to the next speaker
- AND a 1-minute warning MUST precede the time-up signal (user story #340)

#### Scenario: Manage speaker queue

- GIVEN a discussion in progress on an agenda item
- WHEN 4 participants request to speak
- THEN the system MUST display a speaker queue in order of request
- AND the chair MUST be able to reorder the queue
- AND the current speaker MUST be highlighted

#### Scenario: Track speaking balance with equity indicators

- GIVEN a meeting with 8 participants
- WHEN the meeting is in progress
- THEN the system MUST display speaking time per participant as duration and percentage
- AND a balance indicator MUST show over/under-participation (green = balanced, red = imbalanced)
- AND gender breakdown MUST be available (per Equal Time pattern, user story #336)
- AND interruption detection MUST be flagged (user story #336)
- AND historical trends per participant across meetings MUST be tracked

#### Scenario: Configure proportional faction-based speaking time

- GIVEN a council debate with 5 factions of different sizes
- WHEN the clerk configures speaking time allocation
- THEN time MUST be allocated proportionally to faction size (user story #340)
- AND a maximum of 2 speaking terms per faction per topic MUST be enforceable
- AND interruptions MUST be tracked separately (Tweede Kamer model)
- AND a post-debate report MUST show allocation vs. actual time per faction

#### Scenario: BOB phase speaking rules

- GIVEN a council agenda item in the Beeldvorming (image-forming) phase
- WHEN the chair opens the item for discussion
- THEN speaking rules MUST differ from Besluitvorming (decision) phase
- AND the BOB phase MUST be visible to all participants (user story #161, #341)
- AND citizens MUST be enabled to participate in the Beeldvorming phase (user story #341)
- AND time spent per BOB phase MUST be tracked across meetings

---

### Requirement: Meeting Cost Calculator

The system MUST calculate and display the running cost of a meeting based on participant count and configurable hourly rates. The cost MUST be displayed in real-time during the meeting and in meeting analytics afterward.

**Feature tier**: V1
**Evidence**: $541B wasted globally (insight #1). $25K/employee/year (external source #325). $100M/year for large companies (CBS via external source #325). Meeting cost visibility is a behavioral nudge that reduces meeting duration by 10-15% (external source #322, Flowtrace).

#### Scenario: Display running meeting cost

- GIVEN a meeting with 12 participants and an average hourly rate of EUR 75
- WHEN the meeting has been running for 45 minutes
- THEN the system MUST display the running cost as "EUR 675" (12 x 75 x 0.75)
- AND the cost MUST update in real-time (per second) as the meeting progresses (user story #335)
- AND the cost MUST include fully loaded cost (salary + benefits) when configured

#### Scenario: Show cost per agenda item in analytics

- GIVEN a completed meeting with 5 agenda items and tracked time per item
- WHEN the user views meeting analytics
- THEN the cost MUST be broken down per agenda item based on actual time spent
- AND the most expensive agenda items MUST be highlighted

#### Scenario: Meeting cost threshold alerts

- GIVEN a meeting with a configured cost budget of EUR 1,500
- WHEN the running cost exceeds the budget threshold
- THEN a visual alert MUST be displayed to the chair
- AND the alert MUST show percentage over budget (user story #335)

#### Scenario: Organizational meeting cost dashboard

- GIVEN a CEO viewing the meeting analytics for their organization
- WHEN they open the meeting cost dashboard
- THEN total meeting cost per week/month/quarter MUST be displayed
- AND cost breakdown by team/department MUST be visible
- AND trend lines MUST show cost trajectory over time
- AND benchmark data MUST be available for comparison (user story #330)

---

### Requirement: Action Item Tracking

The system MUST support capturing, assigning, and tracking action items from meetings. Action items MUST have an owner, deadline, and status. The system MUST integrate with Nextcloud Tasks via CalDAV VTODO.

**Feature tier**: V1
**Evidence**: 44% of action items never completed (external source #342). 71% of meetings fail objectives due to poor follow-through. EOS Level 10 targets >90% completion rate. Action item tracking appears in 213 user stories across all domains (insight #26).

#### Scenario: Capture action item during meeting

- GIVEN a meeting in progress
- WHEN the secretary adds an action item with owner "Jan" and deadline "2026-04-15"
- THEN the action item MUST be linked to the current agenda item and decision
- AND Jan MUST receive a notification (user story #337)
- AND a CalDAV VTODO task MUST be created in Nextcloud Tasks via _todos metadata (user story #1834)

#### Scenario: AI-suggested action items from transcription

- GIVEN meeting transcription is enabled
- WHEN the meeting ends
- THEN the system SHOULD suggest action items extracted from the transcription (user story #1170)
- AND each suggestion MUST include a proposed owner and deadline
- AND the secretary MUST be able to confirm, modify, or dismiss each suggestion

#### Scenario: Track action item completion rate

- GIVEN multiple meetings with action items over the past quarter
- WHEN the user views action item analytics
- THEN the completion rate MUST be calculated: (completed / total) x 100 (external source #342)
- AND the rate MUST be shown per person, per team, and per meeting
- AND items not completed MUST automatically roll over to the next meeting agenda (user story #19)

#### Scenario: Action item status updates between meetings

- GIVEN an action item assigned to an MT member
- WHEN the MT member updates status from "in progress" to "completed"
- THEN the secretary and chair MUST be automatically notified (user story #92)
- AND a history log of status changes MUST be maintained
- AND the team MUST have real-time visibility without waiting for the next meeting

---

### Requirement: Meeting Preparation Compliance

The system SHOULD track whether attendees have reviewed pre-meeting documents and provide preparation compliance analytics.

**Feature tier**: V1
**Evidence**: 67% of meeting time is wasted, partially due to unprepared attendees (insight #1). Only 37% of meetings use agendas (external source #319).

#### Scenario: Track document read receipts

- GIVEN a meeting package distributed 5 days before the meeting
- WHEN the organizer checks preparation status
- THEN the system MUST show which attendees have opened each document
- AND a preparation compliance rate MUST be calculated as a KPI (user story #339)

#### Scenario: Reminder for unprepared attendees

- GIVEN a meeting in 24 hours with 3 attendees who have not read the documents
- WHEN the reminder threshold is reached
- THEN automatic reminders MUST be sent to unprepared attendees (user story #339)

---

### Requirement: Quorum Tracking

The system MUST provide real-time quorum tracking during meetings, showing present count versus required count, with automatic alerts when quorum is at risk.

**Feature tier**: V1
**Evidence**: Gemeentewet Art. 20 requires >50% of seated members present (insight #17). iBabs provides quorum tracking as a key differentiator (external source #97).

#### Scenario: Real-time quorum display

- GIVEN a council meeting requiring 16 of 31 members present
- WHEN 18 members are present and 1 leaves
- THEN the quorum display MUST update to 17/16 (quorum met)
- AND if another member leaves (16/16), the display MUST show a warning
- AND if quorum is lost (15/16), the display MUST show a critical alert (user story #342)

#### Scenario: Attendance analytics per member

- GIVEN 12 meetings over the past year
- WHEN the secretary views attendance analytics
- THEN attendance rate per member/faction MUST be displayed
- AND late arrivals and early departures MUST be tracked
- AND patterns (e.g., consistently absent on Thursdays) MUST be visible (user story #342)

---

### Requirement: Meeting Analytics Dashboard

The system MUST provide analytics on meeting efficiency including: average meeting duration, decision throughput (decisions per meeting), time per agenda item vs. allocated time, attendance trends, cost trends over time, and meeting effectiveness scoring.

**Feature tier**: V1
**Evidence**: 15 essential meeting KPIs identified by Flowtrace (external source #323). 12 highest-impact manager KPIs benchmarked by Worklytics (external source #347). Flowtrace dashboard implementation guide provides reference architecture (external source #349).

#### Scenario: View meeting duration trends

- GIVEN a body with 12 meetings in the past year
- WHEN the administrator views the efficiency analytics
- THEN a chart MUST show meeting duration over time
- AND the average duration MUST be displayed
- AND meetings exceeding the scheduled duration MUST be highlighted

#### Scenario: Compare allocated vs. actual time per item type

- GIVEN analytics data from multiple meetings
- WHEN the user views the "Time Allocation Accuracy" report
- THEN the system MUST show average allocated vs. actual time grouped by item type (informational, discussion, decision)
- AND recommendations MUST be shown (e.g., "Decision items average 25 min actual vs. 15 min allocated -- consider increasing default allocation")

#### Scenario: Personal meeting scorecard

- GIVEN a manager with meeting history over the past month
- WHEN they view their personal scorecard
- THEN the following KPIs MUST be displayed (user story #331):
  - Meetings per week with trend
  - Average meeting duration
  - Decision rate (decisions per meeting)
  - Action item completion rate
  - Focus time vs. meeting time ratio
- AND organizational benchmarks MUST be available for comparison (external source #347)

#### Scenario: Meeting audit report

- GIVEN a director requesting a quarterly meeting audit
- WHEN the audit report is generated (user story #333)
- THEN it MUST show:
  - Agenda compliance rate (% of meetings with agenda)
  - Goal achievement rate
  - Cost per meeting
  - Attendance vs. invited ratio
  - Recurring meeting staleness detection
- AND recommendations for meetings to cancel or merge MUST be included

#### Scenario: Decision throughput analytics

- GIVEN analytics data across meetings for a governance body
- WHEN the user views decision throughput
- THEN decisions per meeting MUST be calculated
- AND time-to-decision (from proposal to adoption) MUST be tracked
- AND bottleneck states (where decisions stall longest) MUST be highlighted

---

### Requirement: AI-Powered Meeting Transcription and Summaries

The system SHOULD support automatic meeting transcription with AI-generated summaries, key decision extraction, and action item identification. All processing MUST be self-hosted for GDPR compliance.

**Feature tier**: V2
**Evidence**: AI meeting tool usage grew 17x (insight #5). No self-hosted alternative exists (insight #9). Otter.ai achieves 93-95% accuracy. Fireflies and Otter face lawsuits over privacy.

#### Scenario: Real-time transcription

- GIVEN a meeting with Talk integration enabled
- WHEN transcription is activated
- THEN the system MUST provide real-time speech-to-text with >90% accuracy (user story #345)
- AND speaker identification MUST be automatic
- AND multi-language support (NL/EN minimum) MUST be available
- AND all data MUST remain within the Nextcloud tenant

#### Scenario: AI-generated meeting summary

- GIVEN a completed meeting with transcription
- WHEN the meeting ends
- THEN an AI summary MUST be generated highlighting: key decisions, action items, discussion points (user story #345)
- AND the secretary MUST be able to edit the summary before finalizing
- AND the summary MUST be linked to the meeting record

---

### Requirement: Consent-Based Decision Support

The system COULD support structured consent-based decision processes for organizations using sociocracy or similar methodologies.

**Feature tier**: V2
**Evidence**: Sociocracy For All documents the full consent process (external source #256). Growing adoption in cooperatives and progressive organizations.

#### Scenario: Run consent rounds with timer

- GIVEN a proposal ready for consent-based decision
- WHEN the facilitator starts the consent process
- THEN the system MUST support structured rounds (user story #346):
  - Proposal presentation (configurable time)
  - Quick reactions round (2-3 min per participant)
  - Objection harvesting round (timer per participant)
  - Integration round (addressing objections)
- AND each participant's position MUST be visually tracked (no objection / objection / pending)
- AND the decision MUST be recorded when all objections are resolved or withdrawn

## User Stories

### Critical & Must-Have Stories (from Intelligence DB)

1. **CEO viewing organizational meeting cost dashboard** (DB #330, priority: must): As a CEO/director, I want to see a real-time dashboard showing total organizational meeting costs, trends, and cost per department, so that I can make data-driven decisions about meeting culture. *AC: Total cost per week/month/quarter, breakdown by team, trend lines, benchmarks.*

2. **Manager tracking personal meeting KPIs** (DB #331, priority: must): As a manager, I want a personal meeting scorecard showing my KPIs (meetings/week, avg duration, decision rate, action item completion rate, focus time ratio), so that I can optimize my meeting behavior. *AC: KPIs with trends, organizational benchmarks, focus time ratio.*

3. **Meeting facilitator using agenda timers** (DB #334, priority: must): As a meeting facilitator, I want visible countdown timers per agenda item with configurable time allocations and audio/visual alerts, so that meetings stay on schedule. *AC: Per-item timer, 1-min warning, extend/pause/skip, sub-topic support.*

4. **Meeting chair tracking speaking balance** (DB #336, priority: must): As a meeting chair, I want real-time visibility into speaking time per participant with balance indicators, so that I can ensure inclusive discussion. *AC: Duration + percentage, balance indicator, gender breakdown, interruption detection, historical trends.*

5. **Secretary capturing action items** (DB #337, priority: must): As a secretary, I want to capture action items with owner and deadline, and automatically create follow-up tasks, so that meeting outcomes are actioned (currently 44% are not). *AC: Owner + deadline, AI suggestions, CalDAV VTODO, reminders, >90% completion target.*

6. **Council clerk enforcing speaking time rules** (DB #340, priority: must): As a council clerk, I want configurable speaking time rules per debate type with visible countdown, so that debates stay within allocated time. *AC: Proportional faction allocation, max 2 terms, interruption tracking, post-debate report.*

7. **Meeting secretary tracking quorum** (DB #342, priority: must): As a meeting secretary, I want real-time quorum tracking with automatic alerts, so that meetings are legally valid. *AC: Real-time present count, risk alerts, attendance history, configurable requirements.*

8. **Board secretary tracking action items** (DB #19, priority: must): As a board secretary, I want to assign, track, and report on board action items with due dates and owners, so that nothing falls through the cracks. *AC: Assignment with notifications, status tracking, automatic rollover, overdue dashboard.*

9. **Secretary auto-creating tasks from minutes** (DB #1834, priority: must): As a secretary, I want action items in meeting minutes to automatically become CalDAV VTODO tasks assigned to the responsible person. *AC: VTODO creation, linked to decision/meeting, assignee notification.*

10. **Committee chair managing speaking order** (DB #159, priority: must): As a commissievoorzitter, I want to manage the speaking order for committee members and insprekers, so that everyone gets a fair opportunity.

11. **Council chair managing debate speaking time** (DB #163, priority: must): As a voorzitter, I want to track and display remaining speaking time per faction during debates, so that all factions get fair representation.

### Should-Have Stories

12. **Director generating meeting audit reports** (DB #333, priority: should): As a director, I want periodic meeting audit reports showing agenda compliance, goal achievement, costs, and attendance, so I can eliminate waste.

13. **Council secretary tracking BOB phases** (DB #341, priority: should): As a council secretary, I want to tag agenda items with BOB phases and track progression across meetings, so that decision-making is transparent.

14. **Secretary generating AI transcription** (DB #345, priority: should): As a secretary, I want automatic meeting transcription with AI summaries, so that minutes creation is automated.

15. **Meeting organizer tracking preparation compliance** (DB #339, priority: should): As a meeting organizer, I want to track whether attendees have read pre-meeting documents.

16. **Meeting analytics as organizational capability** (DB #1201, priority: should): As an organizational leader, I want meeting analytics capabilities so that effectiveness can be measured and improved.

### Could-Have Stories

17. **Live meeting cost ticker display** (DB #335, priority: could): As a meeting organizer, I want a live cost ticker during meetings so participants are aware of the financial investment.

18. **Consent-based decision support** (DB #346, priority: could): As a meeting facilitator, I want consent-based decision processes with structured rounds and timer per round.

19. **Action item extraction from transcription** (DB #1170, priority: could): As a project manager, I want action item extraction capabilities from meeting recordings.

### Related Integration Stories

20. **Secretary preparing digital meeting package** (DB #65, priority: high): As secretary, I want to prepare a digital meeting package with agenda, previous minutes, action items, and new documents. *AC: Digital package, read confirmations, distributed X days before.*

21. **MT assistant assigning action items** (DB #91, priority: high): As a management assistant, I want to assign action items to specific MT members with deadlines and track completion. *AC: Linked to decision, acceptance notification, deadline reminders, status dashboard.*

## Acceptance Criteria

- Agenda item timers display countdown with visual alerts at 1-minute warning and on expiry
- Chair can extend (5/10 min), pause, or skip timers
- Sub-topic timeboxing is supported within parent item timers
- Speaking time is tracked per participant with duration, percentage, and balance indicators
- Gender breakdown of speaking time is available (65% improvement evidence)
- Speaker queue supports request-to-speak and chair reordering
- Speaking time rules are configurable per debate type (proportional, fixed, unlimited)
- Faction-based proportional allocation is supported for council debates
- BOB model phases are trackable per agenda item across meetings
- Meeting cost calculator shows running cost based on participant rates (updated per second)
- Cost threshold alerts warn when budget is exceeded
- Organizational meeting cost dashboard shows totals, trends, and team breakdowns
- Action items captured with owner, deadline, and linked to agenda item/decision
- CalDAV VTODO tasks auto-created from action items via _todos metadata
- Action item completion rate tracked per person, team, and meeting (target >90%)
- Incomplete action items automatically roll over to next meeting agenda
- Real-time quorum tracking with alerts when quorum is at risk
- Attendance analytics show per-member rates, late arrivals, and patterns
- Meeting preparation compliance tracked via document read receipts
- Analytics dashboard shows 15 KPIs: duration trends, decision throughput, cost, attendance, completion rates
- Personal meeting scorecard with organizational benchmarks
- Meeting audit reports identify staleness, waste, and merge opportunities
- All timing data is recorded for post-meeting analytics
- AI transcription (V2) processes data self-hosted for GDPR compliance

## External Sources

| # | Type | Title | Key Insight |
|---|------|-------|-------------|
| 319 | research | State of Meetings Report 2024 (Fellow) | 78% too many meetings, AI grew 17x, only 37% use agendas |
| 321 | research | Microsoft Work Trend Index | Interrupted every 2 min, 60% time in communication, 60% ad hoc meetings |
| 325 | research | $37B/Year in Unnecessary Meetings | $25K/employee/year, $100M/year large companies |
| 320 | research | 45 Meeting Statistics 2025 (Fellow) | 67% say clear agenda most important, managers 13hrs/week |
| 342 | research | Action Item Completion Rate | 44% never completed, 71% meetings fail objectives |
| 323 | product | 15 Meeting KPIs (Flowtrace) | Decision rate, action item completion, attendance analytics |
| 347 | research | 12 Manager Scorecard KPIs (Worklytics) | Meeting hours/week, 1:1 frequency, focus time ratio |
| 349 | product | Meeting Analytics Dashboard Guide (Flowtrace) | Executive tiles, team investment view, meeting audit |
| 322 | product | Flowtrace Meeting Analytics | 2.2M meetings benchmarked, real-time cost, behavioral nudges |
| 337 | methodology | BOB-model: Beeldvorming/Oordeelsvorming/Besluitvorming | Dutch 3-phase decision model for municipalities |
| 339 | regulation | Reglement van Orde gemeenteraad 2023 | Max 5 min speaking, proportional allocation, max 2 terms |
| 256 | documentation | Consent Decision Making (Sociocracy For All) | Structured rounds: proposal, reactions, objections, integration |
| 292 | legal | Gemeentewet Quorum Art. 20/29/30 | Municipal quorum and voting rules |
| 273 | documentation | Gemeentewet Art. 32 | Rollcall voting, oral votes, tie-breaking |
| 51 | comparison | Best Meeting Software for Local Governments | OpenMeeting, Civica, OnBoard, CivicPlus, eScribe |
| 70 | blog | Civica Modern.Gov | 50-70% reduction in admin time, 300+ UK councils |
| 97 | case-study | iBabs for Local Government | Quorum tracking, RSVP, voting, ISO/GDPR certified |
| 106 | product | Decisions.com for Microsoft Teams | Smart timer, AI agenda, decision capture, engagement score |
| 372 | review | iBabs Reviews (Capterra) | 4.6/5, easy to use, system dependency risk during meetings |
