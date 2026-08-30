# urgent-decision-procedure Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- urgent-decision-procedure

## Purpose

Lets an authorised actor run a decision through an urgent/expedited procedure (spoedprocedure): a guarded urgency declaration with recorded justification, an expedited route variant (emergency meeting with shortened convocation, or an expedited written round with an hours-based response deadline), and a mandatory auto-appended ratification (bekrachtiging) stage on the ratifying body's next regular meeting. The urgent decision is provisionally effective until ratification confirms or reverses it. Reuses the existing route/stage machinery (decision-route, decision-methods), the BW 2:40 written-resolution path, and the convocation notice computation — no parallel system (ADR-005, ADR-006, ADR-031).

**Standards**: Schema.org (`ChooseAction`), Gemeentewet (college spoedbesluiten bekrachtigd door de raad), BW 2:40 (written resolutions), BW 2:8/statuten (corporate/association urgent-resolution clauses)

## ADDED Requirements

### Requirement: REQ-001 Guarded urgency declaration

The system SHALL allow an authorised actor to declare a `Decision` urgent by setting `isUrgent=true` together with a mandatory `urgencyReason`; the system SHALL stamp `urgencyDeclaredBy` (the acting user) and `urgencyDeclaredAt` (date-time). The trigger SHALL be authorised only for the resolved meeting chair or a role listed in the governing body's `urgencyPolicy.allowedTriggerRoles` (process-configuration); when no `urgencyPolicy` is configured for the body, or when the actor's role cannot be resolved, the trigger MUST be rejected (fail closed — same posture as existing chair-only transitions). A declaration without a non-empty `urgencyReason` MUST be rejected. Every urgency declaration MUST be recorded in the decision's immutable audit trail with actor, timestamp, and reason. The urgency flag SHALL be orthogonal to the decision `lifecycle`, `outcome`, and `isPublished` — declaring urgency SHALL NOT change any lifecycle state and SHALL NOT bypass any existing lifecycle, quorum, or chair guard.

#### Scenario: Chair declares a college spoedbesluit

- GIVEN a Decision "Noodopvang statushouders" in lifecycle `proposed` for a body whose `urgencyPolicy.allowedTriggerRoles` includes `chair`
- WHEN the resolved chair declares it urgent with reason "Acute opvangcrisis; eerstvolgende raadsvergadering is pas over drie weken"
- THEN `isUrgent` is true, `urgencyReason`, `urgencyDeclaredBy`, and `urgencyDeclaredAt` are stored
- AND the audit trail records the declaration with actor, timestamp, and reason
- AND the decision's lifecycle state is unchanged

#### Scenario: Unauthorised actor is rejected

- GIVEN a Decision for a body whose `urgencyPolicy.allowedTriggerRoles` is `["chair"]`
- WHEN an authenticated user who is not the resolved chair attempts the urgency declaration
- THEN the request is rejected with an authorization error
- AND `isUrgent` remains false and no audit entry is created

#### Scenario: Body without urgency policy fails closed

- GIVEN a Decision for a body whose process template has no `urgencyPolicy`
- WHEN any user (including the chair) attempts the urgency declaration
- THEN the request is rejected indicating the urgent procedure is not configured for this body

#### Scenario: Declaration without justification is rejected

- GIVEN an authorised chair on an eligible decision
- WHEN they submit the urgency declaration with an empty `urgencyReason`
- THEN the request is rejected with a validation error naming `urgencyReason` as required

### Requirement: REQ-002 Expedited variant A — emergency meeting with shortened convocation

The system SHALL support scheduling an emergency meeting for an urgent decision by reusing the existing Meeting machinery with `meetingType=extraordinary`: the convocation flow (meeting-management) SHALL permit sending the notice below the body's regular notice period, provided the actual notice is not below the body's `urgencyPolicy.minimumNoticeFloorHours`, and MUST record the deviation on the meeting (`shortenedNotice=true`, the actual notice given, and a deviation reason — see the meeting-management delta). The emergency meeting SHALL run the unchanged meeting lifecycle and quorum rules (meeting-workflow); no quorum rule is relaxed by this capability.

#### Scenario: Emergency meeting convened below the regular notice period

- GIVEN an urgent Decision for an association board whose regular notice period is 15 days and whose `urgencyPolicy.minimumNoticeFloorHours` is 48
- WHEN the secretary schedules an `extraordinary` meeting 3 days out and sends the convocation
- THEN the convocation is sent, the meeting records `shortenedNotice=true` with the actual notice and the deviation reason
- AND the meeting still enforces its domain's quorum rules when opened

#### Scenario: Notice below the configured floor is rejected

- GIVEN a body whose `urgencyPolicy.minimumNoticeFloorHours` is 48
- WHEN the secretary attempts to send an emergency convocation for a meeting starting in 12 hours
- THEN the send is rejected naming the configured 48-hour floor
- AND no notice deliveries are recorded

### Requirement: REQ-003 Expedited variant B — written round with an hours-based response deadline

The system SHALL support an expedited written round for an urgent decision by reusing the existing BW 2:40 written-resolution path: a `VotingRound` linked to the decision's decisive stage (`method=vote`) whose `votingDeadline` is set to a response deadline expressed in hours from opening. The chosen deadline MUST lie within the body's `urgencyPolicy.responseDeadlineHours` bounds (`min`/`max`); values outside the bounds MUST be rejected. Outcome derivation, quorum-on-round semantics, and the existing deadline-reminder notification SHALL apply unchanged from the voting-system and decision-methods capabilities.

#### Scenario: RvB opens an expedited written round

- GIVEN an urgent corporate Decision whose body's `urgencyPolicy.responseDeadlineHours` is `{min: 4, max: 72}`
- WHEN the chair opens a written round with a 24-hour response deadline
- THEN a VotingRound is created with `votingDeadline` 24 hours from opening, linked to the decision's decisive stage
- AND the stage outcome derives from the round result exactly as for a regular written resolution

#### Scenario: Deadline outside the configured bounds is rejected

- GIVEN a body whose `urgencyPolicy.responseDeadlineHours` is `{min: 4, max: 72}`
- WHEN the chair attempts to open an expedited round with a 1-hour deadline
- THEN the request is rejected naming the configured bounds
- AND no VotingRound is created

### Requirement: REQ-004 Mandatory ratification stage is auto-appended

When a decision is declared urgent and the body's `urgencyPolicy.ratificationRequired` is true (default), the system SHALL auto-append a `DecisionStage` with `stageType=ratifying` to the decision's route, assigned (`decisionMakerType=body`) to the configured ratifying body (`urgencyPolicy.ratifyingBody`, overridable at trigger time by the same authorised actor). The appended stage SHALL be an ordinary route stage: sequenced after the existing final stage, driven by the unchanged declarative DecisionStage lifecycle, and counted by the unchanged route-progress calculations (decision-route). The system SHALL place the ratification on the agenda of the next regular (`meetingType=regular`) scheduled meeting of the ratifying body by creating a linked AgendaItem; when no such meeting is scheduled yet, the system SHALL record the pending placement and surface it as an actionable warning on the decision detail, retrying placement when a qualifying meeting is created.

#### Scenario: Ratifying stage appended to a college spoedbesluit

- GIVEN an urgent municipal Decision whose route ends at a `decisive` college stage, with `urgencyPolicy.ratifyingBody` = "Gemeenteraad"
- WHEN the urgency declaration completes
- THEN a `stageType=ratifying` DecisionStage assigned to the Gemeenteraad is appended with the next `sequence`
- AND the decision's `stageCount` increases by one and `routeComplete` is false until the stage is decided

#### Scenario: Ratification placed on the next regular meeting

- GIVEN the ratifying body has a `regular` meeting scheduled 12 days out
- WHEN the ratifying stage is appended
- THEN an AgendaItem linked to the urgent decision is created on that meeting
- AND the decision detail shows where and when ratification is scheduled

#### Scenario: No regular meeting scheduled yet

- GIVEN the ratifying body has no future `regular` meeting scheduled
- WHEN the ratifying stage is appended
- THEN the placement is recorded as pending and the decision detail shows a warning that no ratifying meeting exists yet
- AND when a `regular` meeting for that body is later created, the AgendaItem is placed on it

### Requirement: REQ-005 Ratification outcome confirms or reverses

An urgent decision SHALL be provisionally effective from the moment its decisive stage is decided, pending ratification. When the ratifying stage is decided with `outcome=adopted`, the decision SHALL present as ratified (no longer awaiting ratification) with no further change. When the ratifying stage is decided with `outcome=rejected`, the system SHALL guide the ratifying body to record the reversal using the existing decision-evolution semantics: a reversing decision carrying a `repeals` (or `supersedes`, at the body's choice) relation to the urgent decision, so the urgent decision's derived `effectiveStatus` becomes `repealed`/`superseded` through the unchanged decision-management derivation. The urgent decision's own lifecycle and audit trail SHALL remain append-only and unchanged by the derivation.

#### Scenario: Raad ratifies the spoedbesluit

- GIVEN an urgent decision whose ratifying stage (Gemeenteraad) is `active` on the agenda of a regular raadsvergadering
- WHEN the raad decides the stage with `outcome=adopted`
- THEN the stage is `decided`, `routeComplete` becomes true, and the decision no longer presents as awaiting ratification

#### Scenario: Ratification refused and decision reversed

- GIVEN an urgent decision whose ratifying stage is decided with `outcome=rejected`
- WHEN the ratifying body records the reversal decision with a `repeals` relation to the urgent decision
- THEN the urgent decision's `effectiveStatus` derives to `repealed` while its lifecycle status and audit trail remain unchanged
- AND both decisions' audit trails record the relation

### Requirement: REQ-006 Awaiting-ratification indicators and dashboard KPI

The system SHALL derive `awaitingRatification` on `Decision` declaratively (ADR-031): true when `isUrgent` is true and the route contains a `stageType=ratifying` stage whose `status` is not terminal. The decision detail view MUST show a prominent "urgent — awaiting ratification" banner (alongside, never replacing, the lifecycle badge — mirroring the effective-status banner pattern), the decision list MUST offer an urgency badge and an `awaitingRatification` filter, and the dashboard MUST show a KPI card counting decisions with `awaitingRatification=true`, navigating to the pre-filtered decision list.

#### Scenario: Detail banner while ratification is pending

- GIVEN an urgent decision whose ratifying stage is `pending`
- WHEN a user opens its detail view
- THEN a prominent "urgent — awaiting ratification" banner is shown naming the ratifying body, and the lifecycle badge remains visible

#### Scenario: Dashboard KPI counts and navigates

- GIVEN three decisions with `awaitingRatification=true` and five ratified urgent decisions
- WHEN the user opens the dashboard
- THEN the "Awaiting ratification" KPI shows 3
- AND activating it opens the decision list pre-filtered to `awaitingRatification=true`

#### Scenario: Indicator clears on ratification

- GIVEN an urgent decision whose ratifying stage becomes `decided` with `outcome=adopted`
- WHEN the list and detail views are reloaded
- THEN `awaitingRatification` is false and the banner and badge are no longer shown

## Non-Functional Requirements

- **Performance:** `awaitingRatification` is a materialised declarative derivation — list/dashboard consumers read it; no per-row route queries (no N+1).
- **Accessibility:** the urgency banner/badge MUST NOT rely on colour alone (icon + text label), WCAG 2.1 AA contrast via NL Design CSS variables.
- **Internationalization:** Dutch and English MUST be supported (ADR-005); notification subjects carry `{nl,en}` variants.

## Acceptance Criteria

- [ ] Urgency declaration is guarded (chair/configured role, fail closed), justified, audited, and lifecycle-orthogonal
- [ ] Emergency meeting reuses `meetingType=extraordinary` + convocation flow; deviation recorded; floor enforced
- [ ] Expedited written round reuses `VotingRound.votingDeadline` with per-body hour bounds enforced
- [ ] Ratifying stage auto-appended as an ordinary route stage; agenda placement on next regular meeting, with pending-placement fallback
- [ ] Rejected ratification reverses via existing `repeals`/`supersedes` + derived `effectiveStatus`
- [ ] Banner, list badge/filter, and dashboard KPI driven by declarative `awaitingRatification`

## Notes

- Per-body configuration (`urgencyPolicy`) is owned by the process-configuration delta; notification rules by the decidesk-notifications delta; Decision schema fields by the decision-management delta; shortened-notice recording by the meeting-management delta.
- ADR-031 imperative exceptions (trigger guard, ratification orchestration) are justified in design.md.
- Quorum rules are explicitly out of scope and unchanged (proposal).
