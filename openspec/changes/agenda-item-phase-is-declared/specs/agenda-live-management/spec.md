## ADDED Requirements

### Requirement: REQ-LIV-010 The agenda item declares its phase

The `AgendaItem` schema SHALL declare a `phase` property, an enum of `voorstel`, `beeldvorming`, `oordeelsvorming`, `besluitvorming` and `completed`, facetable so lists can filter on it. Moving an item to its next phase SHALL write `phase`, and an item created without a phase SHALL read as `beeldvorming`. Existing items that carry the phase in the undeclared `status` property SHALL have it moved to `phase` once, on upgrade.

#### Scenario: The chair moves an item to forming an opinion

- GIVEN a decision item at phase `beeldvorming`
- WHEN the chair presses Next phase on the live meeting screen
- THEN the item's `phase` reads `oordeelsvorming` after a reload
- AND the agenda list can filter items on that phase

#### Scenario: An item from before the upgrade keeps its phase

- GIVEN an agenda item with the undeclared `status` value `besluitvorming`
- WHEN the upgrade runs
- THEN the item's `phase` reads `besluitvorming`

### Requirement: REQ-LIV-011 The agenda item page shows the phase

The agenda item page SHALL show the phase of a discussion or decision item as a stage line with the current phase marked, as DcAgendapunt draws it. An informational item SHALL show no phase.

#### Scenario: A member opens a decision item

- GIVEN a decision item at phase `oordeelsvorming`
- WHEN member Noor opens the agenda item page
- THEN she sees the stage line with forming an opinion marked as the current phase
