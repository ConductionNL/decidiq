# urgent-decision-procedure Specification (delta)

## Purpose

The urgent decisions register shows who declared each urgent decision, when it was reported to the ratifying body and whether it still waits for ratification. Follows board DcSpoedbesluiten. Covers row rou-06 (decision 100) together with the change urgent-decision-procedure.

## ADDED Requirements

### Requirement: REQ-020 The declaration records the declaring body or office

When a decision is declared urgent, the system SHALL store the governance body on whose behalf it was declared (`urgencyDeclaredByBody`) and, when the power belongs to a single office, that office (`urgencyDeclaredInOffice`). A declaration without a declaring body SHALL be refused. The urgent decisions list SHALL show the office when set, else the body name, under the decision title.

#### Scenario: The mayor declares an emergency ordinance
- GIVEN the mayor holds the urgency power for the municipality
- WHEN she declares "Noodverordening Havenfestival 2026" urgent in the office Burgemeester
- THEN the decision stores the municipality's body and the office Burgemeester
- AND the list shows "Burgemeester" under the title

#### Scenario: The college declares
- GIVEN the College van B en W declares "Tijdelijke opvang in sporthal De Linde" urgent
- WHEN the list loads
- THEN the line under the title reads "College van B en W"

### Requirement: REQ-021 The report to the ratifying body is recorded once

The system SHALL record on an urgent decision the date it was first reported to the ratifying body (`reportedToRatifyingBodyAt`), set by the first of: the action Gemeld aan de raad, placing the ratification agenda item, or linking a raadsinformatiebrief to the decision. A later step SHALL NOT overwrite or clear it. Only the roles allowed to declare urgency for the body SHALL run the action. The list SHALL add "gemeld aan de raad op <date>" to the line under the title, naming the ratifying body.

#### Scenario: Reported before the ratifying meeting
- GIVEN the urgent decision "Noodverordening Havenfestival 2026" without a report date
- WHEN the griffier runs Gemeld aan de raad on 6 October
- THEN the decision stores 6 October
- AND the list line reads "Burgemeester · gemeld aan de raad op 6 okt"

#### Scenario: The agenda item comes later
- GIVEN a decision reported on 6 October
- WHEN the ratification agenda item is placed on 15 October
- THEN the report date stays 6 October

#### Scenario: A member without the role
- GIVEN a council member who may not declare urgency
- WHEN they call the report action
- THEN the request is refused and nothing changes

### Requirement: REQ-022 The register reads as the board draws it

The urgent decisions page SHALL list decisions with `isUrgent` newest first by `urgencyDeclaredAt`, with the columns Besluit, Spoed verklaard, Wacht op bekrachtiging (a pill reading Ja or Nee), Levenscyclus (a pill) and Acties (Bekijken, opening the decision page). It SHALL offer the quick filters Alle spoedbesluiten, Wacht op bekrachtiging and Bekrachtigd, each with its count, a line "n van m spoedbesluiten · nieuwste eerst", row selection and Downloaden of the selected or all rows.

#### Scenario: Counts on the quick filters
- GIVEN five urgent decisions, three awaiting ratification
- WHEN the page loads
- THEN the quick filters read Alle spoedbesluiten 5, Wacht op bekrachtiging 3, Bekrachtigd 2

#### Scenario: Filter on waiting
- GIVEN the same five decisions
- WHEN a user picks Wacht op bekrachtiging
- THEN three rows show, each with the pill Ja, and the line reads "3 van 5 spoedbesluiten · nieuwste eerst"

#### Scenario: Download
- GIVEN the list filtered on Bekrachtigd
- WHEN a user clicks Downloaden
- THEN a file with the two rows and the board's columns is offered
