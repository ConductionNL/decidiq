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
The app SHALL allow users to create a new Participant object via a schema-driven form dialog.

#### Scenario: User opens create dialog
- **WHEN** the user clicks the add button on the participants list
- **THEN** a `CnFormDialog` opens with fields for displayName, role, party, email, joinedAt, leftAt, and votingWeight

#### Scenario: Participant is saved successfully
- **WHEN** the user fills in all required fields (displayName, role) and clicks save
- **THEN** the Participant is persisted via `ObjectService.saveObject()` and appears in the list

#### Scenario: Validation prevents save without required fields
- **WHEN** the user submits the form with `displayName` or `role` empty
- **THEN** the form displays a validation error and the object is not saved

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
