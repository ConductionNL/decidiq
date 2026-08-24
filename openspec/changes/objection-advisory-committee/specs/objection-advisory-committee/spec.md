# objection-advisory-committee Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [objection-advisory-committee](../../changes/objection-advisory-committee/)

## Purpose

Make a Dutch bezwaaradviescommissie (Awb 7:13 objection advisory committee) expressible as a decidiq `GovernanceBody`, so no other app has to keep a parallel committee schema. Four additive fields close the measured gap — `active`, a numeric `quorum`, `jurisdiction`, and `Membership.external` — plus a scoped write path on the cross-app API, without which another app has no supported way to place a body here at all.

**Standards**: Awb (Algemene wet bestuursrecht) art. 7:13, Schema.org `Organization`
**ORI note**: OpenRaadsinformatie models no objection advisory committee; the body is an ordinary `GovernanceBody` with `bodyType: advisory-body`.

## ADDED Requirements

### Requirement: REQ-OAC-001 GovernanceBody carries an operational active flag

The system SHALL add an optional `active` boolean property (default `true`, with a `title`) to the `GovernanceBody` schema, meaning whether the body may be assigned new work.

`active` SHALL be distinct from the existing `termStart`/`termEnd` pair. A body inside its term can be suspended, and a body past its term can still be finishing open cases; a consumer that derived availability from the dates alone would be wrong in both directions.

`active` SHALL NOT be confused with `x-openregister.active`, which is a SCHEMA-level flag and says nothing about an individual body.

#### Scenario: A suspended committee within its term

- GIVEN a `GovernanceBody` with `termStart` in the past and `termEnd` in the future
- WHEN `active` is set to `false`
- THEN the body reads as unavailable for new work
- AND its term dates are unchanged

#### Scenario: Absent active reads as available

- GIVEN a `GovernanceBody` created without `active`
- WHEN it is read
- THEN `active` is `true`
- AND a consumer that treats a missing value as "not available" would wrongly retire every body created before this change

### Requirement: REQ-OAC-002 GovernanceBody carries a numeric quorum

The system SHALL add an optional `quorum` integer property (minimum 2, with a `title`) to the `GovernanceBody` schema, meaning the minimum number of members required for a valid sitting.

The existing `quorumRule` string SHALL be retained unchanged. The two answer different questions — `quorumRule` how a quorum is CALCULATED (e.g. `majority`), `quorum` how many members are REQUIRED — and the descriptions of both SHALL say so, each naming the other.

`quorum` SHALL NOT carry a default. An unset value means "not specified" and MUST NOT be readable as `0`, which would assert that no members are needed.

#### Scenario: Awb 7:13 minimum expressed

- GIVEN an objection advisory committee that must sit with at least three members
- WHEN `quorum` is set to `3`
- THEN the requirement is stored as a number and can be compared against an attendance count
- AND `quorumRule` may independently record how a majority is computed

#### Scenario: A quorum below the legal floor is rejected

- GIVEN a create or update setting `quorum` to `1`
- WHEN it is saved
- THEN OpenRegister schema validation rejects it against `minimum: 2`

### Requirement: REQ-OAC-003 GovernanceBody carries jurisdiction and statutory basis

The system SHALL add two optional string properties (each with a `title`) to the `GovernanceBody` schema:

- `jurisdiction` — the territorial or subject-matter competence of the body.
- `statutoryBasis` — the legal instrument the body is constituted under, as free text (e.g. `Awb 7:13`, a local verordening).

`statutoryBasis` SHALL be free text rather than an enumeration. A Dutch tripartite enum (BAC/VKK/VTH) would not survive contact with the non-Dutch bodies decidiq already models, and a closed vocabulary that cannot express a caller's real value forces that caller to pick a wrong one.

#### Scenario: Three Dutch committee regimes distinguished without an enum

- GIVEN three committees constituted under different instruments
- WHEN each records its instrument in `statutoryBasis`
- THEN the three remain distinguishable
- AND no value has to be mapped onto a bucket that does not fit

### Requirement: REQ-OAC-004 Membership records whether a member sits from outside

The system SHALL add an optional `external` boolean property (default `false`, with a `title`) to the `Membership` schema, meaning the member sits from outside the administrative organ.

`external` SHALL be distinct from the existing `independenceStatus`. `independenceStatus` encodes corporate-governance independence (`independent` / `non-independent` / `unknown`); `external` encodes employment by the administrative organ, which Awb 7:13(2) requires of an objection committee's chair. The description of each SHALL name the other and state what it does not mean.

#### Scenario: An Awb 7:13(2) chair

- GIVEN a `Membership` with `role: chair` on an objection advisory committee
- WHEN `external` is `true`
- THEN the record asserts the chair is not employed by the administrative organ
- AND `independenceStatus` is unaffected and may be `unknown`

#### Scenario: The two fields disagree without contradiction

- GIVEN a membership with `external: true` and `independenceStatus: non-independent`
- WHEN it is read
- THEN both values stand
- AND neither is derivable from the other

### Requirement: REQ-OAC-005 Governance bodies are writable through the cross-app API

The system SHALL extend the cross-app API to allow creating and updating a `governance-body` resource. Read routes SHALL be unchanged, and no resource other than `governance-body` SHALL become writable by this change.

Without this, another app has no supported route to place a body in decidiq: the only writable cross-app path decidiq exposes today creates a `Decision`, and reaching into decidiq's register directly is what ADR-022 and ADR-066 forbid.

**Authorization SHALL be delegated to OpenRegister's own RBAC**, by performing the write through `ObjectService` as the acting user, rather than by introducing a new permission mechanism in decidiq.

This is stated precisely because the obvious alternative is not available. `ApiController::SCOPE_MAP` reads as a scope control and enforces nothing — it is declared and never referenced by any method, so no request has ever been checked against it. Specifying a `governance-bodies:write` scope would have described a gate that does not exist, and every test of it would have had to assert against a mechanism no runtime path consults. Delegating to OpenRegister means the write is subject to a control that is actually applied, and the same one that governs the register's own UI.

The posture SHALL match the existing cross-app write (`integration#createDecision`): authenticated, `#[NoAdminRequired]`. An unauthenticated write SHALL be refused.

#### Scenario: An authenticated create

- GIVEN an authenticated caller whose OpenRegister permissions allow writing the register
- WHEN it POSTs a governance body with `name`, `bodyType` and `domain`
- THEN the body is created and returned
- AND a body missing any required property is rejected by schema validation

#### Scenario: Unauthenticated writes are refused

- GIVEN no signed-in user
- WHEN a governance body is POSTed
- THEN the request is refused
- AND no object is created

#### Scenario: The write path is not opened for other resources

- GIVEN an authenticated caller
- WHEN it POSTs to a resource other than `governance-body`
- THEN the request is refused with "Unknown resource"
- AND no object is created

#### Scenario: An update replaces rather than patches

- GIVEN an existing governance body
- WHEN it is PUT with a body omitting a required property
- THEN the request is refused, naming the missing properties
- AND the stored object is unchanged — a partial body MUST NOT blank the fields it omits

### Requirement: REQ-OAC-007 Route names are unique across the table

The system SHALL declare each route with a distinct `name`. Create and update SHALL be separate controller methods rather than one method registered twice.

A route `name` is the route IDENTIFIER. Two entries sharing one name do not fail at the duplicate: the collision throws while the route table is being built, which takes down EVERY route in the app. Measured on a running instance while developing this change — declaring `api#write` for both POST and PUT made the entire `/api/v1` surface answer HTTP 500, including endpoints untouched by the change, and the app still reported itself as enabled.

#### Scenario: The whole table survives adding a route

- GIVEN the write routes are declared
- WHEN the app's route table is built
- THEN every pre-existing route still resolves
- AND `POST /api/v1/decisions` still reaches the decision-hub handler rather than the generic writer

### Requirement: REQ-OAC-006 The new fields are seeded and surfaced

The system SHALL seed one objection advisory committee demonstrating the new fields, and SHALL surface `active`, `quorum`, `jurisdiction` and `statutoryBasis` on the governance-body detail page.

#### Scenario: The seeded committee demonstrates the fields

- GIVEN a fresh install
- WHEN the register is seeded
- THEN a governance body exists with `bodyType: advisory-body`, a `statutoryBasis` naming Awb 7:13, a `quorum` of at least 2, and `active: true`

#### Scenario: A reader can see them

- GIVEN that body's detail page
- WHEN it renders
- THEN the four new fields are shown
- AND a field that is unset renders as unset rather than as a wrong value
