# A consumer reads its governance body back

## Why

dossiq already holds its bezwaar committees in decidiq through `GovernanceBodyRequestedEvent`. It still reads them from its own copy, because decidiq offers no read: no query event and no service dossiq may call, and ADR-022/066 forbid dossiq reading decidiq's register directly. So a committee archived in decidiq can still receive new objections in dossiq. dossiq's change migrate-committees-to-decidiq (task 3, read path with a local fallback) waits on this seam.

## What changes

- `GovernanceBodyStateRequestedEvent`: a typed read event, keyed by `governanceBodyId` or (`sourceApp`, `externalReference`), with `handled` and found/body result slots.
- `GovernanceBodyQueryService::lookup()`: reads the body and its roster (memberships with the person's uid, role, external flag), only for the asking app's own bodies, as the acting user.
- `GovernanceBodyStateRequestedListener`, registered in `CrossAppEventRegistrar`.

The same request/response-over-the-bus shape `DecisionStateRequestedEvent` uses.

## Impact

- Spec: governance-body-events gains REQ-GBE-007.
- No schema or register change.
