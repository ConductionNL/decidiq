---
status: in-progress
status-note: In progress 2026-08-19 via organisation-facet-composition (GovernanceBodyDetail becomes the ADR-004 v2 Organisation hub — retirement schedule, term rules, integrity declarations, shared-body participation, and factions composed onto the detail page as declarative object-list widgets).
openspec-changes:
  - organisation-facet-composition
---

# governance-body-crud Specification

## Purpose
Provides full create, read, update, and delete management of GovernanceBody objects. Bodies are shown in a paginated, searchable, filterable list, created and edited through schema-driven form dialogs with required-field validation, and deleted with confirmation. The detail page renders a body's properties alongside its linked Meetings and Participants and an object sidebar with Files, Notes, Tags, Tasks, and Audit Trail tabs.

## Requirements

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
The app SHALL display a detail view for a GovernanceBody including its properties,
linked Meetings, linked Participants, and — per ADR-004 v2's Organisation cluster —
the body's retirement schedule, term rule, integrity declarations (other positions
and gifts), shared-body participation, and factions, each rendered as a filtered
`object-list` widget scoped to the body's own object id against that register's
existing scoping field. This detail page is the "Organisation" hub: a griffier no
longer has to leave it to find a body's retirement schedule, term rule, or
integrity register.

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

#### Scenario: Retirement schedule shown on the body detail page
- **GIVEN** a GovernanceBody with a generated `rooster-van-aftreden` (`body` = the body's object id)
- **WHEN** the user views the GovernanceBody detail page
- **THEN** a "Retirement schedule" widget lists the body's `rooster-van-aftreden` object(s)
- **AND** clicking a row navigates to `RoosterDetail`, which shows the ordered term entries

#### Scenario: No retirement schedule yet
- **GIVEN** a GovernanceBody with no `rooster-van-aftreden` object
- **WHEN** the user views the GovernanceBody detail page
- **THEN** the "Retirement schedule" widget shows its empty state, not an error

#### Scenario: Term rule shown read-only on the body detail page
- **GIVEN** a GovernanceBody with one or more `termijn-regeling` objects (`body` = the body's object id)
- **WHEN** the user views the GovernanceBody detail page
- **THEN** a "Term rules" widget lists the body's `termijn-regeling` object(s) with no inline create or edit action
- **AND** clicking a row navigates to `TermijnRegelingDetail`, where the rule is editable

#### Scenario: Integrity declarations shown on the body detail page
- **GIVEN** a GovernanceBody with `nevenfunctie` and `geschenk` objects scoped to it (`governanceBody` = the body's object id)
- **WHEN** the user views the GovernanceBody detail page
- **THEN** an "Other positions" widget lists the body's `nevenfunctie` objects and a "Gifts" widget lists the body's `geschenk` objects
- **AND** clicking a row in either navigates to that object's own detail page (`NevenfunctieDetail` / `GeschenkDetail`)

#### Scenario: Shared-body participation shown on the body detail page
- **GIVEN** a GovernanceBody with `bodyType=shared-body` and `body-participation` objects referencing it (`sharedBody` = the body's object id)
- **WHEN** the user views the GovernanceBody detail page
- **THEN** a "Participating organisations" widget lists the participating `body-participation` objects
- **AND** a "Zienswijze rounds" widget lists the body's `zienswijzeronde` objects (`sharedBody` = the body's object id)

#### Scenario: A body's own participations in shared bodies are shown
- **GIVEN** a GovernanceBody that itself participates in one or more shared bodies (`body-participation.participant` = the body's object id)
- **WHEN** the user views the GovernanceBody detail page
- **THEN** a "Shared-body participations" widget lists those `body-participation` objects

#### Scenario: Factions shown on a body's detail page
- **GIVEN** one or more `GovernanceBody` objects with `bodyType=faction` and `parentBody` set to this body's object id
- **WHEN** the user views this GovernanceBody's detail page
- **THEN** a "Factions" widget lists those child `GovernanceBody` objects
- **AND** clicking a row navigates to that faction's own `GovernanceBodyDetail` page

#### Scenario: No facet widget errors when its register is empty for this body
- **GIVEN** a GovernanceBody with no `nevenfunctie`, `geschenk`, `body-participation`, `zienswijzeronde`, or faction-`bodyType` child objects referencing it
- **WHEN** the user views the GovernanceBody detail page
- **THEN** every added facet widget renders its own empty state and the page does not error

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
