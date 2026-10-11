# governance-body-events delta

## ADDED Requirements

### Requirement: REQ-GBE-007 A consumer reads its governance body back through a typed event

Decidiq SHALL answer a `GovernanceBodyStateRequestedEvent` in process through
`GovernanceBodyStateRequestedListener`, so a consumer app reads the committee it
raised (its `active` flag, quorum, jurisdiction and roster) without reading
decidiq's register (ADR-022/066). The consumer names the body by the
`governanceBodyId` decidiq returned, or by (`sourceApp`, `externalReference`).
Only a body whose `sourceApp` is the asking app SHALL be returned. The read
runs as the acting user, so OpenRegister RBAC decides what comes back. The
answer has two separate slots: `isHandled()` false means decidiq could not
answer (absent, or the read failed) and the consumer uses its own copy;
handled without `isFound()` means decidiq holds no such body for that consumer.
The listener SHALL NOT throw.

#### Scenario: A consumer reads its committee with the roster

- **GIVEN** dossiq raised committee `cmte-1` with `active = true`, quorum 3,
  a chair and an external secretary
- **WHEN** dossiq dispatches `GovernanceBodyStateRequestedEvent('dossiq', 'cmte-1')`
- **THEN** the event is handled and found, and the body carries `active`,
  `quorum`, `jurisdiction` and two members with their `uid`, `role` and
  `external` flag
@e2e exclude In-process event seam with no UI surface; asserted by GovernanceBodyQueryServiceTest against rows written by the real command service.

#### Scenario: An archived committee reads as inactive

- **GIVEN** a body raised with `active = false`
- **WHEN** the consumer reads it by its `governanceBodyId`
- **THEN** the body carries `active = false`
@e2e exclude In-process event seam; asserted by GovernanceBodyQueryServiceTest.

#### Scenario: Another app's body is not returned

- **GIVEN** a body raised by `pipelinq`
- **WHEN** dossiq reads it by its id or by the same reference
- **THEN** the event is handled and not found
@e2e exclude In-process event seam; asserted by GovernanceBodyQueryServiceTest.

#### Scenario: A failed read leaves the event unhandled

- **GIVEN** OpenRegister throws during the read
- **WHEN** the event is dispatched
- **THEN** the event is not handled, nothing is thrown, and an error is logged
@e2e exclude Failure path of an in-process seam; asserted by GovernanceBodyStateRequestedListenerTest.
