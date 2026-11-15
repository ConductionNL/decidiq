# authorization-via-or-rbac

## ADDED Requirements

### Requirement: A body scope group exists only while it has members (REQ-RBAC-009)

The projector SHALL NOT keep a `decidesk:body:{bodyId}:chair` or `decidesk:body:{bodyId}:signatory` group whose scope nobody holds: reconcile SHALL delete such a group and SHALL create one only when it gets a member. Deleting a governance body SHALL delete both of its scope groups. A missing scope group SHALL deny exactly as an empty one did.

#### Scenario: Removing an example set leaves no body groups
- **GIVEN** an example set whose two governance bodies have a chair and a secretary each, so four scope groups exist
- **WHEN** `occ openregister:example-set:remove` deletes the set's bodies, participants and memberships
- **THEN** none of the four `decidesk:body:*` groups SHALL remain
- @e2e exclude {group lifecycle on object deletion; covered by GovernanceRoleScopeProjectorTest and GovernanceRoleProjectionListenerTest}

#### Scenario: A body without a chair has no chair group
- **GIVEN** a body whose only signatory role is a secretary
- **WHEN** its scopes are reconciled
- **THEN** its signatory group SHALL hold the secretary and its chair group SHALL NOT exist
- @e2e exclude {covered by GovernanceRoleScopeProjectorTest}

#### Scenario: Deleting one body leaves another body's groups
- **GIVEN** scope groups for two bodies
- **WHEN** one body is deleted
- **THEN** only that body's groups SHALL be deleted
- @e2e exclude {covered by GovernanceRoleScopeProjectorTest}
