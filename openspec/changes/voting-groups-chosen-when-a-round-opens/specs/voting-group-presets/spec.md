## MODIFIED Requirements

### Requirement: REQ-VGP-001 Admin can define named voting group presets in settings
The app SHALL let the secretariat create, edit and deactivate named voting groups on the admin page Stemgroepen. Each group SHALL be a `VotingGroup` object (slug `voting-group`) with a name, the governance body it belongs to and the persons in it, stored in decidiq's register rather than in app configuration.

#### Scenario: Admin creates a preset "Voltallige raad"
- **GIVEN** the admin page Stemgroepen for the body Gemeenteraad Zuiddrecht
- **WHEN** the secretary adds the group "Voltallige raad" with all 32 council members selected
- **THEN** a `voting-group` object is saved with that name, the body and the 32 persons
- **AND** it appears in the list straight away

#### Scenario: Admin edits an existing preset to remove a departed member
- **GIVEN** the group "Commissie AZ" with 11 members, one of whom has left the body
- **WHEN** the secretary removes the departed member and saves
- **THEN** the group's `members` no longer hold that person

### Requirement: REQ-VGP-002 Chair can select a voting group preset when opening a VotingRound
When opening a voting round, the panel SHALL let the chair choose who may vote: all members of the body (the default) or one of the body's active voting groups. When a group is chosen, the server SHALL resolve its persons to the meeting's participants and only those participants SHALL be eligible to vote.

#### Scenario: Chair selects the "Commissie AZ" preset when opening a round
- **GIVEN** the open-round panel with the groups "Voltallige raad" (32) and "Commissie AZ" (11)
- **WHEN** the chair chooses "Commissie AZ" and opens the round
- **THEN** the round's eligible participants are the 11 members of that group present as participants of the meeting
- **AND** a vote cast by a participant outside the group is refused with 403

#### Scenario: No preset is required — chair leaves selection blank
- **GIVEN** the open-round panel
- **WHEN** the chair keeps "all members of the body" and opens the round
- **THEN** every active member of the body is eligible, as before

## ADDED Requirements

### Requirement: REQ-VGP-005 The chair sees who a voting group left out
When a round opens with a voting group, the panel SHALL tell the chair how many members of the group were left out because they have no active membership, and who they are.

#### Scenario: One member of the group has left the body
- **GIVEN** the group "Commissie AZ" holds a person whose membership ended last month
- **WHEN** the chair opens a round with that group
- **THEN** the panel says one member of the group is no longer active and was left out, and names her
