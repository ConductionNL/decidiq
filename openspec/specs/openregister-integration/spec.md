---
status: idea
---

# OpenRegister Integration Specification

## Purpose

OpenRegister is the data layer for Decidesk. All Decidesk entities are stored as OpenRegister objects with schema validation. This specification covers the 12 entity schemas, the register configuration file, the repair step that imports them, the JSON-based register configuration, the `useObjectStore` data access pattern on the frontend, the `ObjectService`/mapper data access pattern on the backend, and the `ConfigurationService` import mechanism.

**Standards**: OpenAPI 3.0.0 (register config format), JSON Schema (validation), Schema.org (type annotations), Akoma Ntoso (legislative document types)
**Feature tier**: MVP

**Evidence sources**: Intelligence DB user stories #6, #23, #29, #38, #43, #55, #56, #62, #66, #71, #73, #126, #129, #160, #181, #189, #206, #207, #262, #263, #264, #272, #280, #281, #286; Requirement clusters #43 (Besluitvorming, 271 reqs/133 tenders), #54 (e-Depot digital archive, 247 reqs/117 tenders), #55 (Document creation/generation, 298 reqs/116 tenders); Category features: document-storage, search-filter, document-linking, full-text-search

## Data Model

The register configuration file `lib/Settings/decidesk_register.json` defines all 12 schemas in OpenAPI 3.0.0 format. The schemas form a connected graph of governance entities:

```
Organization
  └── Body (1:N)
        ├── Member (N:M via membership)
        ├── ProcessTemplate (1:N)
        └── Meeting (1:N)
              ├── AgendaItem (1:N, ordered)
              │     ├── Motion (0:N)
              │     │     └── Amendment (0:N)
              │     └── Decision (0:1)
              │           ├── Vote (1:N per VotingRound)
              │           │     └── Ballot (1:N)
              │           └── Resolution (0:1)
              └── Minutes (0:1)
```

### Schema Overview

| Schema | @type | Purpose | Key Properties |
|--------|-------|---------|----------------|
| Organization | schema:Organization | Top-level entity | name, legalForm, logo, language, timezone |
| Body | schema:GovernmentOrganization | Governing body | name, type, members, quorumRules, defaultTemplate |
| Meeting | schema:Event | Scheduled meeting | title, dateTime, location, body, status, agenda |
| AgendaItem | schema:ListItem | Ordered agenda entry | title, position, type, documents, decision |
| Motion | akomaNtoso:motion | Formal proposal | title, text, proposer, status, linkedAgendaItem |
| Amendment | akomaNtoso:amendment | Motion modification | text, proposer, parentMotion, status |
| Decision | schema:ChooseAction | Outcome of deliberation | title, status, votingRound, resolution, body |
| Vote | schema:VoteAction | Voting round | method, majorityRule, status, deadline, ballots |
| Ballot | schema:ChooseAction | Individual vote cast | voter, choice, timestamp, proxy |
| Resolution | akomaNtoso:act | Formal adopted decision | title, text, decisionDate, body, status |
| Minutes | schema:DigitalDocument | Meeting record | meeting, text, status, approvedBy, approvedDate |
| ProcessTemplate | schema:HowTo | Workflow template | name, steps, mandatoryAgendaItems, bodyType |

## Requirements

---

### REQ-OR-01: Register Configuration File

The system MUST define all Decidesk schemas in a single `lib/Settings/decidesk_register.json` file using OpenAPI 3.0.0 format. The file MUST define schemas for all 12 entity types listed above with proper `@type` annotations, required properties, property types, and validation rules.

**Feature tier**: MVP

#### Scenario: Register config defines all required schemas

- GIVEN the `decidesk_register.json` file
- WHEN it is parsed by OpenRegister's ConfigurationService
- THEN it MUST contain a valid OpenAPI 3.0.0 document with `components.schemas` for all 12 entity types
- AND each schema MUST include `@type` with the appropriate Schema.org or Akoma Ntoso type annotation
- AND each schema MUST define required properties, property types, and validation rules (format, minLength, maxLength, enum)

#### Scenario: Schema properties match data model definitions

- GIVEN the entity definitions in the data model section
- WHEN comparing with the register config schemas
- THEN all properties defined in the data model MUST be present in the corresponding schema
- AND property types and validation rules MUST match
- AND cross-references between schemas MUST use `$ref` or UUID string references

#### Scenario: Register config includes cross-references between schemas

- GIVEN the `decision` schema references a `meeting` and `agendaItem`
- WHEN the register is imported
- THEN OpenRegister MUST validate referential integrity between objects
- AND the frontend MUST be able to resolve references for display
- AND cascade behavior (e.g., deleting a meeting soft-deletes its agenda items) MUST be defined

---

### REQ-OR-02: Repair Step Import

The system MUST import the register configuration during app installation and upgrade via a Nextcloud repair step. The repair step MUST use `ConfigurationService::importFromApp()` to create or update the `decidesk` register and all schemas.

**Feature tier**: MVP

#### Scenario: Initial installation creates register and schemas

- GIVEN a fresh Nextcloud installation with Decidesk enabled
- WHEN the repair step runs
- THEN a register named `decidesk` MUST be created in OpenRegister
- AND all 12 schemas from `decidesk_register.json` MUST be imported
- AND the register MUST be ready for data storage immediately

#### Scenario: App upgrade updates schemas without data loss

- GIVEN an existing Decidesk installation with data (meetings, decisions, votes)
- WHEN the app is upgraded and the repair step runs
- THEN schema changes MUST be applied to the `decidesk` register
- AND existing data MUST be preserved
- AND new properties MUST have default values where applicable
- AND removed properties MUST NOT cause data corruption

#### Scenario: Repair step is idempotent

- GIVEN a Decidesk installation where the repair step has already run
- WHEN the repair step runs again (e.g., via `occ maintenance:repair`)
- THEN the register and schemas MUST remain unchanged if no config changes exist
- AND no duplicate schemas MUST be created
- AND existing data MUST NOT be affected

---

### REQ-OR-03: Frontend Data Access via useObjectStore

The frontend MUST access Decidesk data via the OpenRegister API using `useObjectStore` from `@conduction/nextcloud-vue`. The frontend MUST NOT make direct API calls to a Decidesk backend for CRUD operations. Each entity type MUST have its own store instance configured with the correct register and schema.

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
- AND validation errors MUST be displayed to the user with field-level messages

#### Scenario: Update an existing agenda item

- GIVEN a user editing an agenda item's position or title
- WHEN they save the changes
- THEN the frontend MUST use `useObjectStore.save()` with the existing object UUID
- AND OpenRegister MUST validate the update against the `agendaItem` schema
- AND optimistic locking MUST prevent concurrent edit conflicts

#### Scenario: Delete a motion with cascade

- GIVEN a motion with 2 amendments
- WHEN the user deletes the motion
- THEN `useObjectStore.delete()` MUST remove the motion from OpenRegister
- AND all linked amendments MUST be handled according to cascade rules (soft-delete or orphan)
- AND the UI MUST update reactively to reflect the deletion

---

### REQ-OR-04: Backend Service Access

Backend services (VotingService, WorkflowService, ConvocationService) MUST access OpenRegister data via the `ObjectService` or mapper classes (`ObjectMapper`). The backend MUST NOT maintain its own database tables for Decidesk entities.

**Feature tier**: MVP

#### Scenario: VotingService reads votes from OpenRegister

- GIVEN a voting round in progress
- WHEN the VotingService needs to calculate results
- THEN it MUST query OpenRegister for all ballot objects linked to the voting round
- AND it MUST use the `ObjectService` or `ObjectMapper` to retrieve the data
- AND calculations MUST be performed on the retrieved data without caching in a separate table

#### Scenario: WorkflowService advances decision status

- GIVEN a decision in "deliberating" status
- WHEN the WorkflowService processes a status transition to "voting"
- THEN it MUST update the decision object in OpenRegister via `ObjectService::saveObject()`
- AND the status change MUST be validated against the `processTemplate` workflow steps
- AND the transition MUST be logged in the Nextcloud Activity feed

#### Scenario: ConvocationService generates member list

- GIVEN a meeting being convoked for body "ALV" with 200 members
- WHEN the ConvocationService retrieves the member list
- THEN it MUST query OpenRegister for all member objects linked to the body
- AND it MUST filter by active membership status and voting rights
- AND it MUST resolve Nextcloud user accounts for email delivery

---

### REQ-OR-05: Organization Schema

The `organization` schema MUST store the top-level organization configuration with properties for identity, localization, and governance defaults.

**Feature tier**: MVP

#### Scenario: Store organization with full configuration

- GIVEN an administrator configuring the organization
- WHEN they save the organization settings
- THEN the object MUST be stored with properties: name (required, string), legalForm (required, enum: vereniging/stichting/cooperatie/nv/bv), logo (string, file reference), defaultLanguage (enum: nl/en), timezone (string), currency (string, default: EUR), retentionPeriodYears (integer)
- AND the `@type` MUST be `schema:Organization`
- AND only one organization object MUST exist per Decidesk installation

---

### REQ-OR-06: Body Schema

The `body` schema MUST store governing body definitions including membership, roles, quorum rules, and template assignments.

**Feature tier**: MVP

#### Scenario: Store body with members and quorum rules

- GIVEN a body "Bestuur" with 5 members
- WHEN the body is saved to OpenRegister
- THEN the object MUST include: name (required), type (required, enum: council/board/assembly/committee/team/ledenraad), members (array of member references), quorumRules (object with type, threshold, proxyLimit), defaultTemplate (processTemplate reference), parentBody (optional body reference)
- AND the `@type` MUST be `schema:GovernmentOrganization`

---

### REQ-OR-07: Meeting and AgendaItem Schemas

The `meeting` schema MUST store meeting metadata and link to agenda items. The `agendaItem` schema MUST store ordered agenda entries with document references and decision links.

**Feature tier**: MVP

#### Scenario: Store meeting with ordered agenda

- GIVEN a meeting with 8 agenda items
- WHEN the meeting is saved
- THEN the meeting object MUST include: title, dateTime (ISO 8601), endDateTime, location, body (reference), status (enum: draft/convoked/in_progress/completed/cancelled), agendaItems (ordered array of references)
- AND each agendaItem MUST include: title, position (integer), type (enum: decision/information/discussion/procedural), documents (array of file references), timeAllocation (minutes), decision (optional reference)

---

### REQ-OR-08: Motion and Amendment Schemas

The `motion` schema MUST store formal proposals. The `amendment` schema MUST store modifications to motions with parent references.

**Feature tier**: MVP

#### Scenario: Store motion with amendments

- GIVEN a motion "Approve Budget 2026" with 2 amendments
- WHEN the motion and amendments are saved
- THEN the motion MUST include: title, text (rich text), proposer (member reference), coSigners (array), linkedAgendaItem (reference), status (enum: submitted/under_debate/voted/adopted/rejected/withdrawn)
- AND each amendment MUST include: text, proposer, parentMotion (reference), status, type (enum: addition/deletion/replacement)
- AND amendments MUST be votable independently before the main motion

---

### REQ-OR-09: Decision, Vote, and Ballot Schemas

The `decision` schema MUST store deliberation outcomes. The `vote` schema MUST store voting round configuration. The `ballot` schema MUST store individual votes cast.

**Feature tier**: MVP

#### Scenario: Store decision with voting round and ballots

- GIVEN a decision that has been voted on
- WHEN the full decision record is stored
- THEN the decision MUST include: title, description, status (enum: proposed/deliberating/voting/adopted/rejected/deferred/withdrawn), body (reference), meeting (reference), agendaItem (reference), resolution (optional reference)
- AND the vote MUST include: method (enum: open/roll_call/secret_ballot/digital), majorityRule (enum: simple/absolute/qualified_two_thirds/unanimous), status (enum: pending/open/closed), deadline (ISO 8601), ballots (array of references), result (object with forCount, againstCount, abstainCount, absentCount)
- AND each ballot MUST include: voter (member reference, nullable for secret ballot), choice (enum: for/against/abstain/blank), timestamp, isProxy (boolean), proxyFor (optional member reference)

---

### REQ-OR-10: Resolution and Minutes Schemas

The `resolution` schema MUST store formally adopted decisions. The `minutes` schema MUST store meeting records.

**Feature tier**: MVP

#### Scenario: Store resolution with full provenance

- GIVEN a resolution adopted by the ALV
- WHEN the resolution is stored
- THEN the resolution MUST include: title, text (rich text), decisionDate (ISO date), body (reference), meeting (reference), decision (reference), sequenceNumber (integer, auto-increment per body per year), status (enum: draft/adopted/published/archived), effectiveDate (optional), expiryDate (optional)
- AND the `@type` MUST be `akomaNtoso:act`

#### Scenario: Store minutes with approval workflow

- GIVEN minutes drafted after a board meeting
- WHEN the minutes are stored
- THEN the minutes MUST include: meeting (reference), text (rich text), status (enum: draft/submitted/approved/published), author (member reference), approvedBy (array of member references), approvedDate (optional), corrections (array of text changes with submitter)
- AND the `@type` MUST be `schema:DigitalDocument`

---

### REQ-OR-11: ProcessTemplate Schema

The `processTemplate` schema MUST store workflow definitions that govern how decisions progress through stages.

**Feature tier**: MVP

#### Scenario: Store process template with workflow steps

- GIVEN a template "ALV Standard Decision"
- WHEN the template is stored
- THEN the template MUST include: name, description, bodyType (enum matching body types), steps (ordered array with name, type, requiredRole, duration), mandatoryAgendaItems (array of agenda item definitions), votingDefaults (object with method, majorityRule), quorumDefaults (object with type, threshold)
- AND steps MUST define valid transitions between statuses

---

### REQ-OR-12: Data Integrity and Validation

The system MUST ensure data integrity across all schemas through OpenRegister's validation layer. Cross-references MUST be validated, required fields MUST be enforced, and enum values MUST be constrained.

**Feature tier**: MVP

#### Scenario: Reject invalid cross-reference

- GIVEN a decision referencing a non-existent meeting UUID
- WHEN the decision is saved via the API
- THEN OpenRegister MUST reject the save with a 422 validation error
- AND the error MUST identify the invalid reference field and the missing UUID

#### Scenario: Enforce required fields on meeting creation

- GIVEN a meeting creation request missing the required `body` field
- WHEN the request is processed
- THEN OpenRegister MUST reject the request with a 422 error
- AND the error MUST list all missing required fields

#### Scenario: Validate enum values for decision status

- GIVEN a decision update with status "invalid_status"
- WHEN the update is processed
- THEN OpenRegister MUST reject the update with a 422 error specifying the allowed enum values
- AND the decision MUST remain unchanged

---

### REQ-OR-13: Query Patterns and Performance

The system MUST support efficient query patterns for common governance operations: meetings by body, decisions by status, votes by voting round, action items by assignee, and resolutions by date range.

**Feature tier**: MVP

#### Scenario: Query upcoming meetings for a body

- GIVEN a body "Bestuur" with 20 past and 3 upcoming meetings
- WHEN the frontend queries meetings with filter `body=<uuid>&dateTime[gte]=now&_order[dateTime]=ASC`
- THEN OpenRegister MUST return only the 3 upcoming meetings in chronological order
- AND the response MUST include pagination metadata (total, page, limit)

#### Scenario: Query voting results for a decision

- GIVEN a decision with a completed voting round containing 50 ballots
- WHEN the backend queries ballots with filter `vote=<uuid>`
- THEN OpenRegister MUST return all 50 ballot objects
- AND the response MUST be efficient (indexed query, not full table scan)

---

### REQ-OR-14: Seed Data

The system MUST provide optional seed data for demonstration and testing. Seed data MUST include sample organizations, bodies, meetings, decisions, and process templates representing common governance scenarios.

**Feature tier**: V1

#### Scenario: Install seed data for demo environment

- GIVEN a fresh Decidesk installation with OpenRegister
- WHEN the administrator runs the seed data import (via admin setting or CLI)
- THEN the system MUST create a sample organization "Demo Vereniging" with bodies (Bestuur, ALV, Kascommissie)
- AND sample process templates MUST be created for standard decision, statute amendment, and board election
- AND 3 sample meetings with agenda items and decisions MUST be created
- AND seed data MUST be clearly marked as sample data and easily removable

## User Stories

1. **Administrator setting up Decidesk**: As an administrator, I want Decidesk to automatically create its data schemas when installed so that the app is ready to use without manual database configuration. (Source: OpenRegister integration pattern)

2. **Developer extending the data model**: As a developer, I want all Decidesk entities defined in a single JSON config file so that schema changes are versioned, reviewable, and automatically applied on upgrade. (Source: OpenRegister integration pattern)

3. **Frontend developer querying decisions**: As a frontend developer, I want to use the standard useObjectStore composable to query decisions so that I do not need to implement custom API clients or state management. (Source: @conduction/nextcloud-vue pattern)

4. **Secretary recording board decisions with vote distribution**: As a secretary, I want to record each board decision with the vote distribution per board member so that we comply with WBTR documentation requirements. (Source: intelligence DB #66, priority: high)

5. **Secretary maintaining digital conflict of interest register**: As a board secretary, I want to maintain a digital conflict of interest register with audit trail, so that potential conflicts are proactively identified. (Source: intelligence DB #23, priority: must)

6. **Audit committee tracking findings**: As an audit committee member, I want to review audit findings and track management remediation across committee meetings. (Source: intelligence DB #29, priority: must)

7. **Secretary maintaining shareholder register**: As a board secretary, I want to maintain a digital shareholder register with automatic updates for share transfers. (Source: intelligence DB #38, priority: must)

8. **Secretary organizing document archive**: As a board secretary, I want a structured, searchable governance document archive with access controls and retention enforcement. (Source: intelligence DB #43, priority: must)

9. **Secretary registering attendance and verifying quorum**: As secretary, I want to register attendance and automatically calculate quorum including proxy votes. (Source: intelligence DB #55, priority: critical)

10. **Secretary verifying voting rights**: As secretary, I want to verify each attendee's voting rights so that only eligible members participate. (Source: intelligence DB #56, priority: critical)

11. **Kascommissie performing financial audit**: As a kascommissie member, I want a structured audit checklist and access to all financial records. (Source: intelligence DB #71, priority: critical)

12. **Committee member submitting report**: As a committee member, I want to submit reports using a standard template so the board can act on our advice. (Source: intelligence DB #73, priority: medium)

13. **Employee searching decision register**: As an employee, I want to search a central decision register by topic, date, meeting, or decision-maker. (Source: intelligence DB #129, priority: high)

14. **Citizen registering to speak**: As a burger, I want to register online to speak at a committee meeting about an agenda item that affects me. (Source: intelligence DB #160, priority: must)

15. **Wethouder reporting on decision implementation**: As a wethouder, I want to report on the implementation progress of council decisions. (Source: intelligence DB #181, priority: should)

16. **ICT beheerder publishing council data via ORI API**: As an ICT beheerder, I want council meeting data to be published via the Open Raadsinformatie API. (Source: intelligence DB #189, priority: should)

17. **ICT beheerder migrating historical data**: As an ICT beheerder, I want to migrate all historical council data from the old RIS to the new system. (Source: intelligence DB #206, priority: must)

18. **ICT beheerder validating migrated data**: As an ICT beheerder, I want to validate that all migrated data is complete and correctly structured. (Source: intelligence DB #207, priority: must)

19. **Records manager auto-populating MDTO metadata**: As a records manager, I want the system to auto-populate metadata fields from context. (Source: intelligence DB #262, priority: critical)

20. **Information manager validating MDTO metadata**: As an information manager, I want to validate records against the MDTO schema for eDepot transfer. (Source: intelligence DB #263, priority: critical)

21. **Records manager inheriting metadata from dossier**: As a records manager, I want records to inherit metadata from their parent dossier. (Source: intelligence DB #264, priority: high)

22. **Compliance officer verifying destruction**: As a compliance officer, I want to verify destruction completeness and generate compliance reports. (Source: intelligence DB #272, priority: high)

23. **Compliance officer maintaining tamper-evident audit log**: As a compliance officer, I want a tamper-evident audit log recording every action on every record. (Source: intelligence DB #281, priority: critical)

24. **Records manager performing faceted search**: As a records manager, I want to filter search results using metadata facets. (Source: intelligence DB #286, priority: high)

25. **Proxy advisor accessing standardized resolution data**: As a proxy advisor, I want machine-readable resolution data via API. (Source: intelligence DB #6, priority: could)

## Acceptance Criteria

1. All 12 Decidesk schemas are defined in `lib/Settings/decidesk_register.json` using OpenAPI 3.0.0 format
2. Each schema has a `@type` annotation (Schema.org or Akoma Ntoso)
3. Repair step creates/updates the `decidesk` register via `ConfigurationService::importFromApp()`
4. Repair step is idempotent (safe to run multiple times)
5. App upgrade preserves existing data while applying schema changes
6. Frontend uses `useObjectStore` from `@conduction/nextcloud-vue` for all CRUD operations
7. Each entity type has its own store instance with correct register and schema configuration
8. Backend uses `ObjectService`/`ObjectMapper` for data access (no own DB tables)
9. Cross-references between entities are validated by OpenRegister
10. Required fields are enforced at the schema level
11. Enum values are constrained for all status fields
12. Decision status follows the workflow: proposed -> deliberating -> voting -> adopted/rejected/deferred/withdrawn
13. Ballot choices are constrained to: for, against, abstain, blank
14. Body types are constrained to: council, board, assembly, committee, team, ledenraad
15. Meeting statuses are constrained to: draft, convoked, in_progress, completed, cancelled
16. Resolution sequence numbers auto-increment per body per year
17. Minutes approval workflow tracks corrections, approvers, and approval date
18. ProcessTemplate defines valid status transitions and mandatory agenda items
19. Query patterns support efficient filtering by body, status, date range, and assignee
20. Seed data is available for demonstration with clearly marked sample data
21. All validation errors return 422 with field-level error messages
22. Cascade behavior is defined for parent-child relationships (e.g., meeting -> agendaItems)
