# Quorum — Declarative Migration

## Purpose

Define the capability boundary for migrating Decidesk's quorum logic
from `lib/Service/QuorumService.php` to schema-declarative
`x-openregister-aggregations` + `x-openregister-calculations` on the
Meeting schema, per ADR-031. The lifecycle guard remains in PHP but
reads the derived field instead of calling a service.

This is the original (umbrella) form of the migration; the
`quorum-schema-declaration`, `quorum-guard-rewrite`, and
`quorum-service-deletion` chain supersedes it as three smaller specs.
This capability documents the end-state contract that the chain
collectively delivers.

## MODIFIED Requirements

### REQ-QDM-1: Meeting schema declares cross-schema participant aggregations

Meeting's schema in `lib/Settings/decidesk_register.json` MUST declare
two `x-openregister-aggregations` entries that count related
Participant objects filtered on
`governanceBody == @self.governanceBody`:

- `totalParticipantCount` — count of all Participants in the Meeting's
  governance body.
- `presentParticipantCount` — count of those Participants whose
  `attendanceStatus == "present"`.

#### Scenario: Aggregations present and well-formed
- **GIVEN** the imported Meeting schema
- **WHEN** inspecting `Meeting.configuration.x-openregister-aggregations`
- **THEN** keys `totalParticipantCount` and `presentParticipantCount` MUST be present
- **AND** both MUST declare `metric: "count"`, `schema: "Participant"`, and a `filter` referencing `@self.governanceBody`
- **AND** `presentParticipantCount` MUST additionally filter `attendanceStatus: "present"`

### REQ-QDM-2: Meeting schema declares quorum calculations

Meeting's schema MUST declare two `x-openregister-calculations`:

- `quorumPercentage` (`type: number`) — equal to
  `presentParticipantCount / totalParticipantCount × 100` when
  `totalParticipantCount > 0`, otherwise `0`.
- `quorumMet` (`type: boolean`) — `true` when `quorumRequired` is
  `null` OR `presentParticipantCount >= quorumRequired`; otherwise
  `false`.

Both calculations MUST be readable as derived fields on every Meeting
object without a service round-trip.

#### Scenario: Calculations exist with correct types
- **GIVEN** the imported Meeting schema
- **WHEN** inspecting `Meeting.configuration.x-openregister-calculations`
- **THEN** keys `quorumPercentage` and `quorumMet` MUST be present
- **AND** their `type` MUST be `"number"` and `"boolean"` respectively

#### Scenario: Null `quorumRequired` short-circuits to met
- **GIVEN** a Meeting with `quorumRequired == null`
- **WHEN** the engine materialises `quorumMet`
- **THEN** the field MUST be `true` regardless of present-count

#### Scenario: Threshold comparison drives quorumMet
- **GIVEN** a Meeting with `quorumRequired == 5`
- **AND** `presentParticipantCount == 5`
- **THEN** `quorumMet` MUST be `true`
- **AND** when `presentParticipantCount == 4` it MUST be `false`

### REQ-QDM-3: MeetingTransitionGuard reads the derived field

`lib/Lifecycle/MeetingTransitionGuard.php` MUST decide the `scheduled
→ opened` transition by reading `meeting.quorumMet` from the loaded
Meeting object. The guard MUST NOT call any method on
`QuorumService` and MUST NOT inject `QuorumService` via its
constructor.

#### Scenario: Guard allows when quorumMet is true
- **GIVEN** a Meeting fixture with `quorumMet: true`
- **WHEN** the guard evaluates the `open` transition precondition
- **THEN** the guard MUST permit the transition

#### Scenario: Guard blocks when quorumMet is false
- **GIVEN** a Meeting fixture with `quorumMet: false`
- **WHEN** the guard evaluates the `open` transition precondition
- **THEN** the guard MUST block the transition

#### Scenario: Guard constructor signature
- **WHEN** reflecting over the constructor of `MeetingTransitionGuard`
- **THEN** its parameter list MUST NOT include `QuorumService`

### REQ-QDM-4: QuorumService is removed

`lib/Service/QuorumService.php` MUST NOT exist in the codebase after
the migration completes. Any Container/`Application` registration of
the service MUST be removed. A repository-wide search for
`QuorumService`, `->validateQuorum`, or `->calculateQuorum` MUST
return zero matches in `lib/`, `src/`, and `tests/`.

#### Scenario: File deleted
- **WHEN** checking the filesystem
- **THEN** `lib/Service/QuorumService.php` MUST NOT exist

#### Scenario: No remaining references
- **WHEN** running `grep -rn "QuorumService\|->validateQuorum\|->calculateQuorum" lib/ src/ tests/`
- **THEN** the command MUST return zero matches

### REQ-QDM-5: External readers gain read-only quorum visibility

After the migration, any consumer of the OpenRegister API
(REST, GraphQL, MCP, manifest-driven UI) MUST be able to read
`quorumPercentage` and `quorumMet` directly from a Meeting object as
ordinary properties, without a Decidesk service call.

#### Scenario: Fields visible via standard object fetch
- **GIVEN** a materialised Meeting object retrieved through `ObjectService`
- **WHEN** serialising to JSON
- **THEN** the payload MUST include `quorumPercentage` (number) and `quorumMet` (boolean)

### REQ-QDM-6: Engine dependency gating (ADR-031 exception 1)

If the OpenRegister aggregation engine does not yet support
cross-schema `@self.{relation}` filters at the time of implementation,
this capability MUST NOT be partially shipped. Instead, the migration
MUST stop after the engine-capability spike (per `tasks.md` task 1)
and ADR-031 exception 1 MUST apply: `QuorumService` remains in place
and an OR feature request MUST be opened referencing this capability.

#### Scenario: Engine missing → migration parked
- **GIVEN** the engine-capability spike returns errors or wrong counts
- **WHEN** the implementer reaches the decision point
- **THEN** the implementer MUST file an OR feature request and stop
- **AND** `QuorumService` MUST remain unchanged
- **AND** Meeting's schema MUST carry a `TODO(adr-031)` comment naming this change
