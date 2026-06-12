# voting-group-presets Specification

## Purpose
TBD - created by archiving change 2026-05-11-p2-motion-and-voting-core-t2. Update Purpose after archive.

## Requirements

### Requirement: REQ-VGP-001 Admin can define named voting group presets in settings
The app SHALL allow admin users to create, edit, and delete named voting group presets in the admin settings page. Each preset has a name and a list of Participant UUIDs. Presets are stored per GovernanceBody under `IAppConfig`.

#### Scenario: Admin creates a preset "Voltallige raad"
- **GIVEN** the admin settings page "Stemgroepen" section for GovernanceBody "Gemeenteraad Haarlemmermeer"
- **WHEN** the admin clicks "Nieuwe stemgroep" and enters name "Voltallige raad" with all 32 raadsleden selected
- **THEN** the preset is stored in `IAppConfig` under `voting_group_presets_{bodyId}` as a JSON array; it appears in the preset list immediately

#### Scenario: Admin edits an existing preset to remove a departed member
- **GIVEN** a preset "Commissie AZ" with 11 members, one of whom has left the governance body
- **WHEN** the admin opens the preset editor, deselects the departed member, and saves
- **THEN** the preset is updated in `IAppConfig` and the departed member's UUID is no longer in the stored array

---

### Requirement: REQ-VGP-002 Chair can select a voting group preset when opening a VotingRound
The app SHALL display a "Stemgroep" dropdown in the "Stemronde openen" dialog, populated with the defined presets for the current GovernanceBody. When a preset is selected, only Participants in the preset are eligible to vote in the round.

#### Scenario: Chair selects the "Commissie AZ" preset when opening a round
- **GIVEN** the "Stemronde openen" dialog with presets "Voltallige raad" (32) and "Commissie AZ" (11) available
- **WHEN** the chair selects "Commissie AZ" and opens the round
- **THEN** `VotingService::openVotingRound()` stores the 11 Participant UUIDs as the eligible voter list; vote casting by Participants NOT in the preset is rejected with `403 Forbidden`

#### Scenario: No preset is required — chair leaves selection blank
- **GIVEN** the "Stemronde openen" dialog
- **WHEN** the chair does not select a preset and opens the round
- **THEN** all active Members of the GovernanceBody are eligible to vote (default behaviour from p2-motion-and-voting)

---

### Requirement: REQ-VGP-003 Stale preset member UUIDs are detected and excluded at round open
The app SHALL validate each UUID in a selected preset against active Memberships in `VotingService::openVotingRound()`. UUIDs with no active Membership are excluded from the eligible voter list and a warning is shown in the UI.

#### Scenario: Preset contains a UUID for a member who has left
- **GIVEN** a preset "Commissie AZ" containing the UUID of a Participant whose Membership `endDate` has passed
- **WHEN** the chair opens a VotingRound using this preset
- **THEN** the expired UUID is excluded; the eligible voter count is reduced by 1; a warning banner shows "1 stemgroeplid niet meer actief — uitgesloten van stemronde"

---

### Requirement: REQ-VGP-004 Voting group presets are visible in admin settings with member count
The app SHALL display the list of presets in the admin settings "Stemgroepen" section with: preset name, member count, and last-modified timestamp.

#### Scenario: Admin sees preset overview
- **GIVEN** three defined presets: "Voltallige raad (32)", "Commissie AZ (11)", "Commissie Wonen (9)"
- **WHEN** the admin opens the "Stemgroepen" section
- **THEN** all three presets are listed with name, member count, and a last-modified date; edit and delete buttons are visible per preset
