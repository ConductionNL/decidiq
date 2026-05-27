---
status: idea
---

# OpenRegister Integration Specification

## Purpose
@e2e exclude Pure backend/data-layer spec — register JSON config, repair step, and OR API access patterns are server-side contracts verified by RegisterJsonTest.php and PHP unit tests. The frontend data-access pattern (useObjectStore) is a library contract with no dedicated decidesk UI form to drive.

OpenRegister is the data layer for Decidesk. All Decidesk entities (meetings, decisions, agenda items, votes, resolutions, bodies, process templates) are stored as OpenRegister objects with schema validation. This specification covers the register and schema definitions, the repair step that imports them, the JSON-based register configuration file, and the data access patterns used by the frontend and backend.

**Standards**: OpenAPI 3.0.0 (register config format), JSON Schema (validation), Schema.org (type annotations)
**Feature tier**: MVP

## Data Model

The register configuration file `lib/Settings/decidesk_register.json` defines all schemas in OpenAPI 3.0.0 format. See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for entity property tables and type mappings.

## Requirements

---

### Requirement: Register Configuration File

The system MUST define all Decidesk schemas in a single `lib/Settings/decidesk_register.json` file using OpenAPI 3.0.0 format. The file MUST define schemas for: `meeting`, `agendaItem`, `decision`, `vote`, `votingRound`, `motion`, `amendment`, `resolution`, `minutes`, `body`, `processTemplate`, `actionItem`, and `member`.

**Feature tier**: MVP

#### Scenario: Register config defines all required schemas

- GIVEN the `decidesk_register.json` file
- WHEN it is parsed by OpenRegister's ConfigurationService
- THEN it MUST contain a valid OpenAPI 3.0.0 document with `components.schemas` for all entity types
- AND each schema MUST include `@type` with the appropriate Schema.org or Akoma Ntoso type annotation
- AND each schema MUST define required properties, property types, and validation rules

#### Scenario: Schema properties match ARCHITECTURE.md definitions

- GIVEN the entity definitions in ARCHITECTURE.md
- WHEN comparing with the register config schemas
- THEN all properties defined in ARCHITECTURE.md MUST be present in the corresponding schema
- AND property types and validation rules MUST match

#### Scenario: Register config includes cross-references between schemas

- GIVEN the `decision` schema references a `meeting` and `agendaItem`
- WHEN the register is imported
- THEN OpenRegister MUST validate referential integrity between objects
- AND the frontend MUST be able to resolve references for display

---

### Requirement: Repair Step Import

The system MUST import the register configuration during app installation and upgrade via a Nextcloud repair step. The repair step MUST use `ConfigurationService::importFromApp()` to create or update the `decidesk` register and all schemas.

**Feature tier**: MVP

#### Scenario: Initial installation creates register and schemas

- GIVEN a fresh Nextcloud installation with Decidesk enabled
- WHEN the repair step runs
- THEN a register named `decidesk` MUST be created in OpenRegister
- AND all schemas from `decidesk_register.json` MUST be imported
- AND the register MUST be ready for data storage

#### Scenario: App upgrade updates schemas without data loss

- GIVEN an existing Decidesk installation with data
- WHEN the app is upgraded and the repair step runs
- THEN schema changes MUST be applied to the `decidesk` register
- AND existing data MUST be preserved
- AND new properties MUST have default values where applicable

---

### Requirement: Frontend Data Access Pattern

The frontend MUST access Decidesk data via the OpenRegister API using `useObjectStore` from `@conduction/nextcloud-vue`. The frontend MUST NOT make direct API calls to a Decidesk backend for CRUD operations.

**Feature tier**: MVP

#### Scenario: Fetch decisions from OpenRegister via object store

- GIVEN the Vue frontend needs to display the decision list
- WHEN the component mounts
- THEN it MUST use `useObjectStore` to fetch objects from the `decidesk` register with the `decision` schema
- AND the store MUST handle pagination, filtering, and sorting via OpenRegister API parameters
- AND loading and error states MUST be managed by the store

#### Scenario: Create a new meeting via object store

- GIVEN the user fills in the meeting creation form
- WHEN they submit the form
- THEN the frontend MUST use `useObjectStore.save()` to create the object in OpenRegister
- AND the object MUST be validated against the `meeting` schema by OpenRegister
- AND validation errors MUST be displayed to the user

---

### Requirement: Backend Service Access

Backend services (VotingService, WorkflowService) MUST access OpenRegister data via the ObjectService or mapper classes. The backend MUST NOT maintain its own database tables for Decidesk entities.

**Feature tier**: MVP

#### Scenario: VotingService reads votes from OpenRegister

- GIVEN a voting round in progress
- WHEN the VotingService needs to calculate results
- THEN it MUST query OpenRegister for all vote objects linked to the voting round
- AND it MUST use the ObjectService or VoteMapper to retrieve the data
- AND calculations MUST be performed on the retrieved data without caching in a separate table

## User Stories

1. **Administrator setting up Decidesk**: As an administrator, I want Decidesk to automatically create its data schemas when installed so that the app is ready to use without manual database configuration. (Source: OpenRegister integration pattern)

2. **Developer extending the data model**: As a developer, I want all Decidesk entities defined in a single JSON config file so that schema changes are versioned, reviewable, and automatically applied on upgrade. (Source: OpenRegister integration pattern)

3. **Frontend developer querying decisions**: As a frontend developer, I want to use the standard useObjectStore composable to query decisions so that I do not need to implement custom API clients or state management. (Source: @conduction/nextcloud-vue pattern)

## Acceptance Criteria

- All Decidesk schemas are defined in `lib/Settings/decidesk_register.json` (OpenAPI 3.0.0 format)
- Repair step creates/updates the `decidesk` register via ConfigurationService::importFromApp()
- Frontend uses useObjectStore from @conduction/nextcloud-vue for all CRUD
- Backend uses ObjectService/mappers for data access (no own DB tables)
- Schema.org type annotations are set on all entities
- Cross-references between entities are validated by OpenRegister
- App upgrade preserves existing data while applying schema changes
