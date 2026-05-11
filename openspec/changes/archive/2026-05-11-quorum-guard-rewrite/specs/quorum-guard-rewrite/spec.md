# Quorum — Guard Rewrite (chain spec 2 of 3)

## Purpose

Define the capability boundary for rewiring `MeetingTransitionGuard`
to consume the declarative `meeting.quorumMet` field landed by chain
spec 1 (`quorum-schema-declaration`), removing the guard's dependency
on `QuorumService`. The `QuorumService` class itself remains in the
codebase after this spec; chain spec 3 (`quorum-service-deletion`)
deletes it.

## MODIFIED Requirements

### REQ-QGR-1: MeetingTransitionGuard drops QuorumService dependency

`lib/Lifecycle/MeetingTransitionGuard.php` MUST NOT declare
`QuorumService` as a constructor parameter, property, or imported
symbol after this change. The `use OCA\Decidesk\Service\QuorumService;`
import line MUST be removed.

#### Scenario: Constructor signature has no QuorumService
- **WHEN** reflecting over `MeetingTransitionGuard::__construct`
- **THEN** no parameter MUST be typed as `QuorumService`

#### Scenario: No QuorumService import remains in the guard
- **WHEN** scanning the guard file for `use OCA\Decidesk\Service\QuorumService;`
- **THEN** zero matches MUST be found

### REQ-QGR-2: `open` transition reads `quorumMet` from the Meeting object

The guard's `open`-transition precondition MUST be expressed as a
direct read of the loaded Meeting object's `quorumMet` field
(`($meeting['quorumMet'] ?? false) === true`), reusing the existing
`$meeting` variable rather than fetching the object a second time.

#### Scenario: Guard allows when quorumMet is true
- **GIVEN** a Meeting fixture with `quorumMet: true`
- **WHEN** the guard evaluates the `open` transition
- **THEN** the guard MUST allow the transition

#### Scenario: Guard blocks when quorumMet is false
- **GIVEN** a Meeting fixture with `quorumMet: false`
- **WHEN** the guard evaluates the `open` transition
- **THEN** the guard MUST block the transition

#### Scenario: Guard allows when quorumRequired is null
- **GIVEN** a Meeting fixture with `quorumRequired: null` (and `quorumMet: true` per the spec-1 calculation)
- **WHEN** the guard evaluates the `open` transition
- **THEN** the guard MUST allow the transition

### REQ-QGR-3: DI wiring no longer injects QuorumService into the guard

`lib/AppInfo/Application.php` MUST NOT pass `QuorumService` as an
argument when constructing or registering `MeetingTransitionGuard`.
The `QuorumService` Container registration itself MUST remain in
place; chain spec 3 removes that registration.

#### Scenario: Application wiring no longer references QuorumService for the guard
- **WHEN** inspecting `Application.php` near the `MeetingTransitionGuard` registration
- **THEN** no `QuorumService` reference MUST appear in the guard's construction site

#### Scenario: QuorumService registration survives
- **WHEN** inspecting `Application.php` for the `QuorumService` Container registration
- **THEN** the registration MUST still be present (deleted in chain spec 3)

### REQ-QGR-4: Guard tests stop mocking QuorumService

`tests/Unit/Lifecycle/MeetingTransitionGuardTest.php` MUST NOT
construct or pass a `QuorumService` mock when instantiating the guard
under test. Tests MUST fixture-load Meeting objects that already
include the `quorumMet` field.

#### Scenario: No QuorumService mock in the guard test
- **WHEN** scanning `MeetingTransitionGuardTest.php` for `QuorumService`
- **THEN** zero references MUST be found

#### Scenario: Three required behavioural cases
- **WHEN** running the test class
- **THEN** at least three named cases MUST exercise: `quorumMet=true` allows, `quorumMet=false` blocks, and `quorumRequired=null` allows
- **AND** all three MUST pass

### REQ-QGR-5: Regression scan confirms sole-caller assumption

After the rewrite, a repository-wide search for `QuorumService`,
`->validateQuorum`, or `->calculateQuorum` MUST hit zero locations
under `lib/Lifecycle/`, `lib/Controller/`, and `src/`. The only
permitted remaining hits are `lib/Service/QuorumService.php`,
`lib/AppInfo/Application.php`'s surviving registration, and
`tests/Unit/Service/QuorumServiceTest.php` (if present).

#### Scenario: No unexpected callers
- **WHEN** running `grep -rn "QuorumService\|->validateQuorum\|->calculateQuorum" lib/ src/`
- **THEN** matches MUST be limited to `lib/Service/QuorumService.php` and `lib/AppInfo/Application.php`

#### Scenario: Unexpected caller forces scope re-evaluation
- **GIVEN** an unexpected caller is discovered
- **THEN** this change MUST stop and the caller MUST be addressed before merge (either in this spec or a follow-up)
