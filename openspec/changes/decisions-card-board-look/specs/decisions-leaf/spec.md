## ADDED Requirements

### Requirement: The decisions card is a board card on a host's tab

The decisions card SHALL carry the host's card contract class so the host's look gives it the board radius and padding and joins it to the open tab, and SHALL set its title at 17px on a 1.2 line over a 14px subtitle.

#### Scenario: a case's Besluiten tab in the board look
- **GIVEN** a dossiq case in the board look
- **WHEN** the handler opens the Besluiten tab
- **THEN** the decisions card's top-left corner SHALL be square under the tab
- **AND** its subtitle SHALL read at 14px
