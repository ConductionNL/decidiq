---
status: done
---

# Specs: Motion and Voting — Core T1

**Change:** p2-motion-and-voting-core-t1
**App:** Decidesk
**Entities:** Motion, Amendment, Vote, VotingRound

---

## Purpose

This spec defines motion management, amendments, voting rounds, vote casting, proxy voting, and voting results transparency for Decidesk.

# Requirements

## REQ-MOT: Motion Management

The system SHALL satisfy the REQ-MOT (Motion Management) requirements specified below.

### REQ-MOT-001 — Submit a motion against a decision-type agenda item
A Participant with role `member`, `chair`, or `secretary` can submit a formal Motion linked to a `decision`-type AgendaItem.

**GIVEN** a Meeting in lifecycle `opened` with a `decision`-type AgendaItem
**WHEN** the member clicks "Motie indienen" and submits `title`, `text`, and `motionType`
**THEN** a Motion is saved with `lifecycle: "submitted"`, `proposer` set to the user's display name, `submittedAt` set to the current timestamp, and linked to the AgendaItem via OpenRegister relation
**AND** the user is redirected to the MotionDetail page

**GIVEN** a user with role `observer`
**WHEN** the user opens an AgendaItem detail page
**THEN** the "Motie indienen" action is not visible

### REQ-MOT-002 — Motion lifecycle transitions are role-enforced
The app enforces that only the chair or secretary can advance a Motion's lifecycle. A proposer may withdraw their own Motion before voting starts.

**GIVEN** a Motion with `lifecycle: "submitted"`
**WHEN** the chair clicks "Debat openen"
**THEN** `MotionService::transitionLifecycle()` is called, the `lifecycle` transitions to `"debating"`, and an Activity entry is logged with actor, action, and timestamp

**GIVEN** a Motion with `lifecycle: "debating"` and the current user is the proposer
**WHEN** the proposer clicks "Motie intrekken"
**THEN** the `lifecycle` transitions to `"withdrawn"` and the change is logged in the Activity stream

**GIVEN** a Motion with `lifecycle: "voting"`
**WHEN** the proposer clicks "Motie intrekken"
**THEN** the system returns an error "Motie kan niet worden ingetrokken tijdens een stemronde" and the lifecycle remains unchanged

### REQ-MOT-003 — Motion lifecycle is visualised as a timeline
The app displays the Motion lifecycle as a `CnTimelineStages` component.

**GIVEN** a Motion with `lifecycle: "debating"`
**WHEN** the user opens the MotionDetail page
**THEN** `CnTimelineStages` shows "Debat" as the active stage with "Ingediend" marked complete

**GIVEN** a Motion with `lifecycle: "withdrawn"`
**WHEN** the user opens the MotionDetail page
**THEN** the timeline shows "Ingetrokken" with a warning-coloured badge (Nextcloud CSS variable — no hardcoded colour) and the voting section is hidden

### REQ-MOT-004 — Digital co-signatory collection
The motion proposer can invite other Participants to co-sign. Confirmations update the `coSigners` array.

**GIVEN** a Motion with `lifecycle: "submitted"` and the current user is the proposer
**WHEN** the proposer selects two Participants and clicks "Medeondertekenaars uitnodigen"
**THEN** `MotionService::requestCoSignature()` sends a Nextcloud notification to each selected Participant with a confirmation link

**GIVEN** a Participant who received a co-signature invitation
**WHEN** the Participant clicks "Ondersteunen" in the notification or on the MotionDetail page
**THEN** `MotionService::addCoSigner()` appends the Participant's `displayName` to `Motion.coSigners` (idempotent) and the updated Motion is saved

**GIVEN** a Motion with `coSigners: ["M. de Vries", "F. el-Amrani"]`
**WHEN** any user views the MotionDetail page
**THEN** the co-signers are listed below the proposer name in the motion header

### REQ-MOT-005 — Budget impact data on amendment-type motions
A proposer of a `motionType: "amendment"` Motion can attach budget impact data visible to financial controllers.

**GIVEN** a Motion with `motionType: "amendment"`
**WHEN** the proposer toggles "Budget impact toevoegen" and enters `budgetLine`, `amountDelta`, and `rationale`
**THEN** `MotionService::saveBudgetImpact()` creates or updates a note on the Motion with `title: "Budget impact"` and JSON body containing the entered values

**GIVEN** a Motion with a budget impact note
**WHEN** any user opens the MotionDetail page
**THEN** a "Budget impact" panel is displayed showing the budget line, amount delta (formatted in euros), and policy rationale

### REQ-MOT-006 — Motion index with filters
The app provides a Motion index view with filtering by `lifecycle` and `motionType`.

**GIVEN** a Meeting with Motions in various lifecycle states
**WHEN** the user selects "Ingediend" in the lifecycle filter on the Motion index
**THEN** only Motions with `lifecycle: "submitted"` are displayed, with pagination if needed

**GIVEN** a Motion index with motions of different types
**WHEN** the user selects "Amendement" in the motionType filter
**THEN** only Motions with `motionType: "amendment"` are displayed

---

## REQ-AMD: Amendment Workflow

The system SHALL satisfy the REQ-AMD (Amendment Workflow) requirements specified below.

### REQ-AMD-001 — Submit an amendment against an existing motion
A Participant with role `member`, `chair`, or `secretary` can submit an Amendment against a Motion in `submitted` or `debating` lifecycle.

**GIVEN** a Motion with `lifecycle: "submitted"` or `lifecycle: "debating"`
**WHEN** a member clicks "Amendement indienen" and submits `title`, `text`, and `proposer`
**THEN** an Amendment is saved with `lifecycle: "submitted"` and `submittedAt` set to the current timestamp
**AND** the Amendment is linked to the Motion via OpenRegister relation

### REQ-AMD-002 — Amendment follows its own lifecycle parallel to the parent motion
An Amendment follows the same lifecycle states as a Motion: `submitted` → `debating` → `voting` → `adopted` / `rejected`.

**GIVEN** an Amendment with `lifecycle: "submitted"`
**WHEN** the chair clicks "Debat openen" on the AmendmentDetail page
**THEN** the Amendment `lifecycle` transitions to `"debating"`
**AND** an Activity entry is logged

**GIVEN** an Amendment with `lifecycle: "debating"`
**WHEN** the chair opens a VotingRound for the amendment
**THEN** the Amendment `lifecycle` transitions to `"voting"`

### REQ-AMD-003 — Conflict detection notifies the secretary
When two amendments modify overlapping text passages on the same Motion, the secretary is notified.

**GIVEN** an Amendment A with `lifecycle: "submitted"` on Motion M
**WHEN** a second Amendment B is submitted on the same Motion M and `MotionService::detectConflicts()` finds text overlap with Amendment A
**THEN** a Nextcloud notification is sent to all Participants with role `secretary` on the GovernanceBody: "Mogelijk conflict gedetecteerd tussen amendement A en amendement B — controleer de tekst"
**AND** a warning banner is displayed on both AmendmentDetail pages

**GIVEN** two amendments with no overlapping text
**WHEN** `MotionService::detectConflicts()` runs
**THEN** no notification is sent

### REQ-AMD-004 — Amendment notification on state change
Relevant Participants receive notifications when an Amendment's lifecycle changes.

**GIVEN** an Amendment with `lifecycle: "submitted"`
**WHEN** the chair transitions the lifecycle to `"debating"`
**THEN** the amendment proposer receives a Nextcloud notification "Uw amendement is in behandeling genomen"

**GIVEN** an Amendment with `lifecycle: "voting"`
**WHEN** the VotingRound for the amendment closes with a result
**THEN** the amendment proposer receives a notification with the adopted or rejected outcome

### REQ-AMD-005 — View all amendments for a motion
All amendments for a motion are listed on the MotionDetail page.

**GIVEN** a Motion with 3 Amendments in various lifecycle states
**WHEN** the user opens the MotionDetail page
**THEN** an "Amendementen" section shows all 3 amendments with: title, proposer, lifecycle badge, and a link to each AmendmentDetail page
**AND** the count "3 amendementen" is shown as a badge

---

## REQ-VRM: Voting Round Management

The system SHALL satisfy the REQ-VRM (Voting Round Management) requirements specified below.

### REQ-VRM-001 — Open a voting round per motion or amendment
The chair can open a VotingRound for any Motion or Amendment in `debating` lifecycle.

**GIVEN** a Motion with `lifecycle: "debating"` and quorum is met
**WHEN** the chair opens the "Stemronde openen" dialog, configures `votingMethod` and `isSecret`, and confirms
**THEN** a VotingRound is created with `openedAt` set to the current timestamp
**AND** the Motion `lifecycle` transitions to `"voting"`
**AND** all active Participants in the GovernanceBody receive a Nextcloud notification

### REQ-VRM-002 — Quorum is enforced before opening a voting round
A VotingRound cannot be opened if quorum is not met.

**GIVEN** a Meeting with `quorumRequired: 20` and only 15 active Participants present
**WHEN** the chair attempts to open a VotingRound
**THEN** `VotingService::checkQuorum()` returns false
**AND** the system returns a `400 Bad Request` response with message "Quorum niet bereikt (15 van 20 aanwezig)"
**AND** the VotingRound is not created

**GIVEN** a Meeting where quorum is met
**WHEN** the chair opens a VotingRound
**THEN** the VotingRound is created with `quorumMet: true`

### REQ-VRM-003 — Configure voting method and secrecy
The chair can configure the voting method and whether the ballot is secret when opening a round.

**GIVEN** the "Stemronde openen" dialog is open
**WHEN** the chair selects `votingMethod: "show-of-hands"` and `isSecret: false`
**THEN** the VotingRound is created with those values
**AND** individual vote buttons are hidden in favour of manual count entry fields

**GIVEN** a VotingRound with `isSecret: true`
**WHEN** the round closes and results are displayed
**THEN** per-Participant vote breakdown is hidden and only aggregate totals (votesFor, votesAgainst, votesAbstain) are shown

### REQ-VRM-004 — Voting schedule configuration with calendar event
The chair can set a voting deadline when opening a round; a calendar event is created automatically.

**GIVEN** the "Stemronde openen" dialog is open
**WHEN** the chair sets a `closedAt` datetime and confirms
**THEN** `CalendarEventService` creates a calendar event "Stemronde sluit: [Motion title]" for the configured deadline
**AND** all Participants with a calendar integration receive the event

### REQ-VRM-005 — Close a voting round and tally results
The chair can close an open VotingRound; results are calculated automatically.

**GIVEN** an open VotingRound with votes cast
**WHEN** the chair clicks "Stemronde sluiten" and confirms
**THEN** `VotingService::tallyResults()` counts all Vote objects grouped by `value`
**AND** the VotingRound is updated with `votesFor`, `votesAgainst`, `votesAbstain`, `closedAt`, and `result` (`adopted` / `rejected` / `tied` / `invalid`)
**AND** the parent Motion `lifecycle` transitions to `adopted`, `rejected`, or `tied` accordingly
**AND** the result is immediately displayed in the `VotingRoundPanel`

**GIVEN** a VotingRound where `votesFor` equals `votesAgainst`
**WHEN** the round is closed
**THEN** `result` is set to `"tied"` and the Motion `lifecycle` remains `"voting"` pending chair decision

---

## REQ-VCT: Vote Casting

The system SHALL satisfy the REQ-VCT (Vote Casting) requirements specified below.

### REQ-VCT-001 — Cast a vote in an open voting round
An active Participant can cast a vote (voor / tegen / onthouding) in an open VotingRound.

**GIVEN** an open VotingRound and the current user is an active Participant (role: member, chair, vice-chair, or secretary)
**WHEN** the Participant clicks "Voor", "Tegen", or "Onthouding" in the VotingRoundPanel
**THEN** a Vote is created with `value` matching the selection, `castAt` set to the current timestamp, `isProxy: false`, and linked to the VotingRound and Participant
**AND** a confirmation message "Uw stem is geregistreerd" is shown

**GIVEN** a Participant who already cast a vote in the round
**WHEN** the Participant casts a different vote before the round closes
**THEN** the existing Vote is overwritten (not duplicated) and the tally reflects the new value

### REQ-VCT-002 — Observers and guests cannot vote
Users with role `observer` or `guest` cannot cast votes.

**GIVEN** a user with role `observer` or `guest`
**WHEN** a VotingRound is open
**THEN** the vote buttons (Voor / Tegen / Onthouding) are not shown to that user

### REQ-VCT-003 — Remote vote casting via email reply
Remote Participants can cast votes by replying to the voting invitation email.

**GIVEN** a VotingRound is opened and a remote Participant has an email address on record
**WHEN** `VotingService::openVotingRound()` runs
**THEN** `NotificationService` sends a voting invitation email to the Participant with subject "Stemronde geopend: [Motion title]" and body containing instructions to reply with "Voor", "Tegen", or "Onthouding"

**GIVEN** a remote Participant replies with "Voor" to the voting invitation
**WHEN** `MailReplyHandler` processes the reply
**THEN** `VotingService::castVote()` registers the vote with `isProxy: false`
**AND** a confirmation email "Uw stem (Voor) is ontvangen en geregistreerd" is sent to the Participant

**GIVEN** a Participant replies with an unrecognised word
**WHEN** `MailReplyHandler` processes the reply
**THEN** a re-prompt email is sent; after 3 failed attempts the email vote path is exhausted and a final notification is sent directing the Participant to vote via the UI

### REQ-VCT-004 — Show-of-hands data entry
For in-person meetings using the `show-of-hands` voting method, the chair can manually enter totals.

**GIVEN** a VotingRound with `votingMethod: "show-of-hands"` is open
**WHEN** the chair enters the counts in the "Voor", "Tegen", and "Onthouding" number fields
**THEN** `ObjectService.saveObject()` updates the VotingRound with the entered totals
**AND** individual vote buttons are hidden for all Participants

### REQ-VCT-005 — Vote casting is keyboard-accessible and WCAG AA compliant
All vote casting controls are fully keyboard-navigable and meet WCAG 2.1 AA.

**GIVEN** the VotingRoundPanel is visible with an open round
**WHEN** the user navigates using only keyboard (Tab, Enter/Space)
**THEN** each vote button (Voor, Tegen, Onthouding) is reachable and activatable via keyboard
**AND** the currently focused button has a visible focus indicator using `--color-primary-element-hover` (Nextcloud CSS variable)
**AND** ARIA labels are present on all interactive controls
**AND** colour is not the sole indicator of vote selection state

---

## REQ-PRX: Proxy Voting

The system SHALL satisfy the REQ-PRX (Proxy Voting) requirements specified below.

### REQ-PRX-001 — Delegate voting right to another Participant
An active Participant can delegate their voting right for a specific VotingRound to another active Participant.

**GIVEN** a VotingRound that has not yet been opened
**WHEN** an active member uses the "Volmacht verlenen" action and selects another active member as delegate
**THEN** `VotingService::grantProxy()` creates an OpenRegister relation from the delegate's Vote → Participant (delegator)
**AND** the delegate receives a Nextcloud notification "U heeft een volmacht ontvangen van [delegator name]"

**GIVEN** a user who attempts to delegate to a Participant with role `observer` or `guest`
**WHEN** the proxy grant is submitted
**THEN** the system returns an error "Volmacht kan niet worden verleend aan een waarnemer of gast"

### REQ-PRX-002 — One proxy per Participant per round is enforced
A Participant may hold at most one proxy per VotingRound.

**GIVEN** Participant A has already delegated to Participant B for VotingRound R
**WHEN** Participant A attempts to delegate to Participant C for the same round
**THEN** the system returns an error "U heeft al een volmacht verleend voor deze stemronde"
**AND** the existing delegation to Participant B is not modified

### REQ-PRX-003 — Revoke proxy before the round opens
A delegating Participant can cancel their proxy up until the VotingRound is opened.

**GIVEN** a Participant has an active proxy delegation and the VotingRound is not yet open
**WHEN** the Participant clicks "Volmacht intrekken"
**THEN** `VotingService::revokeProxy()` removes the proxy relation
**AND** the delegate receives a notification "De volmacht van [delegator name] is ingetrokken"

**GIVEN** a Participant attempts to revoke a proxy after the VotingRound has been opened
**WHEN** the revocation is submitted
**THEN** the system returns an error "Volmacht kan niet worden ingetrokken nadat de stemronde is geopend"

### REQ-PRX-004 — Proxy vote is cast and flagged on behalf of delegator
When a delegate casts their vote in an open round, the proxy vote is cast simultaneously and flagged.

**GIVEN** a delegate holds an active proxy from Participant A for an open VotingRound
**WHEN** the delegate casts their vote (e.g., "Voor")
**THEN** a second Vote is created with `isProxy: true`, `value: "for"`, and an OpenRegister relation `delegator` → Participant A
**AND** the vote tally counts both votes (delegate's own vote and the proxy vote)
**AND** the delegate's VotingRoundPanel shows "U stemt namens: [Participant A]" above the vote buttons

---

## REQ-RES: Voting Results Transparency

The system SHALL satisfy the REQ-RES (Voting Results Transparency) requirements specified below.

### REQ-RES-001 — Results are displayed immediately after round close
Vote results are visible to all relevant users as soon as the round closes.

**GIVEN** a VotingRound is closed with result `adopted`
**WHEN** any user opens the VotingRoundPanel for that round
**THEN** the result badge (Aangenomen / Verworpen / Gelijk / Ongeldig) is shown in the appropriate colour (using Nextcloud CSS variables — no hardcoded colours)
**AND** the vote totals (votesFor, votesAgainst, votesAbstain) are displayed
**AND** the majority threshold (e.g., "23 van 32 — eenvoudige meerderheid behaald") is shown

### REQ-RES-002 — Per-Participant vote breakdown for non-secret rounds
For rounds where `isSecret: false`, the individual vote of each Participant is shown.

**GIVEN** a closed VotingRound with `isSecret: false`
**WHEN** any user opens the result view
**THEN** a table shows each Participant's name, their `party` (if set), and their vote value (Voor / Tegen / Onthouding)
**AND** proxy votes are flagged with "(volmacht voor [delegator name])"

### REQ-RES-003 — Per-faction vote aggregation
For councils with party affiliation, vote totals are aggregated per party.

**GIVEN** a closed VotingRound where Participants have `party` values set
**WHEN** the result view is opened
**THEN** a "Fractiestemmen" section shows each party with their aggregate Voor / Tegen / Onthouding counts

### REQ-RES-004 — Publish voting results to ORI API
An adopted VotingRound result can be published to the ORI API endpoint.

**GIVEN** a closed VotingRound with `result: "adopted"` and an ORI endpoint configured in app settings
**WHEN** the chair clicks "Publiceren naar ORI"
**THEN** `OriPublicationService::publish()` sends the result as JSON-LD to the configured ORI endpoint
**AND** the UI shows "Gepubliceerd" with the publication timestamp

**GIVEN** the ORI endpoint is not configured in app settings
**WHEN** the publish action is triggered
**THEN** `OriPublicationService` returns silently without throwing an exception
**AND** the "Publiceren naar ORI" button is hidden from the UI

### REQ-RES-005 — Vote history is accessible per motion
All past VotingRounds for a Motion are listed with their results.

**GIVEN** a Motion with multiple VotingRounds (e.g., an amendment that was revised and re-voted)
**WHEN** the user opens the MotionDetail page
**THEN** all VotingRounds are listed in chronological order with: openedAt, closedAt, votingMethod, result, and a link to the detailed result view

---

## REQ-DASH: Dashboard Extensions

The system SHALL satisfy the REQ-DASH (Dashboard Extensions) requirements specified below.

### REQ-DASH-001 — Dashboard KPI: open motions count
**GIVEN** the Dashboard page is open
**WHEN** the dashboard KPI widgets load
**THEN** an "Open moties" KPI card shows the count of Motions with `lifecycle: "submitted"` or `lifecycle: "debating"`

### REQ-DASH-002 — Dashboard KPI: active voting rounds
**GIVEN** the Dashboard page is open
**WHEN** the dashboard KPI widgets load
**THEN** an "Actieve stemrondes" KPI card shows the count of VotingRounds where `openedAt` is set and `closedAt` is null

---

## Non-Functional Requirements

The implementation MUST satisfy the non-functional requirements (REQ-NFR) specified below.

### REQ-NFR-001 — Accessibility (ADR-010)
All new views and dialogs MUST meet WCAG 2.1 AA: keyboard-navigable, form fields labelled, colour not the sole status conveyor, alt text on status icons. Vote buttons in particular must be keyboard-accessible and ARIA-labelled.

### REQ-NFR-002 — Internationalisation (ADR-007)
All user-visible strings in new views MUST use `t(appName, 'text')`. Dutch (nl) and English (en) translations MUST be provided for all new strings including: motion lifecycle labels, voting method labels, quorum error messages, co-signatory dialog labels, proxy delegation labels, ORI publication labels, email vote keywords, and result display labels.

### REQ-NFR-003 — Audit trail (ADR-001)
All lifecycle transitions on Motion and Amendment, all VotingRound open and close events, all Vote cast events, all proxy grant/revoke events, and all ORI publication events MUST produce an audit trail entry via the OpenRegister built-in `AuditTrailService`.

### REQ-NFR-004 — No hardcoded colours (ADR-004 / ADR-010)
All status indicators (lifecycle badge, result badge, overdue proxy warning) MUST use Nextcloud CSS variables. No hardcoded hex values anywhere in component styles.

### REQ-NFR-005 — Spec traceability (ADR-003)
Every new PHP class and public method introduced by this change MUST carry a `@spec openspec/changes/p2-motion-and-voting-core-t1/tasks.md#task-N` PHPDoc tag.

### REQ-NFR-006 — Security: voting operations require backend auth (ADR-005)
All vote-related endpoints (cast, proxy grant/revoke, round open/close) MUST verify the caller's Nextcloud identity and role on the backend. Frontend-only role checks are not sufficient. No vote data is returned to unauthenticated callers.
