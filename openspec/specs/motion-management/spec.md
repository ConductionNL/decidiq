# motion-management Specification

## Purpose
TBD - created by archiving change 2026-05-11-p2-motion-and-voting. Update Purpose after archive.

## Requirements

### Requirement: REQ-MOT-001 Motion can be submitted against a decision-type AgendaItem
The app SHALL allow a Participant with role `member`, `chair`, or `secretary` to create a Motion object linked to an AgendaItem of type `decision`. The Motion SHALL be given lifecycle `submitted` on creation.

#### Scenario: Raadslid submits a motion
- **GIVEN** a Meeting in lifecycle `opened` with a `decision`-type AgendaItem
- **WHEN** the raadslid clicks "Motie indienen" and fills in `title`, `text`, and `motionType`
- **THEN** a Motion is saved with `lifecycle: "submitted"`, `proposer` set to the user's display name, `submittedAt` set to the current timestamp, and linked to the AgendaItem via OpenRegister relation

#### Scenario: Observer cannot submit a motion
- **GIVEN** a user with role `observer`
- **WHEN** the user opens an AgendaItem detail page
- **THEN** the "Motie indienen" action is not visible

---

### Requirement: REQ-MOT-002 Motion lifecycle transitions are controlled by role
The app SHALL enforce that only the chair or secretary can advance a Motion's lifecycle from `submitted` → `debating` → `voting` → `adopted` / `rejected`. A proposer may withdraw their own Motion (transition to `withdrawn`) at any time before lifecycle reaches `voting`.

#### Scenario: Chair starts debate on a motion
- **GIVEN** a Motion with `lifecycle: "submitted"`
- **WHEN** the chair clicks "Debat openen"
- **THEN** `MotionService::transitionLifecycle()` is called, the Motion `lifecycle` is updated to `debating`, and an Activity entry is logged with actor, action, timestamp

#### Scenario: Proposer withdraws their motion before voting
- **GIVEN** a Motion with `lifecycle: "debating"` and the current user is the proposer
- **WHEN** the proposer clicks "Motie intrekken"
- **THEN** the Motion `lifecycle` is updated to `withdrawn` and the change is logged in the Activity stream

#### Scenario: Proposer cannot withdraw after voting starts
- **GIVEN** a Motion with `lifecycle: "voting"`
- **WHEN** the proposer clicks "Motie intrekken"
- **THEN** the system returns an error "Motie kan niet worden ingetrokken tijdens een stemronde" and the lifecycle remains unchanged

---

### Requirement: REQ-MOT-003 Motion lifecycle is displayed as a timeline
The app SHALL display the Motion lifecycle as a `CnTimelineStages` component with stages: Ingediend → Debat → Stemming → Aangenomen / Verworpen / Ingetrokken.

#### Scenario: User views motion detail
- **GIVEN** a Motion with `lifecycle: "debating"`
- **WHEN** the user opens the MotionDetail page
- **THEN** the `CnTimelineStages` component shows "Debat" as the active stage with the preceding "Ingediend" stage marked complete

#### Scenario: Withdrawn motion is shown as terminal state
- **GIVEN** a Motion with `lifecycle: "withdrawn"`
- **WHEN** the user opens the MotionDetail page
- **THEN** the timeline shows "Ingetrokken" with a warning-coloured badge and the voting section is hidden

---

### Requirement: REQ-MOT-004 Digital co-signatory collection
The app SHALL allow the motion proposer to invite other Participants to co-sign a Motion. Each invited Participant receives a Nextcloud notification. When a Participant confirms, their `displayName` is appended to the `coSigners` array.

#### Scenario: Proposer requests co-signatures
- **GIVEN** a Motion with `lifecycle: "submitted"` and the current user is the proposer
- **WHEN** the proposer clicks "Medeondertekenaars uitnodigen" and selects two Participants
- **THEN** `MotionService::requestCoSignature()` sends a Nextcloud notification to each selected Participant with a confirmation link

#### Scenario: Co-signer confirms support
- **GIVEN** a Participant who received a co-signature invitation
- **WHEN** the Participant clicks "Ondersteunen" in the notification or on the MotionDetail page
- **THEN** `MotionService::addCoSigner()` appends the Participant's `displayName` to `Motion.coSigners` and the updated Motion is saved via `ObjectService.saveObject()`

#### Scenario: Co-signers are displayed on the motion
- **GIVEN** a Motion with `coSigners: ["M. de Vries", "F. el-Amrani"]`
- **WHEN** any user views the MotionDetail page
- **THEN** the co-signers are listed below the proposer name in the motion header

---

### Requirement: REQ-MOT-005 Budget amendment motions include financial impact data
The app SHALL allow the proposer of a `motionType: "amendment"` Motion to attach budget impact data (budget line reference, amount delta, policy rationale). This data SHALL be stored as a structured note on the Motion and visible to the financial controller.

#### Scenario: Council member submits a budget amendment motion
- **GIVEN** a Motion with `motionType: "amendment"`
- **WHEN** the proposer toggles "Budget impact toevoegen" and enters `budgetLine`, `amountDelta`, and `rationale`
- **THEN** `MotionService::saveBudgetImpact()` creates a note on the Motion with `title: "Budget impact"` and a JSON body containing the entered values

#### Scenario: Financial controller sees computed impact
- **GIVEN** a Motion with a budget impact note
- **WHEN** any user opens the MotionDetail page
- **THEN** a "Budget impact" panel is displayed showing the budget line, amount delta (formatted in euros), and policy rationale

---

### Requirement: REQ-MOT-006 Motion index shows all motions for a meeting with filter
The app SHALL provide a Motion index view accessible from MeetingDetail, showing all Motions for the meeting with a filter by `lifecycle` and `motionType`.

#### Scenario: User filters motions by lifecycle
- **GIVEN** a Meeting with 10 Motions in various lifecycle states
- **WHEN** the user selects "Ingediend" in the lifecycle filter on the Motion index
- **THEN** only Motions with `lifecycle: "submitted"` are shown, with pagination if needed

#### Scenario: Chair sees lifecycle badge on each motion
- **GIVEN** a Motion index with motions in different lifecycle states
- **WHEN** the user opens the index
- **THEN** each Motion row shows a `CnStatusBadge` with the current lifecycle value
