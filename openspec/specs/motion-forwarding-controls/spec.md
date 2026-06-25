---
status: done
---

# motion-forwarding-controls Specification

## Purpose
Controls forwarding a motion from one governance body to another, with admin settings that define which roles may forward and whether the receiving body's chair must approve. Enforces the role check on the backend in `MotionService::forwardMotion()`, creates the forwarded motion in the target body (submitted for approval or immediately active), and links it to the source motion so the lineage is visible from both sides.

## Requirements

### Requirement: REQ-MFC-001 Admin can configure which roles may forward a motion to another GovernanceBody
The app SHALL provide an admin settings toggle "Doorzending" that controls which roles (chair, secretary, member) are permitted to forward a Motion to another GovernanceBody. The configuration is stored in `IAppConfig` under `motion_forwarding_roles` as a JSON array.

#### Scenario: Admin restricts motion forwarding to chair and secretary
- **GIVEN** the admin settings "Doorzending" section
- **WHEN** the admin selects "Voorzitter" and "Griffier" and saves
- **THEN** `IAppConfig` stores `motion_forwarding_roles: ["chair", "secretary"]`; the "Doorsturen" action is hidden from users with role `member` on `MotionDetail.vue`

#### Scenario: Admin permits all members to forward motions
- **GIVEN** the admin settings "Doorzending" section
- **WHEN** the admin selects "Voorzitter", "Griffier", and "Lid" and saves
- **THEN** `IAppConfig` stores `motion_forwarding_roles: ["chair", "secretary", "member"]`; the "Doorsturen" action is visible to all active Members on `MotionDetail.vue`

---

### Requirement: REQ-MFC-002 Admin can require approval from the receiving body's chair when forwarding
The app SHALL provide a boolean toggle "Doorzending vereist goedkeuring" in admin settings. When enabled, a forwarded Motion is created with `lifecycle: "submitted"` in the target GovernanceBody and a Nextcloud notification is sent to the target body's chair for approval. When disabled, the forwarded Motion is immediately active.

#### Scenario: Motion forwarded with approval required
- **GIVEN** `motion_forwarding_requires_approval: true` in app config
- **WHEN** an authorised user forwards Motion "Motie Woonvisie" to GovernanceBody "Commissie Wonen"
- **THEN** a new Motion is created in "Commissie Wonen" with `lifecycle: "submitted"`, linked to the source Motion via OpenRegister relation; a Nextcloud notification is sent to the chair of "Commissie Wonen"

#### Scenario: Motion forwarded without approval requirement
- **GIVEN** `motion_forwarding_requires_approval: false`
- **WHEN** an authorised user forwards the motion
- **THEN** the new Motion in the target body is created with `lifecycle: "debating"` and no approval notification is sent

---

### Requirement: REQ-MFC-003 `MotionService::forwardMotion()` enforces role check on the backend
The app SHALL validate the actor's Membership role against `motion_forwarding_roles` in `MotionService::forwardMotion()`. If the role is not in the allowed list, the method SHALL throw a `403 Forbidden` response. The role check MUST NOT be performed frontend-only.

#### Scenario: Member attempts to forward when only chair/secretary allowed
- **GIVEN** `motion_forwarding_roles: ["chair", "secretary"]` and an actor with role `member`
- **WHEN** a POST is made to `POST /api/motions/{id}/forward`
- **THEN** `MotionService::forwardMotion()` returns a `403 Forbidden` response with `{ "message": "Access denied" }`; no Motion is created in the target body

#### Scenario: Chair successfully forwards a motion
- **GIVEN** `motion_forwarding_roles: ["chair", "secretary"]` and an actor with role `chair`
- **WHEN** the chair submits `POST /api/motions/{id}/forward` with `{ "targetBodyId": "...", "justification": "Ter behandeling in commissie" }`
- **THEN** `MotionService::forwardMotion()` creates the forwarded Motion, logs an Activity entry "Motie doorgestuurd naar [body name]", and returns `201 Created` with the new Motion object

---

### Requirement: REQ-MFC-004 Forwarded motions are linked to their source and marked as forwarded
The app SHALL create an OpenRegister relation from the forwarded Motion to the source Motion, and update the source Motion with a `forwarded` marker note, so the lineage is visible in both MotionDetail views.

#### Scenario: Source motion shows forwarding information
- **GIVEN** a Motion that has been forwarded to "Commissie Wonen"
- **WHEN** any user opens the source MotionDetail
- **THEN** a "Doorgestuurd naar" panel shows the target GovernanceBody name, the forwarding date, and a link to the forwarded Motion in the target body

#### Scenario: Forwarded motion shows its source
- **GIVEN** the forwarded Motion in the target GovernanceBody
- **WHEN** a user opens its MotionDetail
- **THEN** an "Afkomstig van" panel shows the source GovernanceBody name and a link to the original Motion
