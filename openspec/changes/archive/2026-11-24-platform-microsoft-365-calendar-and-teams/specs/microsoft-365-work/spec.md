# microsoft-365-work Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [platform-microsoft-365-calendar-and-teams](../../) (this delta)
- builds on openregister `object-dates-as-a-calendar-feed` (matrix row pla-04, platform half)

## Purpose

Puts decidiq's meetings in Outlook through openregister's calendar feed and
lets a digital or hybrid meeting carry a Microsoft Teams meeting. Closes
matrix row plt-12 and decidiq's half of pla-04.

**Standards**: RFC 5545 iCalendar, Schema.org `Event` and `VirtualLocation`.

## ADDED Requirements

### Requirement: REQ-M365-001 A meeting's dates reach openregister's calendar feed

The `meeting` schema SHALL declare `configuration.calendarProvider` with
`enabled: true` and `scheduledDate` as an `appointment` ending at `endDate`,
with a fallback duration of 120 minutes, titled with the meeting title and
placed at `location`. A feed over `meeting` SHALL therefore list, per
principal, the meetings that principal may read.

#### Scenario: A moved meeting is right at the next refresh

- GIVEN a member subscribed to their meeting feed and a council meeting on 23 April at 19:30
- WHEN the clerk moves the meeting to 30 April at 20:00 and the feed is read again
- THEN the feed holds the meeting on 30 April at 20:00 and nothing on 23 April
- @e2e exclude feed contract; covered by a Newman request to the feed address before and after the move

#### Scenario: A member sees only meetings they may read

- GIVEN a closed board meeting the member may not read
- WHEN the member's feed is read
- THEN that meeting is not in it
- @e2e exclude access contract; covered by a Newman request with two principals

### Requirement: REQ-M365-002 Publishing an agenda makes no calendar call

`AgendaService` SHALL NOT depend on openregister's `CalendarEventService` and
SHALL NOT call a calendar method when an agenda is published. The feed reads
the meeting as it is.

#### Scenario: The dead call is gone

- GIVEN the decidiq source tree
- WHEN it is searched for `updateMeetingEvent` and for `CalendarEventService` in `lib/Service/AgendaService.php`
- THEN neither is found, and publishing an agenda still records its version
- @e2e exclude static and unit check; covered by PHPUnit on AgendaService and a grep in CI

### Requirement: REQ-M365-003 A member subscribes from the personal settings

The personal settings page SHALL have a section "Calendar subscription" that
creates an openregister feed token over `meeting` for the member, shows the
feed address with a copy button and where to paste it in Outlook, lists the
member's existing subscriptions with their creation date, and revokes one.

#### Scenario: A member adds their meetings to Outlook

- GIVEN a member without a subscription
- WHEN they open Preferences and press "Subscribe in Outlook or on your phone"
- THEN the section shows a feed address ending in `.ics`, a copy button and the line "In Outlook, choose Add calendar, then Subscribe from web"

#### Scenario: A member stops a subscription

- GIVEN a member with one subscription
- WHEN they press "Stop this subscription"
- THEN the subscription is gone from the list and its address answers 404

### Requirement: REQ-M365-004 A digital or hybrid meeting can carry a Teams meeting

`Meeting` SHALL carry an optional `teamsMeeting` with `joinUrl`, `externalId`,
`createdBy` and `createdAt`. `joinUrl` SHALL accept only addresses starting
with `https://teams.microsoft.com/`. When a source is linked to the
`microsoft-365` connection, the chair or secretary SHALL be able to press
"Create a Teams meeting" on the meeting page, which asks the service through
integriq for an online meeting with the meeting's title, start and end and
stores the answer. Without a linked source the button SHALL NOT be shown.

#### Scenario: The clerk creates the Teams meeting

- GIVEN a digital meeting on 16 April and a linked `microsoft-365` source
- WHEN the secretary presses "Create a Teams meeting" on the meeting page
- THEN the Planning widget shows the Teams join address, the secretary's name and the time

#### Scenario: A pasted address that is not Teams is refused

- GIVEN the Planning widget of a meeting
- WHEN the secretary pastes `https://example.org/meet/123` as the Teams join address
- THEN the save is refused and the field says the address must start with `https://teams.microsoft.com/`

#### Scenario: Only staff create a Teams meeting

- GIVEN a council member who is neither chair nor secretary
- WHEN they call `POST /api/meetings/{meetingId}/teams-meeting`
- THEN the response is 403 and nothing is sent to integriq
- @e2e exclude authorization contract; covered by PHPUnit on the controller and a Newman IDOR request

### Requirement: REQ-M365-005 Members join the Teams meeting from decidiq

When a meeting has `teamsMeeting.joinUrl`, the meeting page and the live
meeting page SHALL show "Join in Microsoft Teams", opening the join address in
a new tab.

#### Scenario: A member joins from the live meeting page

- GIVEN a live digital meeting with a Teams join address
- WHEN a member opens the live meeting page
- THEN a "Join in Microsoft Teams" button is shown and opens the join address
