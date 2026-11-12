# p4-integration Specification (delta)

## ADDED Requirements

### Requirement: Records can be created and changed through the REST API

An authorised external client SHALL be able to create and change decidiq records through a REST API, with the same validation and access rights as the app itself. The objects API of OpenRegister serves every decidiq schema for this; the versioned decidiq API serves reads.

#### Scenario: A client creates a meeting

- **GIVEN** a client authenticated as a user who may create meetings
- **WHEN** it sends a POST with a new meeting to the objects endpoint of the decidiq register's meeting schema
- **THEN** the meeting is created and returned with its id
- **AND** a later GET on that id returns the meeting

#### Scenario: A client without the right is refused

- **WHEN** a client authenticated as a user without write access sends the same POST
- **THEN** the request is refused and no meeting is created
