---
status: in-progress
status-note: In progress 2026-06-14 via unify-decision-supertype (capability retired — amendments become decisionType=amendment decisions owned by decision-management per ADR-005/ADR-006).
openspec-changes:
  - unify-decision-supertype
---

# amendment-workflow Specification

## Purpose
TBD - created by archiving change 2026-05-11-p2-motion-and-voting. Update Purpose after archive.

## Requirements

### Requirement: REQ-AMD-001 Amendment is submitted against an existing Motion
The app SHALL allow a Participant with role `member`, `chair`, or `secretary` to submit an Amendment object against a Motion that is in lifecycle `submitted` or `debating`. The Amendment SHALL receive lifecycle `submitted` on creation.

#### Scenario: Raadslid submits an amendment
- **GIVEN** a Motion with `lifecycle: "debating"`
- **WHEN** the raadslid clicks "Amendement indienen" and fills in `title`, `text`, and `proposer`
- **THEN** an Amendment is saved with `lifecycle: "submitted"`, `submittedAt` set to now, and linked to the Motion via OpenRegister relation

#### Scenario: Amendment cannot be submitted on an adopted motion
- **GIVEN** a Motion with `lifecycle: "adopted"`
- **WHEN** a user tries to submit an amendment
- **THEN** the "Amendement indienen" action is disabled with tooltip "Moties die zijn aangenomen of verworpen kunnen niet meer worden geamendeerd"

---

### Requirement: REQ-AMD-002 Amendment lifecycle mirrors motion lifecycle transitions
The app SHALL allow the chair to advance an Amendment's lifecycle from `submitted` → `debating` → `voting` → `adopted` / `rejected`. Amendment lifecycle transitions are controlled by `MotionService::transitionLifecycle()` with the Amendment object as input.

#### Scenario: Chair opens debate on an amendment
- **GIVEN** an Amendment with `lifecycle: "submitted"`
- **WHEN** the chair clicks "Debat openen" on the AmendmentDetail
- **THEN** the Amendment `lifecycle` is updated to `debating` and the change is logged

#### Scenario: Amendment adoption updates parent motion
- **GIVEN** an Amendment with `lifecycle: "voting"` against a Motion
- **WHEN** the VotingRound for the Amendment closes with `result: "adopted"`
- **THEN** the Amendment `lifecycle` is set to `adopted` AND the parent Motion `text` is updated by `MotionService::applyAmendment()` to incorporate the change

---

### Requirement: REQ-AMD-003 Griffier is alerted when amendments conflict on the same text passage
The app SHALL detect when multiple submitted Amendments against the same Motion appear to target overlapping text passages and alert users with role `secretary` (griffier).

#### Scenario: Two amendments target the same date in the motion text
- **GIVEN** a Motion with text containing "1 oktober 2025"
- **WHEN** a second Amendment is submitted whose `text` also contains "1 oktober"
- **THEN** `MotionService::detectConflicts()` identifies the overlap and a Nextcloud notification is sent to all users with role `secretary`: "Mogelijke conflicten gedetecteerd tussen amendementen op [Motion title]"

#### Scenario: Non-overlapping amendments do not trigger alert
- **GIVEN** two Amendments against the same Motion targeting different clauses
- **WHEN** `MotionService::detectConflicts()` runs after the second Amendment is saved
- **THEN** no conflict notification is sent

---

### Requirement: REQ-AMD-004 Amendments are listed on the MotionDetail with their lifecycle
The app SHALL display all Amendments for a Motion in a dedicated section on the MotionDetail page, showing each Amendment's `title`, `proposer`, `lifecycle` badge, and a link to the AmendmentDetail.

#### Scenario: User views amendments on a motion
- **GIVEN** a Motion with three Amendments in different lifecycle states
- **WHEN** the user opens the MotionDetail page
- **THEN** all three Amendments are listed with title, proposer, and a `CnStatusBadge` showing lifecycle
- **AND** a count badge "3 amendementen" is shown in the section header

#### Scenario: Adopted amendment text is shown on the motion
- **GIVEN** an Amendment with `lifecycle: "adopted"`
- **WHEN** the user views the MotionDetail
- **THEN** the Motion `text` field shows the amended version with an "Geamendeerd" label below the text

---

### Requirement: REQ-AMD-005 Amendment can be voted on independently via a dedicated VotingRound
The app SHALL allow the chair to open a VotingRound for an Amendment (not only for the parent Motion). The VotingRound is linked to the Amendment via OpenRegister relation. The result of the amendment vote determines whether `MotionService::applyAmendment()` is called.

#### Scenario: Chair opens vote on an amendment
- **GIVEN** an Amendment with `lifecycle: "debating"`
- **WHEN** the chair clicks "Stemronde openen" on the AmendmentDetail
- **THEN** a VotingRound is created with relation to the Amendment, and the Amendment `lifecycle` transitions to `voting`

#### Scenario: Failed amendment vote rejects the amendment only
- **GIVEN** an Amendment VotingRound that closes with `result: "rejected"`
- **WHEN** the round closes
- **THEN** the Amendment `lifecycle` is set to `rejected` AND the parent Motion `text` remains unchanged AND the parent Motion `lifecycle` is unaffected
