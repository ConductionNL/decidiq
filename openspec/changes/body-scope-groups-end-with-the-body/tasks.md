# Tasks: body-scope-groups-end-with-the-body

- [x] 1 `GovernanceRoleScopeProjector::syncScope()` deletes an empty scope's group and creates a group only for a member
- [x] 2 `GovernanceRoleScopeProjector::removeBody()`
- [x] 3 `GovernanceRoleProjectionListener` handles a deleted `governance-body` and resolves slugs through `ListenerSchemaResolver`
- [x] 4 `ObjectListenerRegistrar` subscribes the listener to `ObjectDeletedEvent` on `governance-body`
- [x] 5 Tests: `GovernanceRoleScopeProjectorTest` (no group for an unheld scope, last holder leaving, removing one body leaves another's), `GovernanceRoleProjectionListenerTest` (delete removes, update does not, a participant with a numeric schema id reconciles)
