# activity-calendar Specification

## Purpose
Lets staff filter the one calendar of all council activities by audience and by
body, and lets residents read a public calendar of the activities staff
publish. Closes the missing half of matrix row pla-16.

**Standards**: Schema.org `Event` and `audience`, ORI `Vergadering`.

## Requirements

### Requirement: REQ-ACAL-001 A kind of meeting declares its audiences

`MeetingType` SHALL carry `audiences`, a facetable array of `council`,
`executive`, `joint-arrangement`, `residents` and `staff`. An administrator SHALL
be able to set it on the meeting type page without supplier help.

#### Scenario: The administrator marks an information evening for residents

- GIVEN the meeting type "Informatieavond" without audiences
- WHEN an administrator opens it under meeting types in the settings, ticks "Residents" and "Council" and saves
- THEN the type's `audiences` hold `residents` and `council`

### Requirement: REQ-ACAL-002 The calendar filters by audience and by body

The meeting calendar SHALL offer an "Audience" filter and a "Body" filter. With
an audience chosen it SHALL show only meetings whose type lists that audience.
With a body chosen it SHALL show only meetings of that body. The filters SHALL
be kept in the page address. A meeting without a type SHALL appear under "No
audience set" and SHALL NOT disappear silently.

#### Scenario: A clerk looks at what is on for the executive

- GIVEN a "Collegevergadering" type with audience `executive` and a "Raadsvergadering" type with `council` and `residents`, each with a meeting in April
- WHEN the clerk opens the calendar for April and chooses "Executive" under "Audience"
- THEN only the collegevergadering is on the grid, and the address carries the filter

#### Scenario: A meeting without a type stays findable

- GIVEN an April meeting that still uses the deprecated meeting type list
- WHEN the clerk chooses "No audience set" under "Audience"
- THEN that meeting is on the grid

### Requirement: REQ-ACAL-003 The calendar asks the server for the visible month

The calendar SHALL fetch only the meetings whose `scheduledDate` falls within
the visible grid, with the chosen filters, and SHALL NOT fetch a fixed first
page of all meetings.

#### Scenario: A register with many meetings shows the right month

- GIVEN 600 meetings over three years, 12 of them in April
- WHEN the clerk opens April
- THEN all 12 are on the grid and the request asked for April only
- @e2e exclude request shape; covered by a vitest on the load parameters

### Requirement: REQ-ACAL-004 Staff publish a public meeting to the residents' calendar

The chair or secretary SHALL be able to publish a meeting flagged `isPublic` to
the public calendar from the Publication widget on the meeting page, without a
formal agenda. The published entry SHALL be a `Vergadering` payload carrying
only title, body name, date, meeting type, location and audiences, and SHALL be
withdrawable like any publication.

#### Scenario: The clerk publishes an information evening

- GIVEN the public meeting "Informatieavond windpark Noord" on 8 April, without an agenda
- WHEN the secretary presses "Publish to the public calendar" in the Publication widget
- THEN a `Vergadering` payload with its date, location and audiences is published and the widget shows it as published

### Requirement: REQ-ACAL-005 Residents read the calendar without an account

`PortalContributionProvider` SHALL contribute an anonymous `publicCalendar`
collection over `publication-payload`, filtered by default to `Vergadering`,
sorted by `meetingDate`, whose field whitelist holds only `title`, `bodyName`,
`meetingDate`, `meetingType`, `location`, `audiences` and `oriType`.

#### Scenario: A resident finds the information evening

- GIVEN the published entry for "Informatieavond windpark Noord" and portaliq installed
- WHEN a resident without an account opens the portal's "Council calendar" page
- THEN the evening is listed with its date, place and audiences

#### Scenario: The calendar whitelist holds calendar fields only

- GIVEN the provider constructed with portaliq absent
- WHEN the `publicCalendar` collection is read
- THEN it is `anonymous: true` and its `fields` hold only the seven calendar fields
- @e2e exclude declaration contract; covered by PHPUnit on PortalContributionProvider
