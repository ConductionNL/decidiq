## ADDED Requirements

### Requirement: Agenda item list view
The app SHALL display all AgendaItem objects in a paginated, searchable list using `CnIndexPage` with `useListView`.

#### Scenario: User views agenda item list
- **WHEN** the user navigates to `/agenda-items`
- **THEN** a list of AgendaItem objects is shown with columns for orderNumber, title, itemType, estimatedDuration, and isRecurring

#### Scenario: Agenda items sorted by order number by default
- **WHEN** the agenda item list loads
- **THEN** items are displayed sorted ascending by `orderNumber`

#### Scenario: User filters by item type
- **WHEN** the user applies an `itemType` filter via `CnFilterBar`
- **THEN** only AgendaItems of the selected type (informational, discussion, decision) are shown

### Requirement: Create an agenda item
The app SHALL allow users to create a new AgendaItem object via a schema-driven form dialog.

#### Scenario: User opens create dialog
- **WHEN** the user clicks the add button on the agenda items list
- **THEN** a `CnFormDialog` opens with fields for title, itemType, orderNumber, estimatedDuration, actualDuration, description, and isRecurring

#### Scenario: Agenda item is saved successfully
- **WHEN** the user fills in all required fields (title, itemType, orderNumber) and clicks save
- **THEN** the AgendaItem is persisted via `ObjectService.saveObject()` and appears in the list

#### Scenario: Validation prevents save without required fields
- **WHEN** the user submits the form with `title`, `itemType`, or `orderNumber` missing
- **THEN** the form displays a validation error and the object is not saved

### Requirement: View agenda item detail
The app SHALL display a detail view for a single AgendaItem with all properties and its linked Meeting.

#### Scenario: User opens an agenda item detail page
- **WHEN** the user clicks a row in the agenda items list
- **THEN** the router navigates to `/agenda-items/:id` and `CnDetailPage` renders the item's properties

#### Scenario: Linked meeting shown in detail
- **WHEN** the agenda item detail page loads and the item is linked to a Meeting
- **THEN** a `CnDetailCard` section shows the Meeting title and scheduledDate

#### Scenario: CnObjectSidebar is available
- **WHEN** the user is on the agenda item detail page
- **THEN** `CnObjectSidebar` is rendered with Files, Notes, Tags, Tasks, and Audit Trail tabs

### Requirement: Edit an agenda item
The app SHALL allow users to edit an existing AgendaItem object.

#### Scenario: User edits an agenda item
- **WHEN** the user clicks the Edit button on the agenda item detail page
- **THEN** a `CnFormDialog` opens pre-populated with the current item data

#### Scenario: Changes are persisted
- **WHEN** the user modifies fields and clicks save
- **THEN** the AgendaItem is updated via `ObjectService.saveObject()` and the detail view reflects the new values

### Requirement: Delete an agenda item
The app SHALL allow users to delete an AgendaItem object with confirmation.

#### Scenario: User deletes an agenda item
- **WHEN** the user clicks Delete and confirms in `CnDeleteDialog`
- **THEN** the AgendaItem is removed via `ObjectService.deleteObject()` and the user is redirected to the agenda items list

#### Scenario: Delete is cancelled
- **WHEN** the user cancels the delete dialog
- **THEN** the AgendaItem is not deleted and the detail page remains
