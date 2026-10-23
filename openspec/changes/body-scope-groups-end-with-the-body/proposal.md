# Proposal: body-scope-groups-end-with-the-body

## Summary

`occ openregister:example-set:install zuiddrecht` followed by `example-set:remove zuiddrecht` left four groups behind on the rehearsal instance: `decidesk:body:{id}:chair` and `decidesk:body:{id}:signatory` for the set's two governance bodies. The projector (REQ-RBAC-001) only ever creates and fills those groups; nothing removes them, so they outlive their body on every instance, not only after an example set.

The roster listener also never ran on a real write: it read the schema slug off the entity, where OpenRegister stamps the schema's numeric id, so its guard rejected every participant and membership and only the repair-step backfill projected scopes.

## What changes

- A scope nobody holds has no group. Reconcile deletes a scope group whose desired membership is empty and creates one only when it gets a member. An empty scope and a missing group both deny, so access is unchanged.
- Deleting a governance body deletes its two scope groups (`GovernanceRoleScopeProjector::removeBody()`, driven by an `ObjectDeletedEvent` subscription on `governance-body`).
- `GovernanceRoleProjectionListener` resolves the schema slug through `ListenerSchemaResolver`, as the other object listeners do.

This is the generic fix: the groups end with what they describe, whoever deletes it. The example-set remover is not taught about decidiq's groups.
