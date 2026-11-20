# resolution-minutes Specification (delta)

## ADDED Requirements

### Requirement: A meeting's evidence is bundled into one proof package

The proof package of a meeting SHALL hold the approved minutes, the result of every voting round, the attendance list and the signatures on the minutes, next to the convocation and the quorum snapshot, and SHALL carry one hash over the whole package so a reader can check that nothing changed.

#### Scenario: The secretary makes a proof package

- **GIVEN** a meeting with signed, approved minutes and two closed voting rounds
- **WHEN** the secretary chooses to make the proof package
- **THEN** one package is stored in the meeting folder holding the minutes, both round results, the attendance list and the signatures
- **AND** recomputing the hash over the package gives the hash it carries

#### Scenario: Minutes not yet approved

- **GIVEN** a meeting whose minutes are still a draft
- **WHEN** the secretary makes the proof package
- **THEN** the package says the minutes are not approved and names their current state, instead of leaving them out silently
