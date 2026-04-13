## ADDED Requirements

### REQ-MOT-001: Motion list view
The app SHALL display all Motion objects in a paginated, searchable, sortable list using `CnIndexPage` with `useListView`.

#### Scenario: User views motion list
- **GIVEN** the user navigates to `/motions`
- **WHEN** the page loads
- **THEN** a list of Motion objects is displayed with columns for title, motionType, proposer, lifecycle, and submittedAt

#### Scenario: User searches motions
- **GIVEN** the user is on the motions list page
- **WHEN** the user types in the `CnActionsBar` search input
- **THEN** the list is filtered via OpenRegister full-text search and updates within the same page

#### Scenario: User filters motions by lifecycle
- **GIVEN** the user is on the motions list page
- **WHEN** the user selects a lifecycle value from the filter bar
- **THEN** only motions with that lifecycle value are shown

### REQ-MOT-002: Create a motion
The app SHALL allow users to create a new Motion object via a schema-driven form dialog.

#### Scenario: User opens create dialog
- **GIVEN** the user is on the motions list page
- **WHEN** the user clicks the add button in `CnActionsBar`
- **THEN** a `CnFormDialog` opens with fields for title, text, motionType, proposer, coSigners, and submittedAt

#### Scenario: Motion is saved successfully
- **GIVEN** the user has filled in all required fields (title, text, motionType, proposer, submittedAt)
- **WHEN** the user clicks save
- **THEN** the Motion object is created with `lifecycle: submitted` and persisted via `ObjectService.saveObject()`

#### Scenario: Validation prevents save without required fields
- **GIVEN** the create dialog is open
- **WHEN** the user submits the form with `title` or `text` empty
- **THEN** the form displays a validation error and the object is not saved

### REQ-MOT-003: View motion detail
The app SHALL display a detail view for a single Motion object with lifecycle timeline, related amendments, and voting rounds.

#### Scenario: User opens a motion detail page
- **GIVEN** the user clicks a row in the motions list
- **WHEN** the router navigates to `/motions/:id`
- **THEN** `CnDetailPage` renders the motion's properties including title, text, motionType, proposer, coSigners, and lifecycle

#### Scenario: Lifecycle timeline is shown
- **GIVEN** the user is on the motion detail page
- **WHEN** the page loads
- **THEN** `CnTimelineStages` shows the lifecycle stages (submitted, debating, voting, adopted/rejected/withdrawn) with the current stage highlighted

#### Scenario: Related amendments shown in detail
- **GIVEN** the user is on the motion detail page
- **WHEN** the page loads
- **THEN** a `CnDetailCard` section lists all linked Amendment objects with their title, proposer, and lifecycle

#### Scenario: Related voting rounds shown in detail
- **GIVEN** the user is on the motion detail page
- **WHEN** the page loads
- **THEN** a `CnDetailCard` section lists all linked VotingRound objects with their votingMethod, result, and tally

#### Scenario: CnObjectSidebar is available
- **GIVEN** the user is on the motion detail page
- **WHEN** the sidebar is opened
- **THEN** `CnObjectSidebar` renders with Files, Notes, Tags, Tasks, and Audit Trail tabs

### REQ-MOT-004: Edit a motion
The app SHALL allow users to edit a Motion object that is in `submitted` or `debating` lifecycle state.

#### Scenario: User edits a motion
- **GIVEN** the Motion is in `submitted` or `debating` lifecycle
- **WHEN** the user clicks the Edit button on the motion detail page
- **THEN** a `CnFormDialog` opens pre-populated with the current motion data

#### Scenario: Adopted or rejected motion cannot be edited
- **GIVEN** the Motion has lifecycle `adopted`, `rejected`, or `withdrawn`
- **WHEN** the user opens the motion detail page
- **THEN** the Edit button is disabled and a tooltip explains the motion is finalised

#### Scenario: Changes are persisted
- **GIVEN** the edit dialog is open with an editable motion
- **WHEN** the user modifies fields and clicks save
- **THEN** the Motion object is updated via `ObjectService.saveObject()` and the detail view reflects the new values

### REQ-MOT-005: Motion lifecycle transitions
The app SHALL allow authorised users to advance a Motion through its lifecycle states.

#### Scenario: Move motion from submitted to debating
- **GIVEN** a Motion is in lifecycle `submitted`
- **WHEN** the chair user clicks "Start Debate" on the motion detail page
- **THEN** the Motion lifecycle is updated to `debating` and a Nextcloud notification is dispatched to the proposer

#### Scenario: Move motion to voting
- **GIVEN** a Motion is in lifecycle `debating`
- **WHEN** the chair user clicks "Open Voting" on the motion detail page
- **THEN** the lifecycle is updated to `voting` and a new VotingRound object is created linked to this Motion

#### Scenario: Withdraw a motion
- **GIVEN** a Motion is in lifecycle `submitted` or `debating`
- **WHEN** the proposer user clicks "Withdraw"
- **THEN** the Motion lifecycle is updated to `withdrawn` and no further transitions are possible

### REQ-MOT-006: Digital co-signatory collection
The app SHALL allow the proposer to request co-signatures from other council members digitally.

#### Scenario: Proposer requests co-signature
- **GIVEN** the Motion is in `submitted` lifecycle
- **WHEN** the proposer clicks "Request Co-Signature" and selects a Participant
- **THEN** a Nextcloud notification is sent to the selected Participant with a link to review and sign the motion

#### Scenario: Participant co-signs a motion
- **GIVEN** the Participant receives a co-signature request notification
- **WHEN** the Participant navigates to the motion and clicks "Co-Sign"
- **THEN** the Participant's displayName is added to `Motion.coSigners` and the motion is updated

### REQ-MOT-007: Delete a motion
The app SHALL allow users to delete a Motion object that is in `submitted` lifecycle only.

#### Scenario: User deletes a submitted motion
- **GIVEN** the Motion has lifecycle `submitted`
- **WHEN** the user clicks the Delete button and confirms in `CnDeleteDialog`
- **THEN** the Motion object is removed via `ObjectService.deleteObject()` and the user is redirected to the motions list

#### Scenario: Non-submitted motion cannot be deleted
- **GIVEN** the Motion is in any lifecycle state other than `submitted`
- **WHEN** the user opens the motion detail page
- **THEN** the Delete button is absent from the header actions
