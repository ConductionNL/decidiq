## ADDED Requirements

### Requirement: Meeting list view
The app SHALL display all Meeting objects in a paginated, searchable, sortable list using `CnIndexPage` with `useListView`.

#### Scenario: User views meeting list
- **WHEN** the user navigates to `/meetings`
- **THEN** a list of Meeting objects is displayed with columns for title, meetingType, scheduledDate, meetingMode, and lifecycle

#### Scenario: User searches meetings
- **WHEN** the user types in the `CnActionsBar` search input
- **THEN** the list is filtered via OpenRegister full-text search and updates within the same page

#### Scenario: User sorts meetings by scheduled date
- **WHEN** the user clicks the scheduledDate column header
- **THEN** the list is re-sorted in ascending or descending order by scheduledDate

#### Scenario: Meeting list is paginated
- **WHEN** there are more meetings than the current page size
- **THEN** `CnPagination` is shown and the user can navigate between pages

### Requirement: Create a meeting
The app SHALL allow users to create a new Meeting object via a schema-driven form dialog.

#### Scenario: User opens create dialog
- **WHEN** the user clicks the add button in `CnActionsBar` on the meetings list
- **THEN** a `CnFormDialog` opens with fields for all required Meeting properties (title, meetingType, scheduledDate, meetingMode, lifecycle)

#### Scenario: Meeting is saved successfully
- **WHEN** the user fills in all required fields and clicks save
- **THEN** the new Meeting object is persisted via `ObjectService.saveObject()` and appears in the list

#### Scenario: Validation prevents save without required fields
- **WHEN** the user submits the form with `title` or `scheduledDate` empty
- **THEN** the form displays a validation error and the object is not saved

### Requirement: View meeting detail
The app SHALL display a detail view for a single Meeting object including its properties and related AgendaItems.

#### Scenario: User opens a meeting detail page
- **WHEN** the user clicks a row in the meetings list
- **THEN** the router navigates to `/meetings/:id` and `CnDetailPage` renders the meeting's properties

#### Scenario: Related AgendaItems shown in detail
- **WHEN** the meeting detail page loads
- **THEN** a `CnDetailCard` section shows the linked AgendaItems ordered by `orderNumber`

#### Scenario: CnObjectSidebar is available
- **WHEN** the user is on the meeting detail page
- **THEN** `CnObjectSidebar` is rendered with Files, Notes, Tags, Tasks, and Audit Trail tabs

### Requirement: Edit a meeting
The app SHALL allow users to edit an existing Meeting object.

#### Scenario: User edits a meeting
- **WHEN** the user clicks the Edit button on the meeting detail page
- **THEN** a `CnFormDialog` (or inline form) opens pre-populated with the current meeting data

#### Scenario: Changes are persisted
- **WHEN** the user modifies fields and clicks save
- **THEN** the Meeting object is updated via `ObjectService.saveObject()` and the detail view reflects the new values

### Requirement: Delete a meeting
The app SHALL allow users to delete a Meeting object with a confirmation dialog.

#### Scenario: User deletes a meeting
- **WHEN** the user clicks the Delete button and confirms in `CnDeleteDialog`
- **THEN** the Meeting object is removed via `ObjectService.deleteObject()` and the user is redirected to the meetings list

#### Scenario: Delete is cancelled
- **WHEN** the user clicks the Delete button but cancels in the dialog
- **THEN** the Meeting object is not deleted and the user remains on the detail page
