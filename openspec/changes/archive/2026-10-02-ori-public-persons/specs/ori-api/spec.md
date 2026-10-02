# ori-api Specification (delta)

**Scope**: decidiq

Matrix row: pub-14 (decidiq `openspec/parity/capabilities.json`).

## ADDED Requirements

### Requirement: REQ-ORI-007 The public ORI API names public role holders only

`GET /api/ori/v1/persons` SHALL return, to anonymous callers, every person who holds or held a public role: a membership with the role chair, vice-chair, secretary, treasurer or member (not observer or guest) in a governance body with `publishVotingRecords: true`. Each person SHALL carry exactly `id`, `name`, `image` and `biography`, never another field. `GET /api/ori/v1/persons/{id}` SHALL return exactly the people the collection returns and answer 404 for every other id.

#### Scenario: A portal resolves a council member's name

- GIVEN Marie Janssen is a member of Gemeenteraad Amsterdam, which publishes its voting records
- WHEN an anonymous caller requests `/api/ori/v1/persons`
- THEN Marie is returned with her id, name, image and biography only

#### Scenario: Nobody without a public role is named

- GIVEN a person without a membership, a member of a body that does not publish its voting records, and a guest
- WHEN an anonymous caller requests any of them by id
- THEN the request answers 404

## MODIFIED Requirements

### Requirement: REQ-ORI-006 — ORI persons and memberships sourced from Popolo schemas

#### Scenario: Persons are read through the person publication rule
- GIVEN a Person carries an `email`
- WHEN GET `/api/ori/v1/persons` is called anonymously
- THEN the serialized Person carries no `email`
