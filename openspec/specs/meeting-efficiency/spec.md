---
status: idea
---

# Meeting Efficiency Specification

## Purpose
@e2e exclude V1 feature — agenda item timers, speaking-time tracking, meeting cost calculator, and analytics dashboard are not yet implemented in the SPA (no corresponding manifest pages or Vue components exist for these features).

Meeting efficiency features help governance bodies run productive meetings. This includes real-time timers for agenda items and speaking time, a meeting cost calculator (based on participant hourly rates), analytics on meeting duration and decision throughput, and tools to keep discussions focused. These features transform Decidesk from a compliance tool into a productivity platform that actively improves organizational decision-making.

**Standards**: Schema.org (`Duration`, `MonetaryAmount`)
**Feature tier**: V1

## Requirements

---

### Requirement: Agenda Item Timer

The system MUST provide a visible countdown timer for each agenda item during meeting conduct. The timer MUST start when the chair opens an agenda item and alert when the allocated time is exceeded. The chair MUST be able to extend, pause, or skip the timer.

**Feature tier**: V1

#### Scenario: Timer alerts when time is exceeded

- GIVEN an agenda item with 15 minutes allocated and the timer running
- WHEN 15 minutes have elapsed
- THEN the system MUST display a visual alert (flashing timer, color change to red)
- AND the chair MUST be presented with options: "Extend 5 min", "Extend 10 min", "Close item"
- AND the actual time spent MUST be recorded for analytics

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

---

### Requirement: Speaking Time Management

The system MUST track speaking time per participant during discussions. The chair MUST be able to set speaking time limits. The system MUST maintain a speaker queue for managing turn-taking.

**Feature tier**: V1

#### Scenario: Enforce speaking time limit

- GIVEN a speaking time limit of 3 minutes per speaker
- WHEN a speaker has been speaking for 3 minutes
- THEN the system MUST display a visual and optional audio alert
- AND the chair MUST be able to grant an extension or move to the next speaker

#### Scenario: Manage speaker queue

- GIVEN a discussion in progress on an agenda item
- WHEN 4 participants request to speak
- THEN the system MUST display a speaker queue in order of request
- AND the chair MUST be able to reorder the queue
- AND the current speaker MUST be highlighted

---

### Requirement: Meeting Cost Calculator

The system MUST calculate and display the running cost of a meeting based on participant count and configurable hourly rates. The cost MUST be displayed in real-time during the meeting and in meeting analytics afterward.

**Feature tier**: V1

#### Scenario: Display running meeting cost

- GIVEN a meeting with 12 participants and an average hourly rate of EUR 75
- WHEN the meeting has been running for 45 minutes
- THEN the system MUST display the running cost as "EUR 675" (12 x 75 x 0.75)
- AND the cost MUST update in real-time as the meeting progresses

#### Scenario: Show cost per agenda item in analytics

- GIVEN a completed meeting with 5 agenda items and tracked time per item
- WHEN the user views meeting analytics
- THEN the cost MUST be broken down per agenda item based on actual time spent
- AND the most expensive agenda items MUST be highlighted

---

### Requirement: Meeting Analytics Dashboard

The system MUST provide analytics on meeting efficiency including: average meeting duration, decision throughput (decisions per meeting), time per agenda item vs. allocated time, attendance trends, and cost trends over time.

**Feature tier**: V1

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
- AND recommendations MUST be shown (e.g., "Decision items average 25 min actual vs. 15 min allocated — consider increasing default allocation")

## User Stories

1. **Secretary tracking action items**: As a board secretary, I want to assign, track, and report on board action items with due dates and owners, so that nothing falls through the cracks between meetings. (Source: intelligence DB #19)

2. **Secretary preparing meeting package**: As secretary, I want to prepare a digital meeting package with agenda, previous minutes, action items, and new documents so that all board members arrive prepared. (Source: intelligence DB #65)

3. **Chair tracking decision implementation**: As chair, I want to track the implementation status of ALV decisions with responsible persons and deadlines so that I can report progress at the next ALV. (Source: intelligence DB #77)

## Acceptance Criteria

- Agenda item timers display countdown with visual alerts on expiry
- Chair can extend, pause, or skip timers
- Speaking time is tracked per participant with configurable limits
- Speaker queue supports request-to-speak and chair reordering
- Meeting cost calculator shows running cost based on participant rates
- Analytics dashboard shows duration trends, decision throughput, and cost breakdowns
- All timing data is recorded for post-meeting analytics
