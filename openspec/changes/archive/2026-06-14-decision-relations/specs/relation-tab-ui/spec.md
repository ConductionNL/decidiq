# Spec delta: Relation Tab UI — peer-relation tab pattern

This file contains delta specifications for the decision-relations change against the existing `relation-tab-ui` capability. The existing requirement covers parent-owned child CRUD tabs; this adds the complementary pattern for typed links between peer objects.

---

## ADDED Requirements

### Requirement: REQ-RTU-002 Peer-relation tabs for typed links between existing objects

The system SHALL provide a peer-relation sidebar tab pattern for typed links between existing objects of the same schema, first applied as the "Related decisions" tab on the decision detail sidebar. The tab SHALL list outgoing relations and derived incoming relations grouped by relation type, SHALL add a relation by selecting an EXISTING target object through an `NcSelect` (with `inputLabel`) searching the OpenRegister object API — never by creating a child object — combined with a relation-type selector, SHALL remove an outgoing relation with confirmation via `CnDeleteDialog`, and SHALL navigate to a related object on row activation. Derived incoming relations SHALL be presented read-only (they are removable only from their source object). Validation errors from the server (self-reference, cycle, authority) SHALL be surfaced inline in the add dialog. When the parent `objectId` is empty, `refresh()` SHALL short-circuit without fetching.

#### Scenario: Add a typed relation to an existing decision

- **GIVEN** a user with governance-body authority viewing the Related decisions tab on "Budget 2027"
- **WHEN** they click "Add relation", search and select the existing decision "Budget 2026", choose type `supersedes`, and confirm
- **THEN** the relation is saved on "Budget 2027" and appears in the tab under the `supersedes` group after refresh

#### Scenario: Incoming relation shown read-only

- **GIVEN** decision B that is superseded by decision A
- **WHEN** the user opens B's Related decisions tab
- **THEN** "superseded by A" is listed in the incoming group without a remove action, and activating the row navigates to A

#### Scenario: Server validation surfaced in the dialog

- **GIVEN** the add-relation dialog with a target selection that would create a cycle
- **WHEN** the user confirms
- **THEN** the dialog stays open and shows the server's cycle error naming the conflicting decision, and no relation is added

#### Scenario: Empty parent issues no fetch

@e2e exclude component guard — covered by vitest on the tab component
- **GIVEN** a peer-relation tab whose `objectId` prop is empty
- **WHEN** the tab initialises
- **THEN** `refresh()` returns without issuing a fetch
