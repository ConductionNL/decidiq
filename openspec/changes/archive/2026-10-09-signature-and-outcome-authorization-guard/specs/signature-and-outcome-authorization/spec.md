## ADDED Requirements

### Requirement: REQ-SIG-101 Only a body signatory may finalize signed minutes
The app MUST reject `EIDASSignatureController::finalize()` requests unless the calling user is a
member of the OR-projected signatory scope (`decidesk:body:{bodyId}:signatory`) of the
GovernanceBody that owns the Minutes record, or is a Nextcloud admin. `#[NoAdminRequired]` plus an
authentication check MUST NOT be sufficient: finalizing affixes a QES signature set to a
governance record, writes `pdfArchiveReference` / `hashSha256` / `version = signed` /
`eidasSignatureLevel = QES` / `signedBy` onto the Minutes row, resolves the `method=signature`
`DecisionStage`, and appends a `signature` audit entry. The rule is the same one
`EIDASSignatureController::initiate()` already enforces — starting and completing the same signing
flow MUST require the same authority. Resolution failure (unresolvable Meeting/GovernanceBody,
unpopulated scope, OpenRegister error) MUST deny.

#### Scenario: A signatory finalizes the minutes they signed
- **GIVEN** an authenticated user who is in the signatory scope of the GovernanceBody owning
  Minutes `M`
- **WHEN** the user calls `POST /api/minutes/M/eidas/finalize` with at least one signature
- **THEN** `finalizeMinutes()` runs and the archive reference + hash are returned (`200 OK`)

#### Scenario: A non-signatory cannot mark minutes as signed
- **GIVEN** an authenticated user who holds no signatory role on the GovernanceBody owning
  Minutes `M`
- **WHEN** the user calls `POST /api/minutes/M/eidas/finalize` by guessing or enumerating the
  Minutes UUID
- **THEN** the request is rejected with `403 Forbidden`, `finalizeMinutes()` is never invoked, and
  the Minutes row is unchanged

### Requirement: REQ-SIG-102 Only a body signatory may verify a signature on a minutes record
The app MUST reject `EIDASSignatureController::verify()` requests unless the calling user is in
the signatory scope of the GovernanceBody owning the Minutes record named by the route, or is a
Nextcloud admin. The endpoint is routed per-Minutes (`POST /api/minutes/{minutesId}/eidas/verify`)
and belongs to the same signing flow as `initiate()`/`finalize()`; its response carries the
signer's `certificateThumbprint`, which under eIDAS identifies a natural person.

#### Scenario: A signatory verifies a signature on their body's minutes
- **GIVEN** an authenticated user in the signatory scope of the GovernanceBody owning Minutes `M`
- **WHEN** the user calls `POST /api/minutes/M/eidas/verify` with a `requestId` and `signature`
- **THEN** the trust-list verdict is returned (`200 OK`) with the `minutesId` echoed

#### Scenario: A non-signatory cannot verify against another body's minutes
- **GIVEN** an authenticated user who holds no signatory role on the GovernanceBody owning
  Minutes `M`
- **WHEN** the user calls `POST /api/minutes/M/eidas/verify`
- **THEN** the request is rejected with `403 Forbidden` and `verifySignature()` is never invoked

### Requirement: REQ-SIG-103 Certificate trust-status lookup is a deliberately app-wide authenticated read
`EIDASSignatureController::certStatus()` (`POST /api/eidas/validate-cert`) MUST remain available
to every authenticated caller and MUST NOT acquire a per-object authorization guard. It accepts no
caller-supplied object identifier: its only input is a certificate SHA-256 thumbprint and its only
output is `valid` / `issuer` / `trustListLevel`, all sourced from the public EU Trusted List. No
decidiq object is reachable through it and it returns nothing derived from app data. This posture
MUST be recorded explicitly in the method docblock as a reason-bearing
`@no-admin-idor-exempt` tag so it reads as a decision rather than an omission. The authoritative
chain validation remains server-side inside `finalizeMinutes()`, which REQ-SIG-101 guards.

#### Scenario: Any authenticated caller may check a certificate thumbprint
- **GIVEN** any authenticated Nextcloud user
- **WHEN** the user calls `POST /api/eidas/validate-cert` with a `certificateThumbprint`
- **THEN** the trust-list verdict is returned (`200 OK`)

#### Scenario: An unauthenticated caller is refused
- **GIVEN** a request with no Nextcloud session or credential
- **WHEN** it targets `POST /api/eidas/validate-cert`
- **THEN** the response is `401 Unauthorized`

### Requirement: REQ-DCDH-101 Only the raising consumer, an admin, or any caller of a published decision may read an outcome envelope
The app MUST reject `IntegrationController::getOutcome()` requests unless the calling user is the
OpenRegister owner of the Decision (`@self.owner` — the identity that raised it through
`POST /api/v1/decisions`, which is the consumer REQ-DCDH-003 exists to serve), a Nextcloud admin,
or the Decision is published (`isPublished === 'public'`). Authentication alone MUST NOT be
sufficient: the envelope discloses the cross-app subject coordinates
(`subjectRegister`/`subjectSchema`/`subjectId`), the consumer's `externalReference`, and the
`signers` array of an internal, not-yet-published decision.

The controller MUST NOT claim that per-object read access is delegated to OpenRegister RBAC while
the `Decision` schema declares no `authorization` block — the register baseline
(`read`/`list`: `authenticated` + `public`) authorises everyone, so the delegation is real but
open. Where the guard cannot resolve the Decision (OpenRegister unavailable, lookup error) it MUST
deny rather than skip. A Decision that does not exist MUST still yield `404` from the envelope
assembler; the guard MUST NOT turn a miss into a `403`.

#### Scenario: The consumer that raised the decision polls its outcome
- **GIVEN** a Decision raised through `POST /api/v1/decisions` by user `svc-shillinq`
- **WHEN** `svc-shillinq` calls `GET /api/v1/decisions/{id}/outcome`
- **THEN** the outcome envelope is returned (`200 OK`)

#### Scenario: An unrelated authenticated user cannot read an internal decision's envelope
- **GIVEN** a Decision with `isPublished = internal` raised by another user
- **WHEN** an unrelated authenticated user calls `GET /api/v1/decisions/{id}/outcome` by guessing
  or enumerating the Decision UUID
- **THEN** the request is rejected with `403 Forbidden` and no envelope is assembled

#### Scenario: A published decision's outcome stays readable
- **GIVEN** a Decision with `isPublished = public`
- **WHEN** any authenticated user calls `GET /api/v1/decisions/{id}/outcome`
- **THEN** the outcome envelope is returned (`200 OK`)

#### Scenario: An admin may read any outcome envelope
- **GIVEN** an authenticated Nextcloud administrator
- **WHEN** the admin calls `GET /api/v1/decisions/{id}/outcome` for any Decision
- **THEN** the outcome envelope is returned (`200 OK`)

#### Scenario: A missing decision is still not found, not forbidden
- **GIVEN** a Decision UUID that does not exist
- **WHEN** an authenticated user calls `GET /api/v1/decisions/{id}/outcome`
- **THEN** the response is `404 Not Found`

### Requirement: REQ-DCDH-102 Only the raising consumer or an admin may attach an outcome callback to a decision

`POST /api/v1/decisions/{id}/subscriptions` SHALL refuse with `403 Forbidden` unless the
caller is the Decision's OpenRegister owner (`@self.owner`) or a Nextcloud administrator.

The `isPublished === 'public'` allowance of REQ-DCDH-101 SHALL NOT apply to this write.
Public readability is not a write grant: `isPublished` is set only by
`DecisionController::publish()`, an admin-only setting, and honouring it here would mean an
admin's act of widening who may READ a decision silently widens who may WRITE its delivery
target — hardest on exactly the decisions that matter most.

`outcomeCallbackUrl` is a single scalar, not a subscription list, so an unauthorised write
does not merely add a subscriber: it REPLACES the raising consumer's delivery target, so the
outcome envelope is pushed to a URL of the caller's choosing and the legitimate consumer
never receives its callback — hijack and denial in one write.

The anti-SSRF `isRegistryConsumer()` check SHALL NOT be treated as satisfying this
requirement. It validates the callback URL against the app-wide ADR-019 registry, constraining
WHERE data may go and never WHO may redirect it.

#### Scenario: The consumer that raised the decision attaches its callback
- **GIVEN** a Decision whose OpenRegister owner is the calling consumer
- **WHEN** that consumer calls `POST /api/v1/decisions/{id}/subscriptions`
- **THEN** the outcome callback URL is persisted (`200 OK`)

#### Scenario: An unrelated authenticated user cannot attach a callback
- **GIVEN** a Decision raised by another user
- **WHEN** an unrelated authenticated user calls `POST /api/v1/decisions/{id}/subscriptions`
- **THEN** the request is rejected with `403 Forbidden` and no callback URL is persisted

#### Scenario: An unrelated user cannot attach a callback to a published decision
- **GIVEN** a Decision with `isPublished = public` raised by another user
- **WHEN** an unrelated authenticated user calls `POST /api/v1/decisions/{id}/subscriptions`
- **THEN** the request is rejected with `403 Forbidden`, because public readability is not a
  write grant

#### Scenario: An admin may attach a callback to any decision
- **GIVEN** an authenticated Nextcloud administrator
- **WHEN** the admin calls `POST /api/v1/decisions/{id}/subscriptions` for any Decision
- **THEN** the outcome callback URL is persisted (`200 OK`)

#### Scenario: A missing decision is still not found, not forbidden
- **GIVEN** a Decision UUID that does not exist
- **WHEN** an authenticated user calls `POST /api/v1/decisions/{id}/subscriptions`
- **THEN** the response is `404 Not Found`
