# motion-amendment Specification (delta)

## ADDED Requirements

### Requirement: An amendment can target a proposal as well as a motion

A member SHALL be able to submit an amendment against a proposal on the agenda, a decision that is not a motion, in the same way as against a motion. The amendment SHALL be stored with a reference to that proposal and SHALL be listed on the proposal's page.

#### Scenario: A member amends a proposal

- **GIVEN** the proposal "Parking policy 2027" on the agenda of the council
- **WHEN** a member submits an amendment with new text for one of its paragraphs
- **THEN** the amendment is stored with a reference to that proposal and starts as a draft
- **AND** the proposal's page lists the amendment with the difference against the proposal text
