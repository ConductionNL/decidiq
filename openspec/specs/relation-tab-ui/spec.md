# relation-tab-ui Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-25-relation-tab-ui. Update Purpose after archive.
## Requirements
### Requirement: REQ-RTU-001 Relation-scoped CRUD list tabs

The system SHALL present full-CRUD sidebar tabs for child objects that a parent owns directly: motions under an agenda item, agenda items under a meeting, action items under a decision, and amendments under a motion. Each tab SHALL fetch the child collection filtered by the parent relation key, render it in a `CnDataTable`, and expose add / edit / delete through `CnFormDialog` and `CnDeleteDialog`. On save the tab SHALL stamp the parent relation key onto the object and refresh; on delete it SHALL remove the object and refresh. The edit form SHALL hide identity and audit fields (`id`, `uuid`, parent relation key, `created`, `updated`). When the parent `objectId` is empty, `refresh()` SHALL short-circuit without fetching.

#### Scenario: Add a motion from the agenda-item motions tab
- **GIVEN** a user viewing the Motions sidebar tab on an agenda item
- **WHEN** they click "Add motion", fill the form, and confirm
- **THEN** the system SHALL save a motion object carrying `agendaItem` = the parent id
- **AND** the new motion SHALL appear in the tab's table after a refresh

#### Scenario: Delete a child object refreshes the list
- **GIVEN** a tab listing child objects for a parent
- **WHEN** the user confirms deletion of a row
- **THEN** the system SHALL delete that object and re-fetch the relation-scoped collection

#### Scenario: Empty parent issues no fetch
- **GIVEN** a tab whose `objectId` prop is empty
- **WHEN** the tab initialises
- **THEN** `refresh()` SHALL return without issuing a fetch

---

### Requirement: REQ-RTU-002 Status and lifecycle colour semantics

The system SHALL map domain lifecycle and status values to badge colours so a reader can tell at a glance what a row means: motion/amendment lifecycle (`submitted` → primary, `debating`/`voting` → warning, `adopted` → success, `rejected` → error, `withdrawn` → default), action-item status (`open` → primary, `in-progress` → warning, `completed` → success, `overdue` → error), vote value (`for` → success, `against` → error, `abstain` → default), and round result (`adopted` → success, `rejected` → error, `tied` → warning). Each tab SHALL expose its per-row action menu (edit / delete / remove) through `CnRowActions`.

#### Scenario: Adopted motion renders a success badge
- **GIVEN** a motion row with lifecycle `adopted`
- **WHEN** the table renders the status column
- **THEN** the badge SHALL use the success colour

#### Scenario: Against vote renders an error badge
- **GIVEN** a vote row with value `against`
- **WHEN** the votes table renders the vote column
- **THEN** the badge SHALL use the error colour

---

### Requirement: REQ-RTU-003 Participant linking tabs

The system SHALL present add-existing / remove sidebar tabs that link existing participants to a parent (members of a governance body, participants of a meeting). Opening the add dialog SHALL load candidate participants, excluding those already linked, and label each candidate by display name. Selecting a candidate SHALL create the parent → participant relation; removing a row SHALL detach it. For meeting participants, membership SHALL be determined by whether the participant's `meetings` array already references the meeting.

#### Scenario: Add-dialog excludes already-linked participants
- **GIVEN** a governance body that already has members
- **WHEN** the user opens the "Add member" dialog
- **THEN** the candidate list SHALL omit participants already linked to that body

#### Scenario: Linking a participant attaches the relation
- **GIVEN** the add dialog open with candidates listed
- **WHEN** the user picks a candidate
- **THEN** the system SHALL persist the parent → participant relation and refresh the tab

---

### Requirement: REQ-RTU-004 Read-only relation viewers — votes and parent motion

The system SHALL present read-only relation tabs that surface derived context without editing. The motion votes tab SHALL gather every vote across all voting rounds for the motion, resolve each vote's `caster` foreign key to a participant display name (falling back to the raw value or an em dash when unresolvable), and render the breakdown. The amendment parent-motion tab SHALL resolve the amendment's `parentMotion` reference, present the parent motion's key properties (title, proposer, type, status, submitted date) as a read-only list, and offer navigation to the parent motion's detail page.

#### Scenario: Vote caster resolves to a display name
- **GIVEN** a vote whose `caster` is a participant id present in the register
- **WHEN** the votes tab renders the voter column
- **THEN** it SHALL show that participant's display name, not the raw id

#### Scenario: Open the parent motion
- **GIVEN** an amendment with a resolvable `parentMotion`
- **WHEN** the user activates "Open parent motion"
- **THEN** the system SHALL route to the parent motion's detail page

---

### Requirement: REQ-RTU-005 Minutes signer management with sign-now

The system SHALL present a signers tab on a minutes record that lists the `signers[]` entries, hydrating each participant reference to a display name and role and flagging whether the entry has been signed (`signedAt`). It SHALL support adding an existing participant as a pending signer and removing a signer. When the current user matches a pending (unsigned) signer entry — by participant id or owner — the tab SHALL offer a "Sign now" action that calls the minutes lifecycle transition endpoint to set the record to `signed`.

#### Scenario: Sign-now offered only to a pending signer who is the current user
- **GIVEN** a minutes record whose signers include the current user with no `signedAt`
- **WHEN** the signers tab renders
- **THEN** the "Sign now" action SHALL be available

#### Scenario: Signed entry shows a Signed badge
- **GIVEN** a signer entry with a `signedAt` timestamp
- **WHEN** the table renders the status column
- **THEN** it SHALL show a "Signed" success badge rather than "Pending"

### Requirement: REQ-RTU-002 Peer-relation tabs for typed links between existing objects

The system SHALL provide a peer-relation sidebar tab pattern for typed links between existing objects of the same schema, first applied as the "Related decisions" tab on the decision detail sidebar. The tab SHALL list outgoing relations and derived incoming relations grouped by relation type, SHALL add a relation by selecting an EXISTING target object through an `NcSelect` (with `inputLabel`) searching the OpenRegister object API — never by creating a child object — combined with a relation-type selector, SHALL remove an outgoing relation with confirmation via `CnDeleteDialog` (the dialog living in its own file under `src/modals/` per the modal-isolation rule), and SHALL navigate to a related object on row activation. Derived incoming relations SHALL be presented read-only (they are removable only from their source object). Validation errors from the server (self-reference, cycle, authority) SHALL be surfaced inline in the add dialog. When the parent `objectId` is empty, `refresh()` SHALL short-circuit without fetching.

#### Scenario: Add a typed relation to an existing decision

- **GIVEN** a user with governance-body authority viewing the Related decisions tab on "Programmabegroting 2027"
- **WHEN** they click "Add relation", search and select the existing decision "Programmabegroting 2026", choose type `supersedes`, and confirm
- **THEN** the relation is saved on "Programmabegroting 2027" and appears in the tab under the `supersedes` group after refresh

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

