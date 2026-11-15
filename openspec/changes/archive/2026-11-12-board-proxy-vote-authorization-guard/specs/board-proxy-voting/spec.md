## ADDED Requirements

### Requirement: REQ-BPV-001 Only the grantor or an authorized official may register a proxy
The app MUST reject `ProxyVoteController::register()` requests unless the calling user is the
`grantorId` themselves, a chair or clerk of the meeting's GovernanceBody, or a Nextcloud admin.
`#[NoAdminRequired]` alone (any authenticated user) MUST NOT be sufficient authorization for
creating a proxy delegation on another member's behalf.

#### Scenario: Member registers their own proxy
- **GIVEN** an authenticated Participant A
- **WHEN** A calls `POST /api/proxy-votes` with `grantorId = A`, `holderId = B`
- **THEN** the proxy is registered (`201 Created`)

#### Scenario: Chair registers a proxy on behalf of two other members
- **GIVEN** an authenticated user who is chair of the meeting's GovernanceBody
- **WHEN** the chair calls `POST /api/proxy-votes` with `grantorId = A`, `holderId = B` (neither
  the chair)
- **THEN** the proxy is registered (`201 Created`)

#### Scenario: Unrelated authenticated user is rejected
- **GIVEN** an authenticated Participant C who is neither the grantor, the holder, nor chair/clerk
  of the meeting's GovernanceBody, nor an admin
- **WHEN** C calls `POST /api/proxy-votes` with `grantorId = A`, `holderId = B`
- **THEN** the request is rejected with `403 Forbidden` and a static message; no proxy is created

---

### Requirement: REQ-BPV-002 Only a party to the proxy or an authorized official may suspend or revoke it
The app MUST reject `ProxyVoteController::suspend()` and `ProxyVoteController::revoke()` requests
unless the calling user is the proxy's grantor, the proxy's holder, a chair/clerk of the meeting's
GovernanceBody, or a Nextcloud admin. Deriving `$actor` from the session for the audit-log entry
MUST NOT be treated as equivalent to authorizing the mutation itself.

#### Scenario: Grantor revokes their own proxy
- **GIVEN** Participant A granted a proxy to Participant B
- **WHEN** A calls `POST /api/proxy-votes/{id}/revoke`
- **THEN** the proxy transitions to `revoked` (`200 OK`)

#### Scenario: Holder suspends a proxy held on their behalf
- **GIVEN** Participant B holds a proxy from Participant A
- **WHEN** B calls `POST /api/proxy-votes/{id}/suspend`
- **THEN** the proxy transitions to `suspended` (`200 OK`)

#### Scenario: Unrelated authenticated user cannot suspend or revoke another member's proxy
- **GIVEN** Participant C is neither the grantor nor the holder of proxy `{id}`, nor chair/clerk of
  the governing body, nor an admin
- **WHEN** C calls `POST /api/proxy-votes/{id}/revoke` (or `/suspend`) by guessing or enumerating
  the proxy UUID
- **THEN** the request is rejected with `403 Forbidden` and the proxy's status is unchanged
