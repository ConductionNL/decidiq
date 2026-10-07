## ADDED Requirements

### Requirement: REQ-COI-101 Only the declaring member or an authorized official may record a declaration
The app MUST reject `ConflictOfInterestController::declare()` requests unless the calling user IS
the Membership named by `membershipId` (the declarant), a chair or secretary of the relevant
meeting's GovernanceBody, or a Nextcloud admin. `#[NoAdminRequired]` alone (any authenticated
user) MUST NOT be sufficient authorization for recording a declaration about another member.

#### Scenario: Member declares their own conflict of interest
- **GIVEN** an authenticated user whose Nextcloud account resolves to Membership A
- **WHEN** the user calls `POST /api/conflicts` with `membershipId = A`
- **THEN** the declaration is recorded (`201 Created`)

#### Scenario: Chair records a declaration on behalf of another member
- **GIVEN** an authenticated user who is chair of the relevant meeting's GovernanceBody
- **WHEN** the chair calls `POST /api/conflicts` with `membershipId = B` (not the chair)
- **THEN** the declaration is recorded (`201 Created`)

#### Scenario: Unrelated authenticated user is rejected
- **GIVEN** an authenticated user who is neither Membership B, nor chair/secretary of the
  relevant meeting's GovernanceBody, nor an admin
- **WHEN** the user calls `POST /api/conflicts` with `membershipId = B`
- **THEN** the request is rejected with `403 Forbidden` and a static message; no declaration is created

---

### Requirement: REQ-COI-102 Only the member or an authorized official may read a member's conflict declarations
The app MUST reject `ConflictOfInterestController::forMember()` requests unless the calling user
IS the Membership named by the `id` route parameter, a chair or secretary of the relevant
meeting's GovernanceBody, or a Nextcloud admin.

#### Scenario: Member reads their own conflict declarations
- **GIVEN** an authenticated user whose Nextcloud account resolves to Membership A
- **WHEN** the user calls `GET /api/members/A/conflicts?agendaItemId=...`
- **THEN** the active conflict (if any) is returned (`200 OK`)

#### Scenario: Secretary reads another member's conflict declarations
- **GIVEN** an authenticated user who is secretary of the relevant meeting's GovernanceBody
- **WHEN** the secretary calls `GET /api/members/B/conflicts?agendaItemId=...` (not the secretary)
- **THEN** the active conflict (if any) is returned (`200 OK`)

#### Scenario: Unrelated authenticated user cannot read another member's declarations
- **GIVEN** an authenticated user who is neither Membership B, nor chair/secretary of the
  relevant meeting's GovernanceBody, nor an admin
- **WHEN** the user calls `GET /api/members/B/conflicts?agendaItemId=...` by guessing or
  enumerating the Membership UUID
- **THEN** the request is rejected with `403 Forbidden`

---

### Requirement: REQ-COI-103 Only a chair or secretary may record the action taken
The app MUST reject `ConflictOfInterestController::recordAction()` requests unless the calling
user is a chair or secretary of the relevant meeting's GovernanceBody, or a Nextcloud admin.
Recording the mitigating action taken (e.g. recusal) is a presiding-officer act: the declaring
member themselves MUST NOT be authorized to record it merely by having filed the original
declaration.

#### Scenario: Secretary records the action taken on a declaration
- **GIVEN** an authenticated user who is secretary of the relevant meeting's GovernanceBody
- **WHEN** the secretary calls `PUT /api/conflicts/{id}/action` with `actionTaken = recused-from-vote`
- **THEN** the declaration's `actionTaken` is updated (`200 OK`)

#### Scenario: The declaring member cannot record their own action
- **GIVEN** an authenticated user whose Membership filed declaration `{id}`, and who holds no
  chair/secretary role
- **WHEN** the user calls `PUT /api/conflicts/{id}/action`
- **THEN** the request is rejected with `403 Forbidden`; `actionTaken` is unchanged

#### Scenario: Unrelated authenticated user cannot record an action
- **GIVEN** an authenticated user who is neither chair/secretary of the relevant GovernanceBody
  nor an admin
- **WHEN** the user calls `PUT /api/conflicts/{id}/action` by guessing or enumerating the
  declaration UUID
- **THEN** the request is rejected with `403 Forbidden`; `actionTaken` is unchanged
