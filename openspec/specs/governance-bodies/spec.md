---
status: done
status-note: In progress 2026-06-14 via popolo-decision-makers (ContactDetail Popolo schema added; Person + Membership become the governance-body decision-maker model per ADR-001/ADR-006). In progress 2026-06-14 via retire-board-portal (parallel Board schema + Board views retired per ADR-006; corporate boards become governance-body objects with bodyType=corporate-board and mode=corp labels).
openspec-changes:
  - popolo-decision-makers
  - retire-board-portal
---

# governance-bodies Specification

## Purpose
Defines the GovernanceBody as the universal model for any decision-making body — municipal councils, associations, and corporate boards alike — carrying a workflowTemplate preset that governs its meeting lifecycle and a bodyType enum that absorbs corporate subtypes (supervisory-board, executive-board) so no separate Board schema is needed. People relate to a body through the Popolo Membership entity with typed multi-value ContactDetails, replacing the deprecated flat Participant model. The body detail page lists its scheduled and recent meetings and allows creating a meeting from the body regardless of mode.
## Requirements
### Requirement: REQ-GBD-001 — Governance body workflowTemplate property

The GovernanceBody entity SHALL include a `workflowTemplate` property (string, optional) that stores the governance domain preset key. Valid values: `legislative`, `association`, `corporate`, `operations`, `citizen`. This property determines which meeting lifecycle transitions are allowed for meetings of this body (see meeting-workflow spec).

**Entity property (ADR-000):**
| Property | Type | Required | Description |
|----------|------|----------|-------------|
| workflowTemplate | string | No | Domain preset key: legislative, association, corporate, operations, citizen |

#### Scenario: REQ-GBD-001-S1 — Set workflowTemplate on body creation
- **GIVEN** the user creates a GovernanceBody with domain "legislative"
- **WHEN** the body is saved
- **THEN** workflowTemplate defaults to "legislative" based on the domain value

#### Scenario: REQ-GBD-001-S2 — Custom workflowTemplate override
- **GIVEN** a GovernanceBody exists with domain "legislative" and workflowTemplate "legislative"
- **WHEN** the admin updates workflowTemplate to "association"
- **THEN** future meetings for this body follow the association workflow preset

### Requirement: REQ-GBD-002 — Governance body meetings section

The GovernanceBody detail page SHALL include a "Scheduled Meetings" `CnDetailCard` section displaying upcoming and recent meetings for the body. The section SHALL use a `CnDataTable` with columns: title, scheduledDate, lifecycle status. Meetings SHALL be fetched via reverse lookup (`fetchUsed`) from the Meeting wrapper's relation to GovernanceBody.

#### Scenario: REQ-GBD-002-S1 — Meetings listed on body detail page
- **GIVEN** GovernanceBody "Gemeenteraad Delft" has 3 upcoming and 2 recent meetings
- **WHEN** the user views the GovernanceBody detail page
- **THEN** the "Scheduled Meetings" section displays 5 meetings sorted by scheduledDate

#### Scenario: REQ-GBD-002-S2 — Navigate to meeting from body
- **GIVEN** the meetings section displays meeting "Vergadering april 2026"
- **WHEN** the user clicks the meeting row
- **THEN** the router navigates to `/meetings/{meetingId}`

### Requirement: REQ-GBD-003 — Meeting creation from governance body
The system MUST allow creating a `meeting` from a `governance-body` regardless of
mode. After retiring the board portal, a corporate board meeting is a universal
`meeting` linked to a `governance-body` (no `board-meeting` schema). A corporate
`meeting` seed and `minutes` seed are provided so the corporate scenario is
demonstrable on install.

#### Scenario: Pre-filled meeting from a corporate governance body
- GIVEN a `governance-body` with `bodyType=supervisory-board`
- WHEN a meeting is created from it
- THEN a universal `meeting` is created (no `board-meeting` schema is used)

#### Scenario: Corporate meeting + minutes seeded
- GIVEN the register is imported on a clean instance
- WHEN meetings and minutes are listed
- THEN a `meeting` seed `rvc-vergadering-2025-q2` and a `minutes` seed `notulen-rvc-2025-q2` exist

### Requirement: REQ-GBD-010 — ContactDetail schema (popolo:ContactDetail)
The system MUST define a `ContactDetail` schema (`schemaType: popolo:ContactDetail`)
providing typed, multi-value contacts for a Person or GovernanceBody, mirroring the
ADR-000 ContactDetail field set: `type` (required enum: email, phone, fax, cell, address,
url), `value` (required), `label`, `note`, `validFrom`/`validUntil` (date-time).
ContactDetail MUST declare `x-openregister-relations` to `Person` and `GovernanceBody`
(each `many-to-one`).

#### Scenario: Typed multi-value contact for a person
- GIVEN a Person exists
- WHEN two ContactDetails of type "email" and "phone" are linked to the Person
- THEN both resolve their `Person` relation and carry distinct `type`/`value`

#### Scenario: Contact linked to a governance body
- GIVEN a GovernanceBody exists
- WHEN a ContactDetail of type "address" is linked to the GovernanceBody
- THEN it resolves its `GovernanceBody` relation

### Requirement: REQ-GBD-011 — Person + Membership as the governance-body decision-maker model
The system MUST relate people to a GovernanceBody through the Popolo `Membership` entity
(Person ↔ GovernanceBody), and MUST provide seeded ContactDetail demo data linked to
seeded Persons and GovernanceBodies. The flat `Participant` schema MUST NOT be used as
the canonical decision-maker model for new data (it is retained as a deprecated shim —
see `participant-crud`).

#### Scenario: Body membership via Membership, not Participant
- GIVEN a GovernanceBody "Gemeenteraad Amsterdam"
- WHEN its decision makers are seeded
- THEN they are represented as `Person` + `Membership` records (not new `Participant` records)

#### Scenario: Seeded contact details
- GIVEN the register is imported on a clean instance
- WHEN ContactDetails are listed
- THEN at least one email, one phone, and one address ContactDetail exist, linked to a Person or GovernanceBody

### Requirement: REQ-GBD-012 — Corporate board is the universal governance-body (mode=corp)
A corporate board MUST be expressed as a `governance-body` with
`bodyType=supervisory-board` / `executive-board` (ADR-006) and mode=corp labels —
never a separate schema. The parallel `Board` / `BoardMember` schemas, the
`BoardList` / `BoardDetail` views, the `BoardCreateModal`, and the board-CRUD
controller/service are removed; board members are `Person` + `Membership`
(ADR-001, `popolo-decision-makers`). The corporate scenario is re-seeded on the
universal `governance-body`.

#### Scenario: Corporate board uses the universal governance-body
- GIVEN the register is imported on a clean instance
- WHEN the schemas are listed
- THEN no `Board` or `BoardMember` schema exists
- AND a `governance-body` seed `raad-van-commissarissen-acme-bv` with `bodyType=supervisory-board` exists

#### Scenario: bodyType enum carries the corporate subtypes
- GIVEN the `GovernanceBody` schema
- WHEN its `bodyType` enum is inspected
- THEN it includes `supervisory-board` and `executive-board` (alongside the existing values)

