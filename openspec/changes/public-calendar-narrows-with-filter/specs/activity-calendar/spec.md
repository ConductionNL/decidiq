## MODIFIED Requirements

### Requirement: REQ-ACAL-005 Residents read the calendar without an account

`PortalContributionProvider` SHALL contribute an anonymous `publicCalendar`
collection over `publication-payload`, narrowed by `filter` to `Vergadering`
and filtered by default to `Vergadering`, sorted by `meetingDate`, whose field
whitelist holds only `title`, `bodyName`, `meetingDate`, `meetingType`,
`location`, `audiences` and `oriType`.

#### Scenario: A resident finds the information evening

- GIVEN the published entry for "Informatieavond windpark Noord" and portaliq installed
- WHEN a resident without an account opens the portal's "Council calendar" page
- THEN the evening is listed with its date, place and audiences

#### Scenario: The calendar whitelist holds calendar fields only

- GIVEN the provider constructed with portaliq absent
- WHEN the `publicCalendar` collection is read
- THEN it is `anonymous: true` and its `fields` hold only the seven calendar fields
- @e2e exclude declaration contract; covered by PHPUnit on PortalContributionProvider

#### Scenario: The calendar lists meetings, not decisions

- GIVEN published payloads of `oriType` "Vergadering" and "Besluit"
- WHEN portaliq reads `publicCalendar`
- THEN only the meetings are read, because the collection's `filter` narrows to `Vergadering`
- @e2e exclude declaration contract; covered by PHPUnit `PortalContributionProviderTest::testThePublicCalendarIsAnonymousWithCalendarFieldsOnly`
