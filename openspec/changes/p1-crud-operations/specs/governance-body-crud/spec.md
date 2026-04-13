## ADDED Requirements

### Requirement: Governance body list view
The app SHALL display all GovernanceBody objects in a paginated, searchable list using `CnIndexPage` with `useListView`.

#### Scenario: User views governance body list
- **WHEN** the user navigates to `/governance-bodies`
- **THEN** a list of GovernanceBody objects is shown with columns for name, bodyType, domain, and termEnd

#### Scenario: User filters by body type
- **WHEN** the user applies a filter on the `bodyType` field via `CnFilterBar`
- **THEN** only GovernanceBody objects matching the selected bodyType are shown

#### Scenario: Governance body list is paginated
- **WHEN** there are more governance bodies than the page size
- **THEN** `CnPagination` is shown and the user can navigate to other pages

### Requirement: Create a governance body
The app SHALL allow users to create a new GovernanceBody object via a schema-driven form dialog.

#### Scenario: User opens create dialog
- **WHEN** the user clicks the add button on the governance bodies list
- **THEN** a `CnFormDialog` opens with fields for name, bodyType, domain, votingDefault, quorumRule, termStart, and termEnd

#### Scenario: Governance body is saved successfully
- **WHEN** the user fills in all required fields (name, bodyType, domain) and clicks save
- **THEN** the GovernanceBody is persisted via `ObjectService.saveObject()` and appears in the list

#### Scenario: Validation prevents save without required fields
- **WHEN** the user submits the form with `name` or `bodyType` empty
- **THEN** the form shows a validation error and the object is not saved

### Requirement: View governance body detail
The app SHALL display a detail view for a GovernanceBody including its properties, linked Meetings, and linked Participants.

#### Scenario: User opens a governance body detail page
- **WHEN** the user clicks a row in the governance bodies list
- **THEN** the router navigates to `/governance-bodies/:id` and `CnDetailPage` renders the body's properties

#### Scenario: Related Meetings shown in detail
- **WHEN** the governance body detail page loads
- **THEN** a `CnDetailCard` section lists the Meetings linked to this GovernanceBody via OpenRegister relations

#### Scenario: Related Participants shown in detail
- **WHEN** the governance body detail page loads
- **THEN** a `CnDetailCard` section lists the Participants linked to this GovernanceBody, showing displayName and role

#### Scenario: CnObjectSidebar is available
- **WHEN** the user is on the governance body detail page
- **THEN** `CnObjectSidebar` is rendered with Files, Notes, Tags, Tasks, and Audit Trail tabs

### Requirement: Edit a governance body
The app SHALL allow users to edit an existing GovernanceBody object.

#### Scenario: User edits a governance body
- **WHEN** the user clicks the Edit button on the governance body detail page
- **THEN** a `CnFormDialog` opens pre-populated with the current data

#### Scenario: Changes are persisted
- **WHEN** the user modifies fields and clicks save
- **THEN** the GovernanceBody is updated via `ObjectService.saveObject()` and the detail view reflects the changes

### Requirement: Delete a governance body
The app SHALL allow users to delete a GovernanceBody object with confirmation.

#### Scenario: User deletes a governance body
- **WHEN** the user clicks Delete and confirms in `CnDeleteDialog`
- **THEN** the GovernanceBody is removed via `ObjectService.deleteObject()` and the user is redirected to the list

#### Scenario: Delete is cancelled
- **WHEN** the user cancels the delete dialog
- **THEN** the GovernanceBody is not deleted
