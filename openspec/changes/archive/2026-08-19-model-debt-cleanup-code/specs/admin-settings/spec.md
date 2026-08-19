# admin-settings Delta — model-debt-cleanup-code

## MODIFIED Requirements

### Requirement: Member Import

The system MUST support importing members from Nextcloud Groups, Nextcloud Contacts, or CSV file. Imported members MUST be linked to Nextcloud user accounts where possible. Members are stored as `Person` + `Membership` object pairs (Popolo model), not as the deprecated flat `Participant` schema — the `GovernanceBodyMembersTab` list/create/remove flow and the `MemberAddDialog`/`MemberGroupImportDialog`/`MemberCsvImportDialog`/`MemberRoleDialog` import/role-assignment flows all read and write `membership`+`person` objects.

**Feature tier**: MVP

#### Scenario: Import members from a Nextcloud group

@e2e openspec/specs/admin-settings/spec.md#import-members-from-a-nextcloud-group

- GIVEN a Nextcloud group "bestuur" with 5 members
- WHEN the administrator imports the group into a Decidesk body
- THEN all 5 Nextcloud users MUST be added as body members
- AND their display names and email addresses MUST be populated from Nextcloud
- AND the administrator MUST be able to assign roles after import

#### Scenario: Import members from CSV

@e2e openspec/specs/admin-settings/spec.md#import-members-from-csv

- GIVEN a CSV file with columns: name, email, role
- WHEN the administrator uploads the CSV for a body
- THEN the system MUST create member entries for each row
- AND members with matching Nextcloud accounts (by email) MUST be automatically linked
- AND unmatched members MUST be flagged for manual linking or invitation

#### Scenario: Imported member is stored as Person + Membership

- **GIVEN** an administrator imports a member (from a Nextcloud group, CSV, or the "Add member" action) after this change
- **WHEN** the import completes
- **THEN** a `Person` object is created (or matched by email against an existing `Person`) holding identity fields (`name`, `email`)
- **AND** a `Membership` object is created linking that `Person` to the target `GovernanceBody` with the assigned `role`
- **AND** no new `Participant` object is created

#### Scenario: Members tab lists active memberships, not Participant rows

- **GIVEN** a `GovernanceBody` with active `Membership` objects (no `endDate`) for several `Person` records
- **WHEN** the administrator opens the Members tab (`GovernanceBodyMembersTab.vue`)
- **THEN** the list is populated by querying `membership` filtered on `governanceBody` and `endDate: null`, joined to each `Membership`'s `Person` for display name
- **AND** "Remove from body" sets the `Membership`'s `endDate` to the current date (Popolo departure semantics, matching the existing `member-onboarding` offboarding pattern) rather than nulling a `governanceBody` field on a `Participant` row
