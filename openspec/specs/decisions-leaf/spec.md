# decisions-leaf Specification

## Purpose

How decidiq's decisions leaf looks and reads when a host app (dossiq, pipelinq) mounts it on its own page. Introduced by the decisions-card-board-look and leaf-loads-its-translations changes.

## Requirements

### Requirement: The decisions card is a board card on a host's tab

The decisions card SHALL carry the host's card contract class so the host's look gives it the board radius and padding and joins it to the open tab, and SHALL set its title at 17px on a 1.2 line over a 14px subtitle.

#### Scenario: a case's Besluiten tab in the board look
- **GIVEN** a dossiq case in the board look
- **WHEN** the handler opens the Besluiten tab
- **THEN** the decisions card's top-left corner SHALL be square under the tab
- **AND** its subtitle SHALL read at 14px

### Requirement: The decisions leaf reads in the user's language on any host page

The decisions leaf SHALL load decidiq's translations before it mounts on a host page, and SHALL mount in English when they cannot be loaded.

#### Scenario: a Dutch handler opens a case's Besluiten tab
- **GIVEN** a user whose language is Dutch
- **WHEN** the decisions leaf mounts on a dossiq case
- **THEN** its button SHALL read "Besluit voorbereiden" and its subtitle "Uit de besluitvorming van decidiq, hier getoond"
