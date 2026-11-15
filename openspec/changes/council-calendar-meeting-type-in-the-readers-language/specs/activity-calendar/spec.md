## ADDED Requirements

### Requirement: The council calendar names the meeting type in the reader's language

The `publicCalendar` collection SHALL declare `valueLabels` for `meetingType` and `oriType`, and the portal label translator SHALL put every label under a `valueLabels` map into the reader's language through decidiq's catalogue, leaving the stored values that key the map unchanged.

#### Scenario: A Dutch resident reads the meeting type
- GIVEN a published meeting with meeting type `regular`
- WHEN a resident who reads the portal in Dutch opens the Vergaderkalender
- THEN the Vergaderingstype column reads "Gewone vergadering"
- @e2e exclude declaration contract; covered by PHPUnit `PortalContributionProviderTest::testTheCouncilCalendarNamesTheMeetingTypeInTheReadersLanguage`

#### Scenario: An English reader reads the English word
- GIVEN the same meeting
- WHEN a reader without a Dutch catalogue opens the calendar
- THEN the column reads "Regular meeting"
- @e2e exclude covered by the same PHPUnit test
