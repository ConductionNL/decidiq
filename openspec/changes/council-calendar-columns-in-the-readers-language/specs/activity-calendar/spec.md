## ADDED Requirements

### Requirement: The council calendar reads in the reader's language

The `publicCalendar` collection SHALL declare a label for every field it shows in its columns and detail, through decidiq's catalogue, so the portal never heads it with the schema's English titles. It MUST show only whitelisted fields and SHALL leave the filter fields `audiences` and `oriType` out of its columns and detail.

#### Scenario: A Dutch resident opens the Vergaderkalender
- GIVEN a resident reads the portal in Dutch
- WHEN she opens the Vergaderkalender
- THEN the columns read Datum, Vergadering, Orgaan and Vergaderingstype
- @e2e exclude declaration contract; covered by PHPUnit `PortalContributionProviderTest::testTheCouncilCalendarHeadingsReadInTheReadersLanguage`
