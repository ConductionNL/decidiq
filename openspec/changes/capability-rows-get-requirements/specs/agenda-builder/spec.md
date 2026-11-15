# agenda-builder Specification (delta)

## ADDED Requirements

### Requirement: Copy the whole agenda of an earlier meeting

The agenda copy dialog SHALL let the secretary copy every item of a chosen earlier meeting onto the current meeting's agenda in one step, keeping their order and descriptions.

#### Scenario: The secretary copies last week's agenda

- **GIVEN** the meeting of 7 October with five agenda items and an empty meeting of 14 October
- **WHEN** the secretary chooses to copy all items from 7 October onto the agenda of 14 October
- **THEN** the agenda of 14 October holds the same five items in the same order with their descriptions
