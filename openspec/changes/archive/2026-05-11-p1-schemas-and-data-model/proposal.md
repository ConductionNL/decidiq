## Why

Decidesk requires a fully defined data model before any feature development can begin. All domain objects — meetings, agendas, motions, voting rounds, decisions, minutes, and governance bodies — must be registered as OpenRegister schemas so that the platform's built-in CRUD, search, audit, and workflow capabilities can serve them. Without this foundation, no subsequent sprint can store or retrieve data.

## What Changes

- Register `decidesk` as an OpenRegister register with 17 schemas
- Define schema files for all entities from ADR-000: ActionItem, AgendaItem, Amendment, Decision, DigitalDocument, GovernanceBody, Meeting, Minutes, MonetaryAmount, Motion, Offer, Order, Participant, Product, Report, Vote, VotingRound
- Create `lib/Settings/decidesk_register.json` as the OpenAPI 3.0 register template
- Implement `RepairStep` that imports the register via `ConfigurationService::importFromApp()`
- Link cross-entity relations using OpenRegister relation mechanism (register + schema + objectId) — no foreign keys
- Define seed data (3–5 Dutch objects per entity) for development and demo environments

## Capabilities

### New Capabilities

- `schemas-and-data-model`: Full OpenRegister schema definition for all 17 Decidesk entities with field types, required flags, relations, and schema.org type annotations

### Modified Capabilities

_(none — this is the initial schema registration)_

## Impact

- **Backend**: `lib/Settings/decidesk_register.json` (new), `lib/Migration/RepairStep.php` (imports register on install/upgrade)
- **Data**: No existing data affected — initial schema creation only
- **Dependencies**: OpenRegister app must be installed and active
- **Other apps**: None — register is namespaced to `decidesk`
