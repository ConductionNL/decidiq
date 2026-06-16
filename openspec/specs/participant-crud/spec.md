---
status: in-progress
status-note: In progress 2026-06-14 via popolo-decision-makers (Participant deprecated in favour of Popolo Person + Membership; retained as a compatibility shim per ADR-001 §2).
openspec-changes:
  - popolo-decision-makers
---

# participant-crud Specification

## Purpose
TBD - created by archiving change 2026-05-11-p1-crud-operations. Update Purpose after archive.
## Requirements
### Requirement: Participant list view
The app SHALL display all Participant objects in a paginated, searchable list using `CnIndexPage` with `useListView`.

#### Scenario: User views participant list
- **WHEN** the user navigates to `/participants`
- **THEN** a list of Participant objects is shown with columns for displayName, role, party, and email

#### Scenario: User searches participants
- **WHEN** the user types in the search input on the participants list
- **THEN** the list filters via OpenRegister full-text search on displayName, role, and party

#### Scenario: User filters by role
- **WHEN** the user applies a role filter via `CnFilterBar`
- **THEN** only Participants with the selected role (chair, member, secretary, etc.) are shown

### Requirement: Create a participant
The app SHALL allow users to create a new decision maker as a `Person` + `Membership`
pair via the Popolo model (per the `person-and-membership` spec). Creating a new flat
`Participant` object is deprecated: the `Participant` schema is retained only as a
backward-compatibility shim and SHALL NOT be the default target for new decision-maker
data. Existing `Participant` records remain readable and editable.
<!-- Previous behavior: the app created a new Participant object via a schema-driven form
     dialog with displayName/role/party/email/joinedAt/leftAt/votingWeight, persisted via
     ObjectService.saveObject(). That flat model is deprecated in favour of Person + Membership. -->

#### Scenario: New decision maker created as Person + Membership
- **GIVEN** the user adds a new decision maker
- **WHEN** the record is created
- **THEN** a `Person` (identity) and a `Membership` (role/party/votingWeight/body link) are persisted via `ObjectService.saveObject()`, not a flat Participant

#### Scenario: Participant creation path is deprecated
- **WHEN** new decision-maker data is seeded or created by this change
- **THEN** it is NOT created as a `Participant` object (the shim is retained for existing records only)

#### Scenario: Validation prevents save without required fields
- **WHEN** the user submits the Person/Membership form with `name` (Person) or `role` (Membership) empty
- **THEN** the form displays a validation error and the objects are not saved

### Requirement: View participant detail
The app SHALL display a detail view for a single Participant showing all their properties.

#### Scenario: User opens a participant detail page
- **WHEN** the user clicks a row in the participants list
- **THEN** the router navigates to `/participants/:id` and `CnDetailPage` renders the participant's properties

#### Scenario: GovernanceBody relation shown in detail
- **WHEN** the participant detail page loads
- **THEN** a `CnDetailCard` section displays the linked GovernanceBody name and type if a relation exists

#### Scenario: CnObjectSidebar is available
- **WHEN** the user is on the participant detail page
- **THEN** `CnObjectSidebar` is rendered with Files, Notes, Tags, Tasks, and Audit Trail tabs

### Requirement: Edit a participant
The app SHALL allow users to edit an existing Participant object.

#### Scenario: User edits a participant
- **WHEN** the user clicks the Edit button on the participant detail page
- **THEN** a `CnFormDialog` opens pre-populated with the current participant data

#### Scenario: Changes are persisted
- **WHEN** the user modifies fields and clicks save
- **THEN** the Participant is updated via `ObjectService.saveObject()` and the detail view reflects the new values

### Requirement: Delete a participant
The app SHALL allow users to delete a Participant object with confirmation.

#### Scenario: User deletes a participant
- **WHEN** the user clicks Delete and confirms in `CnDeleteDialog`
- **THEN** the Participant is removed via `ObjectService.deleteObject()` and the user is redirected to the participants list

#### Scenario: Delete is cancelled
- **WHEN** the user cancels the delete dialog
- **THEN** the Participant is not deleted and the detail page remains

### Requirement: REQ-PCR-010 — Participant schema deprecated in favour of Person + Membership
The system MUST annotate the `Participant` schema in
`lib/Settings/decidesk_register.json` as deprecated (in its `description`), and MUST NOT
create new decision-maker seed data as `Participant` objects. New person/relationship
data MUST be modelled as `Person` + `Membership` per the `person-and-membership` spec.
The `Participant` schema MUST remain active (not deleted) so existing references —
the `Meeting` quorum aggregation (`totalParticipantCount`/`presentParticipantCount`),
`VotingService::resolveParticipantUuid()`, and dependent specs — continue to function.

#### Scenario: Participant marked deprecated
- GIVEN the decidesk register is imported
- WHEN the `Participant` schema description is inspected
- THEN it states the schema is deprecated and that Person + Membership is the canonical model

#### Scenario: New data uses Person + Membership
- GIVEN a new decision maker is seeded by this change
- WHEN the seeds are imported
- THEN the person is created as a `Person` + `Membership` pair, not a new `Participant`

#### Scenario: Participant shim still resolvable
- GIVEN existing code reads Participant records for quorum and vote-casting
- WHEN those reads execute after this change
- THEN the `Participant` schema is still present and queryable (the shim is retained)

