# constituency-consultation Specification

**Status**: planned
**Scope**: decidiq
**OpenSpec changes**:
- [constituency-consultation](../../changes/constituency-consultation/)

## Purpose

An informal, explicitly non-binding member poll (achterbanraadpleging / ledenraadpleging): a defined member audience — a governance body's active members, a fractie, or a configured Nextcloud group such as an OR's achterban — is asked a question linked to an agenda item and/or decision, and the summarised outcome flows back into the meeting as an input artifact. It is explicitly not a formal ballot (`voting-system` / `preferential-ballot`) and not citizen input (`citizen-participation` / `portal-contribution`); it fills the member-poll space between them.

## ADDED Requirements

### Requirement: REQ-CCO-001 MemberConsultation schema on OpenRegister

The system SHALL define a `MemberConsultation` schema in the decidesk register (via the `lib/Settings/register.d/48-constituency-consultation.json` fragment per ADR-037, never by editing `decidesk_register.json`), annotated `x-schema-org: schema:AskAction`. The schema SHALL carry at minimum: `question` (required), `description` (optional), `responseType` (required enum: `single-choice | multi-choice | open-text`), `choiceOptions` (array of strings; required when `responseType` is a choice type), audience properties per REQ-CCO-002, `agendaItem` (AgendaItem reference, optional), `decision` (Decision reference, optional), `opensAt` / `closesAt` (date-time; `closesAt` required), `anonymousResponses` (boolean, default false), `lifecycle` (required, per REQ-CCO-003), and `results` (summary object written per REQ-CCO-006, never client-writable). At least one of `agendaItem` / `decision` MUST be set. Every property SHALL carry a `title`. The manifest and all widget/filter sources SHALL reference the schema by its slug `member-consultation`.

#### Scenario: Fractievoorzitter creates a consultation before a council vote

- GIVEN an upcoming raadsvergadering with an agenda item "Vaststelling parkeervisie"
- WHEN a fractievoorzitter creates a MemberConsultation with a question, `responseType: single-choice`, two choice options, a fractie audience, and the agenda item linked
- THEN a MemberConsultation object is created in the decidesk register in lifecycle `concept`
- AND OpenRegister validation rejects a consultation missing `question`, `responseType`, or `closesAt`

#### Scenario: Consultation without any link target rejected

- GIVEN a user creating a MemberConsultation
- WHEN neither `agendaItem` nor `decision` is set
- THEN the save is rejected with a validation error naming the missing link

#### Scenario: Register fragment is additive

- GIVEN a decidiq installation upgrading to this change
- WHEN the register configuration is loaded
- THEN the MemberConsultation and MemberConsultationResponse schemas are registered from the `48-constituency-consultation.json` fragment
- AND no existing schema in `decidesk_register.json` is modified

### Requirement: REQ-CCO-002 Generic audience model

The `MemberConsultation` schema SHALL define the audience as `audienceType` (required enum: `body-members | fractie | nc-group`) with: `audienceBody` (GovernanceBody reference; required for `body-members` and `fractie`), `audienceParty` (string; required for `fractie` — matched against `Membership.party` within `audienceBody`), and `audienceGroup` (Nextcloud group id; required for `nc-group`, e.g. an OR's configured achterban group). The system SHALL resolve the audience server-side: for `body-members`, all Persons holding an active Membership in `audienceBody` (active per `person-and-membership` REQ-PMB-002 semantics, evaluated at response time); for `fractie`, the subset of those whose active Membership has `party` equal to `audienceParty`; for `nc-group`, the members of the Nextcloud group. Audience membership SHALL never be trusted from the client. The audience model SHALL be reusable by other capabilities (the `works-council-consultation` change references the `nc-group` audience for its achterban step).

#### Scenario: Body-members audience resolved from active memberships

- GIVEN GovernanceBody "Gemeenteraad Voorbeeldingen" with 3 active Memberships and 1 Membership whose `endDate` is in the past
- WHEN a MemberConsultation with `audienceType: body-members` for that body resolves its audience
- THEN exactly the 3 persons with active Memberships are in the audience

#### Scenario: Fractie audience is the party subset

- GIVEN the same body where 2 active Memberships carry `party: "Groen Voorbeeldingen"`
- WHEN a MemberConsultation with `audienceType: fractie`, that body, and `audienceParty: "Groen Voorbeeldingen"` resolves its audience
- THEN exactly those 2 persons are in the audience

#### Scenario: NC-group audience for an OR achterban

- GIVEN a Nextcloud group `or-achterban-acme` with 12 members
- WHEN a MemberConsultation with `audienceType: nc-group` and `audienceGroup: or-achterban-acme` resolves its audience
- THEN exactly the 12 group members are in the audience
- AND no GovernanceBody membership is required for them

#### Scenario: Non-audience user cannot respond

- GIVEN an open MemberConsultation with a fractie audience
- WHEN an authenticated user outside that audience submits a response
- THEN the request is rejected with HTTP 403 and no response object is created

### Requirement: REQ-CCO-003 Lifecycle is declarative with four states

The `MemberConsultation` schema SHALL declare its status workflow exclusively via the canonical `x-openregister-lifecycle` dialect (ADR-031; keyword `initial`, never `initialState`/`default`): field `lifecycle`, initial `concept`, states `concept → open → gesloten → verwerkt`, with `verwerkt` terminal. Only the consultation's initiator or staff with governance-body authority SHALL transition it. Responses SHALL only be accepted while `lifecycle` is `open` AND the current time is within `opensAt`–`closesAt`; the window SHALL be enforced server-side on every response write independent of the stored status. The app SHALL NOT implement an imperative state machine for this lifecycle.

#### Scenario: Initiator opens the consultation

- GIVEN a MemberConsultation in lifecycle `concept` with a future `closesAt`
- WHEN the initiator transitions it to `open`
- THEN audience members can submit responses and the consultation appears in their consultation list

#### Scenario: Window enforced over stale status

- GIVEN a MemberConsultation in lifecycle `open` whose `closesAt` has passed but which has not yet been transitioned to `gesloten`
- WHEN an audience member submits a response
- THEN the submission is rejected with a static closed message and no object is created

#### Scenario: Invalid transition rejected declaratively

- GIVEN a MemberConsultation in lifecycle `verwerkt`
- WHEN any user attempts to set the lifecycle back to `open`
- THEN OpenRegister rejects the transition per the declared transition map (no app-side guard code involved)

### Requirement: REQ-CCO-004 Response collection respects respond-once and edit-until-close

The system SHALL define a `MemberConsultationResponse` schema (same register fragment; slug `member-consultation-response`, `x-schema-org: schema:Answer`) carrying: `consultation` (MemberConsultation reference, required), `respondentId` (NC UID, required, server-set), `choices` (array of selected options; required for choice types, a single element for `single-choice`), `openText` (string; the answer for `open-text`, optional remark otherwise), and `submittedAt` / `updatedAt` (date-time, server-set). Each audience member SHALL have at most one response per consultation (duplicate create by the same `respondentId` rejected); a member SHALL be able to edit their own response until the consultation closes (lifecycle leaves `open` or `closesAt` passes). Members SHALL never read, edit, or delete other members' responses. When `anonymousResponses` is true, no view or summary SHALL display respondent identities; `respondentId` remains stored solely to enforce respond-once and edit-own (pseudonymous at display, not at rest — stated in the UI).

#### Scenario: Member responds once

- GIVEN an open MemberConsultation with `responseType: single-choice` and an audience member who has not responded
- WHEN they submit a response choosing one option via the respond surface
- THEN a MemberConsultationResponse is created with their NC UID as `respondentId` and `submittedAt` set

#### Scenario: Duplicate response rejected

- GIVEN the same member who already responded
- WHEN they submit a second create for the same consultation
- THEN it is rejected with a conflict error and the original response is unchanged

#### Scenario: Member edits their response until close

- GIVEN an open consultation and a member's existing response
- WHEN the member changes their choice before `closesAt`
- THEN the response is updated and `updatedAt` is set
- AND the same edit attempted after the consultation is `gesloten` is rejected

#### Scenario: Anonymous consultation hides identities

- GIVEN a MemberConsultation with `anonymousResponses: true` and three responses
- WHEN the initiator views the responses or the summary
- THEN only counts and (for open text) answer texts are shown, never respondent names or UIDs

### Requirement: REQ-CCO-005 A raadpleging is non-binding and is not a VotingRound or PublicConsultation

A MemberConsultation SHALL NOT create, feed, or be counted in any `VotingRound`, `Vote`, ballot, tally, or quorum computation (`voting-system`, `preferential-ballot`); its outcome SHALL have no formal decision effect. It SHALL NOT be exposed on any public or citizen surface: it is not a `PublicConsultation`, accepts no citizen or anonymous-public input, and declares no public-group read rule (`citizen-participation` owns citizen input). Every surface that shows a MemberConsultation or its results — index, detail, respond surface, and the meeting-context input section — SHALL carry an explicit "niet-bindende raadpleging" (non-binding consultation) label.

#### Scenario: Outcome does not touch the formal vote

- GIVEN a decision with a linked MemberConsultation whose summary shows a majority "voor"
- WHEN a formal VotingRound is later held on that decision
- THEN the VotingRound tallies contain only formally cast Votes and no raadpleging counts
- AND no VotingRound or Vote object was created by the consultation

#### Scenario: No public surface

- GIVEN a MemberConsultation in any lifecycle state
- WHEN an unauthenticated client queries OpenRegister's published-predicate surface or any decidiq route
- THEN no MemberConsultation or MemberConsultationResponse data is returned

#### Scenario: Non-binding label everywhere

- GIVEN a MemberConsultation with results
- WHEN a user views the consultations index, the consultation detail, the respond surface, and the linked agenda item's input section
- THEN each surface displays the "niet-bindende raadpleging" label

### Requirement: REQ-CCO-006 Results summary as meeting input artifact

When a MemberConsultation is transitioned `gesloten → verwerkt`, the system SHALL compute and store a results summary on the consultation's `results` property: total audience size, response count, per-option counts for choice types, and — for open text — an optional digest written or curated by the initiator (`openTextDigest`). The summary SHALL contain no respondent identities. The linked agenda item and/or decision detail page SHALL show a "Raadpleging (niet-bindend)" input section listing its consultations with lifecycle and summary via reverse lookup, so the outcome is visible in the meeting context where the item is discussed. The `results` property SHALL only be written by the summary step, never directly by clients.

#### Scenario: Summary computed on verwerkt

- GIVEN a `gesloten` single-choice consultation with 8 responses over options "voor" (5) and "tegen" (3) and an audience of 12
- WHEN the initiator transitions it to `verwerkt`
- THEN `results` holds audience size 12, response count 8, and per-option counts 5/3
- AND no respondent identity appears in `results`

#### Scenario: Outcome visible in the meeting context

- GIVEN a `verwerkt` consultation linked to agenda item "Vaststelling parkeervisie"
- WHEN a user opens that agenda item's detail page
- THEN the "Raadpleging (niet-bindend)" section shows the consultation's question, lifecycle, and summary counts

#### Scenario: Open-text digest curated by the initiator

- GIVEN a `gesloten` open-text consultation with 6 textual responses
- WHEN the initiator writes a digest and transitions to `verwerkt`
- THEN `results` carries the response count and the digest text, and the individual texts remain readable to the initiator on the detail page

### Requirement: REQ-CCO-007 Audience notifications are declarative

Audience notifications SHALL be declared exclusively via the canonical `x-openregister-notifications` dialect (ADR-031) on the `MemberConsultation` schema: a trigger on the transition to `open` notifying the resolved audience that a raadpleging is open, and a scheduled closing-soon trigger notifying audience members who have not yet responded when `closesAt` is within the configured window (provisionally 48 hours), both with Dutch and English subjects. The app SHALL NOT dispatch these notifications imperatively and SHALL NOT introduce a bespoke reminder BackgroundJob.

#### Scenario: Audience notified on open

- GIVEN a MemberConsultation in `concept` with a resolved audience of 3
- WHEN it transitions to `open`
- THEN each of the 3 audience members receives a Nextcloud notification referencing the consultation

#### Scenario: Closing-soon reminder

- GIVEN an open consultation whose `closesAt` is within the closing-soon window, with one audience member who has not responded
- WHEN the scheduled notification trigger evaluates
- THEN that member receives a closing-soon notification
- AND no closing-soon notification is sent for consultations in `gesloten` or `verwerkt`

#### Scenario: No imperative dispatch

@e2e exclude static convention — enforced by the notification-dialect hydra gate

- WHEN the notification-dialect gate scans the constituency-consultation code paths
- THEN no imperative object-notification dispatch exists; all notifications are declarative rules in the register fragment

### Requirement: REQ-CCO-008 List, detail, and respond pages per manifest conventions

The system SHALL provide a Raadplegingen index page and a MemberConsultation detail page as manifest pages in a `src/manifest.d/constituency-consultation.json` fragment (ADR-037), following existing manifest-v2 conventions (`register: decidesk`, `schema: member-consultation`; index columns for question, audience, linked item, `closesAt`, lifecycle; quick filters on lifecycle and audience type). The detail page SHALL show the consultation fields, the linked agenda item/decision, the responses (respecting REQ-CCO-004 visibility), the results summary, and — for audience members while the consultation is open — the respond/edit surface. All schema references SHALL use slugs (`member-consultation`, `member-consultation-response`), never PascalCase keys.

#### Scenario: Member finds and answers an open raadpleging

- GIVEN two open and one `verwerkt` MemberConsultations
- WHEN an audience member opens the Raadplegingen index and filters on lifecycle `open`
- THEN only the two open consultations are listed
- AND clicking a row opens the detail page where the member can submit or edit their response

#### Scenario: Initiator follows progress on the detail page

- GIVEN an open consultation with 4 responses from an audience of 10
- WHEN the initiator opens the detail page
- THEN it shows the response count against the audience size, the linked agenda item, and the non-binding label

## Non-Functional Requirements

- **Performance:** the index paginates via the standard OR list API; audience resolution is a single Membership (or group) query per response write; summary computation is a single pass over the consultation's responses at `verwerkt` time (no N+1 on render).
- **Accessibility:** Target WCAG 2.2 AA; pages use standard manifest-v2 components (index/detail) plus the respond surface built from standard NC components with proper labels (`inputLabel` on selects); the non-binding label is text, not colour-only.
- **Internationalization:** Dutch and English MUST be supported (ADR-005); notification subjects declared in both languages; i18n keys in English.

## Acceptance Criteria

- [ ] MemberConsultation and MemberConsultationResponse schemas register from fragment 48 and validate required fields (including the at-least-one-link rule)
- [ ] Audience resolves server-side for all three audience types; non-audience responses are rejected with 403
- [ ] Lifecycle transitions are enforced by x-openregister-lifecycle only; the response window is enforced server-side over stale status
- [ ] Respond-once and edit-until-close hold; anonymous consultations never display identities
- [ ] No VotingRound/Vote/tally object is ever created or modified by a consultation; no public surface exists
- [ ] Summary is computed on `verwerkt` and visible on the linked agenda item/decision detail page with the non-binding label
- [ ] Open and closing-soon notifications fire declaratively; closing-soon only to non-responders and never for closed/verwerkt consultations
- [ ] Index/detail/respond pages render from the manifest fragment with slug schema refs

## Notes

- Related: `citizen-participation` (citizen-side boundary — PublicConsultation/ConsultationReaction untouched), `voting-system` / `preferential-ballot` (formal-vote boundary), `agenda-item-crud` / `meeting-agenda` (link targets), `governance-bodies` + `person-and-membership` (audience resolution via active Membership), `toezeggingen-register` (boundary-requirement pattern REQ-003 + declarative notification dialect mirrored here).
- Sibling `works-council-consultation` reuses the `nc-group` audience for an OR achterban; keep the audience model free of council-only assumptions.
- Fractie audience uses `Membership.party` today; upgrading to the first-class `Fractie` schema is deferred until `fractievoorzitter-fractie-koppeling` lands (forward-only, no dependency).
- Schema.org: `schema:AskAction` (consultation) / `schema:Answer` (response), consistent with the register's `x-schema-org` marker convention; ORI defines no member-poll type.
