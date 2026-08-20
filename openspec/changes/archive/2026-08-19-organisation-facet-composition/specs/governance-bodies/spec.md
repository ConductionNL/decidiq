# governance-bodies Specification

## ADDED Requirements

### Requirement: REQ-GBD-013 — Faction is a GovernanceBody discriminator, not a parallel schema

A faction (fractie) MUST be represented as an ordinary `GovernanceBody` object with
`bodyType=faction` and a `parentBody` reference to the council/body it belongs to —
never as a separate `Fractie` schema. This is the ADR-006 mode/type-adaptation
mechanism ("Type discriminators — where a real subtype distinction exists, use an
enum field on the universal entity... not a new schema") applied to factions the
same way it was already applied to corporate boards (`bodyType=supervisory-board`,
REQ-GBD-012). The `GovernanceBody` schema MUST add:
- `bodyType` enum value `faction`, alongside the existing ten values.
- `parentBody` property (`type: string`, `format: uuid`, `$ref: GovernanceBody`,
  nullable) — the parent body a faction (or, generically, any sub-body) belongs to.
  Not restricted to `bodyType=faction`; any `GovernanceBody` MAY set it.

Members of a faction relate to it exactly as they relate to any other
`GovernanceBody` — via `Membership.governanceBody` pointing at the faction's own
object id, per REQ-GBD-011. No new relation schema is introduced for
faction membership.

#### Scenario: Faction is a bodyType value, not a new schema
- **GIVEN** the `GovernanceBody` schema
- **WHEN** its `bodyType` enum is inspected
- **THEN** it includes `faction` alongside the existing ten values
- **AND** no `Fractie` schema exists in the register

#### Scenario: A faction references its parent council via parentBody
- **GIVEN** a `GovernanceBody` "Gemeenteraad Amsterdam" (`bodyType=legislative`)
- **WHEN** a `GovernanceBody` "GroenLinks-fractie" is created with `bodyType=faction`
  and `parentBody` set to the Gemeenteraad Amsterdam object id
- **THEN** `parentBody` resolves to the Gemeenteraad Amsterdam `GovernanceBody`

#### Scenario: Faction members use the same Membership relation as any other body
- **GIVEN** the "GroenLinks-fractie" `GovernanceBody` from the scenario above
- **WHEN** a raadslid's `Membership.governanceBody` is set to the faction's object id
- **THEN** that Membership resolves the faction as its `GovernanceBody`, identically
  to a Membership in a non-faction body
