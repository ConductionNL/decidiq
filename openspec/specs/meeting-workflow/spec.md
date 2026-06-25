---
status: done
---

# meeting-workflow Specification

## Purpose
Enforces a finite state machine for the meeting lifecycle (draft, scheduled, opened, paused, adjourned, closed, cancelled) stored on the CalDAV VEVENT, rejecting invalid transitions. Applies governance-domain workflow presets that determine which transitions, pause/adjourn states, and quorum enforcement apply, exposes transitions through a lifecycle action API, and records each transition in the audit trail.

## Requirements

### Requirement: REQ-MWF-001 — Meeting lifecycle states

The system SHALL enforce a finite state machine for meeting lifecycle with the following states: `draft`, `scheduled`, `opened`, `paused`, `adjourned`, `closed`, `cancelled`. The lifecycle state SHALL be stored as the X-DECIDESK-LIFECYCLE property on the CalDAV VEVENT.

**State diagram:**
```
draft → scheduled → opened → paused → opened (resume)
                      │         │                │
                      │         └── adjourned ──→ opened (reconvene)
                      │                  │
                      │                  └── closed
                      └── closed
cancelled ← (any state)
```

#### Scenario: REQ-MWF-001-S1 — Valid transition accepted
- **GIVEN** a meeting is in "draft" state
- **WHEN** the user triggers action "schedule"
- **THEN** the lifecycle transitions to "scheduled"
- **AND** the X-DECIDESK-LIFECYCLE VEVENT property is updated

#### Scenario: REQ-MWF-001-S2 — Invalid transition rejected
- **GIVEN** a meeting is in "draft" state
- **WHEN** the user triggers action "close"
- **THEN** the system returns HTTP 409 with message indicating the transition from "draft" to "closed" is not allowed

#### Scenario: REQ-MWF-001-S3 — Cancellation from any state
- **GIVEN** a meeting is in any lifecycle state except "closed"
- **WHEN** the user triggers action "cancel"
- **THEN** the lifecycle transitions to "cancelled"

#### Scenario: REQ-MWF-001-S4 — Closed meetings are immutable
- **GIVEN** a meeting is in "closed" state
- **WHEN** the user triggers any action
- **THEN** the system returns HTTP 409 indicating closed meetings cannot transition

### Requirement: REQ-MWF-002 — Domain-specific workflow presets

The system SHALL support 5 governance domain workflow presets, each defining which transitions are allowed, whether quorum is enforced, and whether pause/adjourn states are available.

**Presets:**

| Domain | Preset Key | Pause Allowed | Adjourn Allowed | Quorum Enforced | Notes |
|--------|-----------|---------------|-----------------|-----------------|-------|
| Legislative | `legislative` | Yes | Yes | Yes | Gemeentewet Art. 20 requires quorum |
| Association | `association` | No | Yes | Yes | Statutes define quorum rules |
| Corporate governance | `corporate` | No | No | Yes | Articles of association define quorum |
| Corporate operations | `operations` | No | No | No | Informal, no legal requirements |
| Citizen participation | `citizen` | No | Yes | No | Public hearings, no formal quorum |

#### Scenario: REQ-MWF-002-S1 — Legislative domain allows pause
- **GIVEN** a meeting belongs to a GovernanceBody with domain "legislative"
- **WHEN** the meeting is in "opened" state and the user triggers "pause"
- **THEN** the transition is allowed and the meeting moves to "paused"

#### Scenario: REQ-MWF-002-S2 — Operations domain rejects pause
- **GIVEN** a meeting belongs to a GovernanceBody with domain "operations"
- **WHEN** the meeting is in "opened" state and the user triggers "pause"
- **THEN** the system returns HTTP 409 indicating pause is not available for this domain

#### Scenario: REQ-MWF-002-S3 — Corporate domain rejects adjourn
- **GIVEN** a meeting belongs to a GovernanceBody with domain "corporate"
- **WHEN** the meeting is in "opened" state and the user triggers "adjourn"
- **THEN** the system returns HTTP 409 indicating adjournment is not available for this domain

### Requirement: REQ-MWF-003 — Lifecycle transition API endpoint

The system SHALL provide a POST `/api/meetings/{id}/actions/{action}` endpoint for triggering lifecycle transitions. Valid actions: `schedule`, `open`, `pause`, `resume`, `adjourn`, `reconvene`, `close`, `cancel`.

**Nextcloud OCP interface:** `\OCP\IGroupManager` (authorization check)

#### Scenario: REQ-MWF-003-S1 — Schedule a draft meeting
- **GIVEN** a meeting is in "draft" state
- **WHEN** POST `/api/meetings/abc-123/actions/schedule` is called
- **THEN** the lifecycle transitions to "scheduled" and HTTP 200 is returned with updated meeting

#### Scenario: REQ-MWF-003-S2 — Open a scheduled meeting with quorum met
- **GIVEN** a meeting is in "scheduled" state for a legislative body, quorum is met
- **WHEN** POST `/api/meetings/abc-123/actions/open` is called
- **THEN** the lifecycle transitions to "opened"

#### Scenario: REQ-MWF-003-S3 — Open a scheduled meeting without quorum (enforced domain)
- **GIVEN** a meeting is in "scheduled" state for a legislative body, quorum is NOT met
- **WHEN** POST `/api/meetings/abc-123/actions/open` is called
- **THEN** the system returns HTTP 409 with message "Quorum not met: 15 of 20 required members present"

#### Scenario: REQ-MWF-003-S4 — Resume a paused meeting
- **GIVEN** a meeting is in "paused" state
- **WHEN** POST `/api/meetings/abc-123/actions/resume` is called
- **THEN** the lifecycle transitions to "opened"

#### Scenario: REQ-MWF-003-S5 — Invalid action name
- **GIVEN** any meeting state
- **WHEN** POST `/api/meetings/abc-123/actions/invalid` is called
- **THEN** the system returns HTTP 400 with message "Unknown action: invalid"

### Requirement: REQ-MWF-004 — Audit trail for lifecycle transitions

The system SHALL record every lifecycle transition in the audit trail with: user UID, timestamp, from-state, to-state, action name, and governance domain. If quorum was validated, the audit entry SHALL include the quorum check result.

#### Scenario: REQ-MWF-004-S1 — Transition audit entry
- **GIVEN** a meeting transitions from "scheduled" to "opened"
- **WHEN** the audit trail is queried
- **THEN** it contains an entry with action "lifecycle_transition", from "scheduled", to "opened", domain "legislative", and quorum result `{ required: 20, present: 25, met: true }`
