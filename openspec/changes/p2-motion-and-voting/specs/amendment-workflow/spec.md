## ADDED Requirements

### REQ-AMD-001: Submit an amendment to a motion
The app SHALL allow Participants to submit an Amendment linked to a Motion in `debating` or `voting` lifecycle.

#### Scenario: User submits an amendment
- **GIVEN** a Motion is in lifecycle `debating`
- **WHEN** a Participant clicks "Submit Amendment" on the motion detail page
- **THEN** a `CnFormDialog` opens with fields for title, text, and proposer

#### Scenario: Amendment is linked to the motion
- **GIVEN** the amendment form is completed
- **WHEN** the user clicks save
- **THEN** a new Amendment object is created with `lifecycle: submitted`, `submittedAt` set to now, and an OpenRegister relation to the parent Motion

#### Scenario: Amendment submission on adopted motion is blocked
- **GIVEN** a Motion is in lifecycle `adopted`, `rejected`, or `withdrawn`
- **WHEN** a user opens the motion detail page
- **THEN** the "Submit Amendment" button is absent

### REQ-AMD-002: Amendment lifecycle management
The app SHALL track Amendment lifecycle through the same states as Motion (submitted, debating, voting, adopted, rejected).

#### Scenario: Amendment lifecycle advances to debating
- **GIVEN** an Amendment is in `submitted` lifecycle
- **WHEN** the chair user clicks "Start Debate" on the amendment
- **THEN** the Amendment lifecycle is updated to `debating`

#### Scenario: Amendment goes to vote
- **GIVEN** an Amendment is in `debating` lifecycle
- **WHEN** the chair user clicks "Vote on Amendment"
- **THEN** the Amendment lifecycle is updated to `voting` and a new VotingRound is created linked to this Amendment

#### Scenario: Adopted amendment merges into motion
- **GIVEN** a VotingRound on an Amendment closes with `result: adopted`
- **WHEN** the close action completes
- **THEN** the Amendment lifecycle is updated to `adopted` and a note is added to the parent Motion indicating the amendment was incorporated

### REQ-AMD-003: Detect conflicting amendments
The app SHALL warn the griffier when multiple amendments target the same text passage in a Motion.

#### Scenario: Conflict detected on amendment submission
- **GIVEN** a Motion already has one or more Amendment objects
- **WHEN** a new Amendment is submitted with `text` that shares a substring (>20 characters) with an existing Amendment's `text`
- **THEN** a warning dialog is shown: "Mogelijk tegenstrijdig amendement: [title of existing amendment]. Neem contact op met de griffier."

#### Scenario: Griffier can override and proceed
- **GIVEN** the conflict warning dialog is shown
- **WHEN** the griffier clicks "Toch indienen"
- **THEN** the Amendment is saved and the warning is dismissed

#### Scenario: Non-conflicting amendments proceed without warning
- **GIVEN** a Motion has existing amendments
- **WHEN** a new Amendment is submitted whose text shares no substring >20 characters with existing amendments
- **THEN** the Amendment is saved without a conflict warning

### REQ-AMD-004: View amendments list
The app SHALL display all Amendments in a paginated list, accessible both from the Motion detail and as a standalone page.

#### Scenario: Amendments shown on motion detail
- **GIVEN** the user is on a Motion detail page
- **WHEN** the page loads
- **THEN** a `CnDetailCard` section lists all linked Amendments with columns: title, proposer, lifecycle, submittedAt

#### Scenario: Standalone amendments list
- **GIVEN** the user navigates to `/amendments`
- **WHEN** the page loads
- **THEN** `CnIndexPage` shows all Amendment objects with columns: title, proposer, lifecycle, submittedAt; the parent Motion title is shown via relation

### REQ-AMD-005: View amendment detail
The app SHALL display a detail view for a single Amendment.

#### Scenario: User opens amendment detail
- **GIVEN** the user clicks an Amendment from the motion detail or amendments list
- **WHEN** the router navigates to `/amendments/:id`
- **THEN** `CnDetailPage` renders the amendment's title, text, proposer, lifecycle, submittedAt, and any linked VotingRound

#### Scenario: Amendment detail shows parent motion link
- **GIVEN** the amendment detail page loads
- **WHEN** the parent motion relation is resolved
- **THEN** the parent Motion title is displayed as a clickable link to the Motion detail page
