# portal-contribution Specification (delta)

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [portal-public-council-records](../../) (this delta)

## ADDED Requirements

### Requirement: REQ-PCR-001 A visitor finds the published meetings with their agenda

decidiq's portal contribution SHALL declare a public record list `publicMeetings` that lists every meeting publication (`publication-payload` with `oriType: Vergadering`) whose `publicationDate` has passed and that is not withdrawn, the latest first, and whose record shows the meeting's date, time and location and its published agenda items in order. A meeting that was not published, or was withdrawn, SHALL NOT be listed and SHALL have no record.

#### Scenario: A resident looks up the next council meeting
- GIVEN the griffie published the agenda of the council meeting of Thursday
- WHEN a resident opens the meetings page of the public site
- THEN the meeting is listed with its date and place, and opening it shows the agenda items in order

#### Scenario: A meeting that is not published
- GIVEN a meeting whose agenda is not published
- WHEN a resident opens the meetings page
- THEN that meeting is not listed

### Requirement: REQ-PCR-002 A visitor reads the published decisions with their outcome

decidiq's portal contribution SHALL declare a public record list `publicDecisions` that lists every live decision publication (`oriType: Besluit`), and whose record shows the decision's date, its outcome, its text and, when it was put to a vote, the totals for, against and abstained. It SHALL never show who voted how; that is the voting record's, under the ORI vote rule.

#### Scenario: A resident reads a council decision
- GIVEN the council adopted the credit for the Lindelaan lighting with 22 for and 5 against, and it was published
- WHEN a resident opens the decision on the public site
- THEN she reads the decision text, "Aangenomen", and the totals 22 and 5
