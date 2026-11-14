## ADDED Requirements

### Requirement: The decisions leaf reads in the user's language on any host page

The decisions leaf SHALL load decidiq's translations before it mounts on a host page, and SHALL mount in English when they cannot be loaded.

#### Scenario: a Dutch handler opens a case's Besluiten tab
- **GIVEN** a user whose language is Dutch
- **WHEN** the decisions leaf mounts on a dossiq case
- **THEN** its button SHALL read "Besluit voorbereiden" and its subtitle "Uit de besluitvorming van decidiq, hier getoond"
