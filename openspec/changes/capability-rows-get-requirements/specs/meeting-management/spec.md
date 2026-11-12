# meeting-management Specification (delta)

## ADDED Requirements

### Requirement: A body limits which voting methods its meetings may use

A governance body SHALL be able to declare the voting methods its meetings may use. Opening a voting round in a meeting of that body SHALL offer only those methods and SHALL refuse any other. A body that declares none SHALL allow every method.

#### Scenario: Only the allowed methods are offered

- **GIVEN** a body that allows only for-against-abstain and roll call
- **WHEN** the chair of one of its meetings opens a voting round
- **THEN** only those two methods are offered
- **AND** a request to open a round by show of hands is refused with a message naming the allowed methods
