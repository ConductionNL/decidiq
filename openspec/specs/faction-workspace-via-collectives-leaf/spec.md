---
status: done
---

# faction-workspace-via-collectives-leaf Specification

## Purpose
Provides a faction or committee workspace as a Nextcloud Collective bound to the governance-body or faction OpenRegister object, surfaced as a workspace tab and widget instead of an app-local workspace store. Workspace membership maps to the collective's member list for space access while authorization over governance objects stays in OpenRegister RBAC, and the tab degrades gracefully when the Collectives app is absent. Legacy workspace objects are migrated to collectives by an idempotent migration that seeds membership, binds the collective to the governance object, and archives the originals rather than deleting them.
## Requirements
### Requirement: REQ-WS-COLL-001 Faction workspace is a Collective bound to the governance object
The system SHALL provide a faction/committee workspace as a Nextcloud Collective bound to the governance-body or faction OpenRegister object via the ADR-019 integration registry, surfaced as a registry tab + widget. The system SHALL NOT store the workspace in an app-local `CollaborationWorkspace` schema.

#### Scenario: Collectives leaf surfaced as the workspace tab
- **GIVEN** an authenticated faction member viewing a governance body detail page
- **AND** the Nextcloud Collectives app is installed and the collectives leaf is registered
- **WHEN** they open the workspace tab
- **THEN** the registry-driven collective bound to the governance object is rendered through `MeetingIntegrations.vue`
- **THEN** collaborative pages are created in the collective, not in an app-local workspace object

#### Scenario: Collectives app not installed degrades gracefully
- **GIVEN** the Nextcloud Collectives app is not installed
- **WHEN** a member opens a governance body detail page
- **THEN** the workspace tab is hidden and the rest of the page renders normally
- **THEN** no error is raised

### Requirement: REQ-WS-COLL-002 Membership maps to the collective; object RBAC stays in OpenRegister
The system SHALL map workspace membership to the bound collective's member list for space access, and SHALL continue to enforce authorization over governance objects through OpenRegister's `AuthorizationService`. The system SHALL NOT maintain a bespoke in-app workspace member list as an authorization layer over governance objects.

#### Scenario: Member added to the workspace
- **WHEN** a faction lead adds a member to the workspace
- **THEN** the member is added to the bound collective's membership for space access
- **THEN** the member's rights over governance objects remain governed by OpenRegister RBAC, unchanged

### Requirement: REQ-WS-COLL-003 Migrate legacy workspaces, archived not deleted
The system SHALL provide an idempotent migration that, for each existing `CollaborationWorkspace` object, creates a collective, seeds its membership from the workspace member list, binds it to the governance object via the registry, and archives the legacy `CollaborationWorkspace` object via OpenRegister's archival workflow without hard-deleting it.

#### Scenario: Workspace migrated to a collective
- **GIVEN** a `CollaborationWorkspace` with three members
- **WHEN** the migration runs
- **THEN** a collective exists bound to the governance object with the three members seeded
- **THEN** the legacy `CollaborationWorkspace` object is set to an archived state and remains queryable for audit

#### Scenario: Migration is idempotent
- **GIVEN** the migration has already run for a workspace
- **WHEN** it runs again
- **THEN** no duplicate collective is created and the already-archived workspace is skipped

