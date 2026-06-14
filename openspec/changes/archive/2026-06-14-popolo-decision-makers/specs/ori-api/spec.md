# ori-api Specification

**Status**: in-progress
**Scope**: decidesk
**OpenSpec changes**:
- popolo-decision-makers

## Purpose

Retargets the ORI `/persons` and `/memberships` resources so they serialize real Popolo
`Person` and `Membership` objects instead of the flat `Participant` schema (ADR-003).
The endpoint paths and JSON-LD response envelope are unchanged; only the source schema
behind the two resource slugs changes.

## ADDED Requirements

### Requirement: REQ-ORI-006 — ORI persons and memberships sourced from Popolo schemas
The system MUST source the ORI `/api/ori/v1/persons` resource from the `person` schema
and the `/api/ori/v1/memberships` resource from the `membership` schema (not from
`participant`). The `OriController::RESOURCE_MAP` MUST map `persons` → `person` and
`memberships` → `membership`. The list path MUST use the OpenRegister config-array
pattern where `register`/`schema` live inside `filters`
(`findAll(['limit' => N, 'filters' => ['register' => 'decidesk', 'schema' => $schema, ...]])`).
The ORI `@type` labels (`Person`, `Membership`), endpoint paths, and JSON-LD envelope
MUST remain unchanged.

#### Scenario: Persons endpoint serializes real Popolo Persons
- GIVEN seeded `Person` records exist
- WHEN GET `/api/ori/v1/persons` is called
- THEN the response is JSON-LD with `@type: Person`
- AND `items` contains the seeded Persons serialized with `name` from the Person `name` field
- AND no `Participant` objects are returned

#### Scenario: Memberships endpoint serializes real Popolo Memberships
- GIVEN seeded `Membership` records exist
- WHEN GET `/api/ori/v1/memberships` is called
- THEN the response is JSON-LD with `@type: Membership`
- AND `items` contains the seeded Memberships
- AND no `Participant` objects are returned

#### Scenario: Person email is exposed on public ORI serialization
- GIVEN a Person carries an `email`
- WHEN GET `/api/ori/v1/persons` is called anonymously
- THEN the serialized Person exposes `email` (open-government transparency for officeholders; the `serializeOri` email gate allows Person in addition to Organization)

#### Scenario: Endpoint paths and envelope unchanged
- GIVEN an external ORI consumer
- WHEN it requests `/api/ori/v1/persons` or `/api/ori/v1/memberships`
- THEN the path and the `@context`/`@type`/`count`/`items` envelope are identical to before this change

## Non-Functional Requirements

- **Performance:** The retarget introduces no new query path — it changes only the schema slug passed to `findAll`; list response time MUST match the prior Participant-backed behaviour.
- **Accessibility:** N/A — API change only.
- **Internationalization:** N/A — ORI output is data, not localised UI strings.

## Acceptance Criteria

- [ ] `RESOURCE_MAP['persons']` = `person` and `RESOURCE_MAP['memberships']` = `membership`
- [ ] `/api/ori/v1/persons` returns Popolo Persons (non-empty against seed data)
- [ ] `/api/ori/v1/memberships` returns Popolo Memberships (non-empty against seed data)
- [ ] Person `email` is not exposed on the public Person serialization
- [ ] Endpoint paths and JSON-LD envelope unchanged

## Notes

- ADR-003 (ORI compatibility — Person/Membership are direct Popolo mappings).
- Follows the C1 (`unify-decision-supertype`) `findAll` config-array fix where
  register/schema go inside `filters`.
