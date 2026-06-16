# p3-citizen-participation Specification

## Purpose
TBD - created by archiving change 2026-05-11-p3-citizen-participation. Update Purpose after archive.

## Requirements

### Requirement: Public active vote browsing
The system SHALL expose an unauthenticated endpoint `GET /api/citizens/motions/{motionId}/votes/active` that returns motions where `citizenVotingAllowed = true` and the associated VotingRound is open. The response SHALL include the motion title, voting method (`citizenVotingMethod`), deadline, and current participation count. Staff-only motions (where `citizenVotingAllowed = false`) SHALL NOT appear.

#### Scenario: Anonymous citizen views active votes
- **WHEN** an unauthenticated HTTP GET is sent to `/api/citizens/motions/{motionId}/votes/active`
- **THEN** the response is HTTP 200 with a JSON array of active citizen vote opportunities for motions with `citizenVotingAllowed: true`

#### Scenario: Closed vote excluded from active list
- **WHEN** a motion's VotingRound `closedAt` timestamp is in the past
- **THEN** that motion is NOT returned by the active votes endpoint

#### Scenario: Staff-only motion excluded
- **WHEN** a motion has `citizenVotingAllowed: false`
- **THEN** it is NOT returned by the active votes endpoint regardless of authentication state

---

### Requirement: Authenticated citizen vote casting
The system SHALL allow an authenticated citizen to cast a `CitizenVote` (`schema:VoteAction`) via `POST /api/citizens/motions/{motionId}/votes`. The endpoint SHALL require Nextcloud authentication via `IUserSession`. One vote per citizen per motion SHALL be enforced (duplicate detection via `voterId` + `motionId`). The endpoint SHALL validate that `citizenVotingAllowed = true` on the motion and that the voting deadline has not passed.

#### Scenario: Citizen successfully casts a vote
- **WHEN** an authenticated citizen POSTs `{ "voteValue": "voor" }` to `/api/citizens/motions/{motionId}/votes`
- **THEN** HTTP 201 is returned and a CitizenVote object is created in OpenRegister with `castAt` set to current UTC timestamp

#### Scenario: Duplicate vote rejected
- **WHEN** an authenticated citizen attempts to cast a second vote on the same motion
- **THEN** HTTP 409 is returned with a static error message (no internal exception details exposed)

#### Scenario: Vote rejected after deadline
- **WHEN** the voting deadline (`VotingRound.closedAt`) has passed
- **THEN** HTTP 400 is returned with a static message indicating voting is closed

#### Scenario: Unauthenticated vote attempt rejected
- **WHEN** a vote POST is sent without a valid Nextcloud session
- **THEN** HTTP 401 is returned

---

### Requirement: Voting results with staff and citizen separation
The system SHALL expose `GET /api/citizens/motions/{motionId}/votes/results` returning vote totals split into three views: `staffVotes` (from VotingRound), `citizenVotes` (from CitizenVote objects), and `combined`. The endpoint SHALL be accessible without authentication once the VotingRound has a result. Individual voter identities SHALL NOT be included in the public response.

#### Scenario: Results returned after voting closes
- **WHEN** an unauthenticated GET is made to `/api/citizens/motions/{motionId}/votes/results` after the VotingRound closes
- **THEN** HTTP 200 is returned with `staffVotes`, `citizenVotes`, and `combined` objects each containing `votesFor`, `votesAgainst`, and `votesAbstain` counts

#### Scenario: Voter identity excluded from results
- **WHEN** the results endpoint is called
- **THEN** the response contains no `voterId` or personally identifiable fields

#### Scenario: Results unavailable before voting closes
- **WHEN** the VotingRound is still open
- **THEN** HTTP 200 is returned with null counts and a `status: "open"` indicator (or per governance-body policy: show running totals if configured)

---

### Requirement: Multi-method citizen voting support
The system SHALL support three voting methods configurable per motion via `citizenVotingMethod`: `simple` (Voor / Tegen / Onthoud), `weighted` (numeric weight allocation), and `ranked` (ordered preference list). The voting UI SHALL adapt to the configured method. Ranked-choice results SHALL be calculated server-side and included in the results response.

#### Scenario: Simple majority vote UI rendered
- **WHEN** a citizen views a motion with `citizenVotingMethod: "simple"`
- **THEN** the voting UI presents exactly three options: Voor, Tegen, Onthoud as radio buttons

#### Scenario: Ranked-choice vote accepted
- **WHEN** a citizen POSTs `{ "voteValue": ["optionA", "optionB", "optionC"] }` for a motion with `citizenVotingMethod: "ranked"`
- **THEN** HTTP 201 is returned and the ranked order is stored in `CitizenVote.voteValue`

---

### Requirement: Governance body isolation for citizen votes
The system SHALL enforce that a citizen can only cast votes on motions belonging to the governance body they are authorized for. Cross-body access SHALL be rejected at the per-object authorization layer using OpenRegister multi-tenancy.

#### Scenario: Cross-body vote attempt rejected
- **WHEN** an authenticated citizen from governance body A attempts to vote on a motion belonging to governance body B
- **THEN** HTTP 403 is returned

---

<!-- ============================================================ -->
<!-- Capability: citizen-panels                                   -->
<!-- Schema.org: schema:Organization (citizen advisory body)     -->
<!-- Popolo: Organization with classification=citizen-panel      -->
<!-- ============================================================ -->

### Requirement: Public citizen panel browsing
The system SHALL expose `GET /api/citizens/panels` returning all `CitizenPanel` objects with `statusLifecycle: "active"` for the relevant governance body. The response SHALL be accessible without authentication. Sensitive fields (internal notes, moderator email) SHALL be excluded from the public response.

#### Scenario: Anonymous user browses panels
- **WHEN** an unauthenticated GET is sent to `/api/citizens/panels`
- **THEN** HTTP 200 is returned with an array of active citizen panels including `name`, `description`, `scope`, `memberCount`, `termStart`, `termEnd`

#### Scenario: Inactive panels excluded
- **WHEN** a CitizenPanel has `statusLifecycle: "inactive"` or `termEnd` in the past
- **THEN** it is NOT included in the public panel listing

---

### Requirement: Panel membership request
The system SHALL allow an authenticated citizen to apply to join a panel via `POST /api/citizens/panels/{panelId}/join`. The request SHALL require Nextcloud authentication. The system SHALL create a membership relation on the CitizenPanel and send a confirmation notification. Staff SHALL be able to approve or reject applications via the governance management UI.

#### Scenario: Authenticated citizen applies to join a panel
- **WHEN** an authenticated citizen POSTs to `/api/citizens/panels/{panelId}/join`
- **THEN** HTTP 201 is returned, membership status is set to `"pending"`, and a confirmation notification is queued

#### Scenario: Duplicate application rejected
- **WHEN** a citizen already has an active or pending membership in the panel
- **THEN** HTTP 409 is returned

---

### Requirement: Panel feedback publication
The system SHALL expose `GET /api/citizens/panels/{panelId}/feedback` returning published feedback statements from panel members linked to decisions or motions. Feedback SHALL only be visible publicly after staff has marked it as published. Unpublished feedback items SHALL NOT appear in the public response.

#### Scenario: Published panel feedback returned
- **WHEN** an unauthenticated GET is sent to `/api/citizens/panels/{panelId}/feedback`
- **THEN** HTTP 200 is returned with an array of published feedback items including `title`, `content`, `publishedAt`, and any linked `relatedDecision`

#### Scenario: Unpublished feedback excluded
- **WHEN** a feedback item has not been marked published by staff
- **THEN** it does NOT appear in the public feedback response

---

### Requirement: Sanitized public panel roster
The system SHALL expose `GET /api/citizens/panels/{panelId}/members` returning a sanitized list of panel members. The public response SHALL include only `displayName` and `role`. Contact details, BSN, date of birth, and email SHALL be excluded from the public response.

#### Scenario: Public roster excludes PII
- **WHEN** an unauthenticated GET is sent to `/api/citizens/panels/{panelId}/members`
- **THEN** HTTP 200 is returned with member names and roles only; no email, phone, or address fields are present in the response

---

<!-- ============================================================ -->
<!-- Capability: participatory-budgeting                         -->
<!-- Schema.org: schema:Grant (ParticipatoryBudget),             -->
<!--             schema:Proposal (BudgetProposal)                -->
<!-- ============================================================ -->

### Requirement: Public budget and proposal browsing
The system SHALL expose `GET /api/citizens/budgets` listing all `ParticipatoryBudget` objects and `GET /api/citizens/budgets/{budgetId}/proposals` listing all `BudgetProposal` objects for a budget. Both endpoints SHALL be accessible without authentication and SHALL include lifecycle status information.

#### Scenario: Anonymous citizen views open budgets
- **WHEN** an unauthenticated GET is sent to `/api/citizens/budgets`
- **THEN** HTTP 200 is returned with an array of participatory budgets including `name`, `totalAmount`, `currency`, `submissionDeadline`, `votingDeadline`, `status`

#### Scenario: Proposals listed per budget
- **WHEN** an unauthenticated GET is sent to `/api/citizens/budgets/{budgetId}/proposals`
- **THEN** HTTP 200 is returned with an array of proposals including `title`, `description`, `requestedAmount`, `category`, `status`, `votesFor`, `votesAgainst`

---

### Requirement: Budget proposal submission
The system SHALL allow authenticated citizens to submit `BudgetProposal` objects via `POST /api/citizens/budgets/{budgetId}/proposals` during the submission phase (`ParticipatoryBudget.status = "submission"` and `submissionDeadline` not passed). The system SHALL validate that `requestedAmount` does not exceed `totalAmount`.

#### Scenario: Citizen submits a valid proposal
- **WHEN** an authenticated citizen POSTs a valid proposal to `/api/citizens/budgets/{budgetId}/proposals`
- **THEN** HTTP 201 is returned and a BudgetProposal object is created with `status: "submitted"`

#### Scenario: Submission rejected after deadline
- **WHEN** `submissionDeadline` has passed or `ParticipatoryBudget.status != "submission"`
- **THEN** HTTP 400 is returned with a static message indicating the submission window is closed

#### Scenario: Oversized proposal rejected
- **WHEN** `requestedAmount` exceeds `ParticipatoryBudget.totalAmount`
- **THEN** HTTP 422 is returned with a validation error on the `requestedAmount` field

---

### Requirement: Budget proposal voting
The system SHALL allow authenticated citizens to vote on budget proposals via `POST /api/citizens/budgets/{budgetId}/proposals/{proposalId}/vote` during the voting phase (`ParticipatoryBudget.status = "voting"` and `votingDeadline` not passed). Each citizen MAY vote on multiple proposals within a single budget.

#### Scenario: Citizen votes on a proposal
- **WHEN** an authenticated citizen POSTs `{ "vote": "voor" }` within the voting phase
- **THEN** HTTP 201 is returned and `BudgetProposal.votesFor` is incremented atomically

#### Scenario: Vote rejected outside voting phase
- **WHEN** the budget `status` is not `"voting"` or `votingDeadline` has passed
- **THEN** HTTP 400 is returned

---

### Requirement: Budget results visualization
The system SHALL expose `GET /api/citizens/budgets/{budgetId}/results` once `resultsPublished = true`. The response SHALL include proposal rankings, allocated amounts (top-ranked proposals within total budget), and total participation count.

#### Scenario: Results available after publication
- **WHEN** `ParticipatoryBudget.resultsPublished = true` and a GET is made to the results endpoint
- **THEN** HTTP 200 is returned with ranked proposals, each including `allocatedAmount` and final `status: "funded"` or `status: "not-funded"`

#### Scenario: Results withheld before publication
- **WHEN** `resultsPublished = false`
- **THEN** HTTP 403 is returned with a message indicating results are not yet published

---

<!-- ============================================================ -->
<!-- Capability: public-consultations                            -->
<!-- Schema.org: schema:Event (PublicConsultation),              -->
<!--             schema:DiscussionForumPosting (Deliberation)    -->
<!-- Akoma Ntoso: akomantoso:debate for deliberation threads     -->
<!-- ============================================================ -->

### Requirement: Public consultation browsing
The system SHALL expose `GET /api/citizens/consultations` returning all `PublicConsultation` objects with `status: "open"` or `status: "closed"`. Each consultation SHALL include `title`, `description`, `submissionDeadline`, `status`, and `submissionCount`. No authentication required.

#### Scenario: Active consultations listed
- **WHEN** an unauthenticated GET is sent to `/api/citizens/consultations`
- **THEN** HTTP 200 is returned with open and recently-closed consultations sorted by `submissionDeadline` descending

---

### Requirement: Consultation feedback submission
The system SHALL allow citizens to submit feedback on an open consultation via `POST /api/citizens/consultations/{consultationId}/feedback`. Submission SHALL be optionally authenticated (anonymous submissions allowed when `feedbackRequired: false`). After the `submissionDeadline`, the endpoint SHALL reject new submissions.

#### Scenario: Anonymous feedback submitted before deadline
- **WHEN** an unauthenticated POST with feedback content is sent before `submissionDeadline`
- **THEN** HTTP 201 is returned and `PublicConsultation.submissionCount` is incremented

#### Scenario: Feedback submission rejected after deadline
- **WHEN** the `submissionDeadline` has passed
- **THEN** HTTP 400 is returned with a static deadline-passed message

#### Scenario: Published feedback visible after deadline
- **WHEN** staff publishes consultation feedback and a GET is made to `/api/citizens/consultations/{consultationId}/feedback`
- **THEN** HTTP 200 is returned with an array of published feedback items

---

### Requirement: Deliberation thread participation
The system SHALL expose `GET /api/citizens/deliberations/{deliberationId}/posts` and `POST /api/citizens/deliberations/{deliberationId}/posts` for threaded discussion. GET is public. POST is optionally authenticated. Nested replies SHALL be supported via `parentPostId` field. The Deliberation entity maps to `akomantoso:debate` for legislative deliberation contexts.

#### Scenario: Anonymous citizen reads deliberation thread
- **WHEN** an unauthenticated GET is sent to `/api/citizens/deliberations/{deliberationId}/posts`
- **THEN** HTTP 200 is returned with posts ordered by creation time, including `parentPostId` for nested replies

#### Scenario: Citizen posts to an open deliberation
- **WHEN** a POST is sent to a deliberation with `discussionStatus: "open"`
- **THEN** HTTP 201 is returned and `Deliberation.postsCount` is incremented

#### Scenario: Post rejected on closed deliberation
- **WHEN** a POST is sent to a deliberation with `discussionStatus: "closed"`
- **THEN** HTTP 400 is returned

---

### Requirement: Deliberation post moderation
The system SHALL allow authenticated staff members to moderate deliberation posts via `PUT /api/citizens/deliberations/{deliberationId}/posts/{postId}/moderate`. Moderated posts SHALL be hidden from the public view with a `[verwijderd door moderator]` placeholder. Only staff with the governance body admin role SHALL access moderation endpoints.

#### Scenario: Staff moderates a post
- **WHEN** an authenticated staff member PUTs `{ "moderated": true, "reason": "off-topic" }` to the moderation endpoint
- **THEN** HTTP 200 is returned and the post `content` is replaced with a placeholder in the public view

#### Scenario: Non-staff moderation attempt rejected
- **WHEN** a citizen attempts to access the moderation endpoint
- **THEN** HTTP 403 is returned

---

<!-- ============================================================ -->
<!-- Capability: citizen-dashboard                               -->
<!-- Schema.org: schema:WebPage (citizen dashboard)              -->
<!-- OCP: IUserSession for personalization                       -->
<!-- ============================================================ -->

### Requirement: Active participation opportunities overview
The `CitizenDashboard.vue` component SHALL display a summary of currently active participation opportunities: open citizen votes, active citizen panels, open budget submission/voting phases, and open public consultations. This view SHALL be accessible without authentication (showing governance-body-level opportunities); authenticated users MAY see personalized highlights.

#### Scenario: Anonymous citizen sees active opportunities
- **WHEN** an unauthenticated user visits `/citizens/dashboard`
- **THEN** the dashboard displays counts and links for: active votes, active panels, open budgets, open consultations

#### Scenario: Authenticated citizen sees personalized dashboard
- **WHEN** an authenticated citizen visits `/citizens/dashboard`
- **THEN** the dashboard additionally shows panel invitations, their past votes, and budget proposals they submitted

---

### Requirement: Participation history for authenticated citizens
The system SHALL provide authenticated citizens with a personal participation history panel on the dashboard showing: past CitizenVote objects cast, BudgetProposal objects submitted, and consultations responded to, with links to the relevant governance item.

#### Scenario: Participation history displayed
- **WHEN** an authenticated citizen views their dashboard
- **THEN** a "Mijn participatie" panel lists their CitizenVote records, BudgetProposal submissions, and consultation responses in reverse chronological order

---

### Requirement: In-app notification center
The citizen dashboard SHALL include a notification center showing unread Notification objects (`channel: "inapp"`) for the authenticated citizen. Citizens SHALL be able to dismiss notifications and navigate to the linked governance item. Unread count SHALL be visible in the navigation.

#### Scenario: Unread notifications shown
- **WHEN** an authenticated citizen views the dashboard
- **THEN** unread in-app notifications are shown with count badge; each notification includes `subject`, `content`, and a link to the relevant item

#### Scenario: Notification dismissed
- **WHEN** a citizen dismisses a notification
- **THEN** the notification `status` is updated to `"read"` via a PATCH call and disappears from the unread list

---

<!-- ============================================================ -->
<!-- Capability: transparency-portal                             -->
<!-- Schema.org: schema:WebPage (transparency portal)            -->
<!-- ORI: /api/ori/v1/decisions (ADR-003 extension)             -->
<!-- Woo compliance: public access to governance information     -->
<!-- ============================================================ -->

### Requirement: Published decision search
The system SHALL expose `GET /api/citizens/decisions` returning only `Decision` objects with `isPublished: "public"`. The endpoint SHALL support filtering by `decisionDate` (date range), `outcome`, and full-text search on `title` and `text`. No authentication required. The response SHALL map to ORI Decision fields as defined in ADR-003.

**ORI field mappings:** `title` → `name`, `decisionDate` → `date`, `outcome` → `result`, `legalBasis` → `classification`.

#### Scenario: Anonymous search returns only public decisions
- **WHEN** an unauthenticated GET is sent to `/api/citizens/decisions?q=windmolens`
- **THEN** HTTP 200 is returned with only decisions where `isPublished: "public"` matching the search term

#### Scenario: Internal decisions excluded
- **WHEN** a decision has `isPublished: "internal"` or `isPublished: "confidential"`
- **THEN** it does NOT appear in the public decisions endpoint response

#### Scenario: Date range filter applied
- **WHEN** GET `/api/citizens/decisions?from=2026-01-01&to=2026-12-31` is called
- **THEN** only public decisions with `decisionDate` within that range are returned

---

### Requirement: Public meeting calendar
The system SHALL expose `GET /api/citizens/meetings/calendar` returning only `Meeting` objects (read from CalDAV per ADR-002) where `isPublic: true`. Each entry SHALL include meeting `title`, `scheduledDate`, `endDate`, `location`, `meetingType`, and links to published agenda and minutes (if available). No authentication required.

**ORI field mappings:** `title` → `name`, `scheduledDate` → `start_date`, `location` → `location` (Popolo Event).

#### Scenario: Public meeting calendar returned
- **WHEN** an unauthenticated GET is sent to `/api/citizens/meetings/calendar`
- **THEN** HTTP 200 is returned with upcoming meetings where `isPublic: true`, sorted by `scheduledDate` ascending

#### Scenario: Non-public meetings excluded
- **WHEN** a Meeting has `isPublic: false`
- **THEN** it does NOT appear in the public calendar

#### Scenario: Published agenda linked
- **WHEN** a public meeting has a published agenda
- **THEN** the calendar response includes `agendaUrl` linking to `GET /api/citizens/meetings/{meetingId}/agenda`

---

### Requirement: ORI API public access for decisions
The system SHALL expose `GET /api/ori/v1/decisions` per ADR-003 endpoint structure, serializing public Decision objects in ORI-compatible JSON-LD format. The endpoint SHALL be unauthenticated and SHALL only include decisions where `isPublished: "public"`.

#### Scenario: ORI decisions endpoint returns public decisions
- **WHEN** an unauthenticated GET is sent to `/api/ori/v1/decisions`
- **THEN** HTTP 200 is returned with decisions serialized in ORI JSON-LD format, each including `@type: "Motion"` and all required ORI fields

#### Scenario: Non-public decisions excluded from ORI endpoint
- **WHEN** a decision has `isPublished != "public"`
- **THEN** it is NOT included in the ORI decisions response

---

### Requirement: Open data export
The transparency portal SHALL offer downloadable exports of public governance data. `GET /api/citizens/decisions/export` SHALL return a CSV or JSON download of all public decisions. `GET /api/citizens/meetings/export` SHALL return a CSV or JSON download of public meeting records. Format is controlled by an `Accept` header (`text/csv` or `application/json`).

#### Scenario: CSV export of public decisions
- **WHEN** `GET /api/citizens/decisions/export` is called with `Accept: text/csv`
- **THEN** HTTP 200 is returned with `Content-Type: text/csv` and a downloadable CSV including all public decisions

---

<!-- ============================================================ -->
<!-- Capability: offline-participation                           -->
<!-- Accessibility: WCAG 2.1 AA, PDF/UA tagged PDF              -->
<!-- ============================================================ -->

### Requirement: PDF voting form generation with QR code
The system SHALL generate accessible PDF voting forms via `GET /api/citizens/forms/vote/{motionId}.pdf` for motions with `citizenVotingAllowed: true`. Each PDF SHALL include: motion title, description, voting options, and a QR code linking to the digital voting page. PDFs SHALL be tagged (PDF/UA) for screen reader accessibility.

#### Scenario: Voting PDF generated for public motion
- **WHEN** `GET /api/citizens/forms/vote/{motionId}.pdf` is called for a motion with `citizenVotingAllowed: true`
- **THEN** HTTP 200 is returned with `Content-Type: application/pdf` and a tagged PDF containing the motion details and QR code

#### Scenario: PDF rejected for non-citizen-voting motion
- **WHEN** `citizenVotingAllowed: false` on the motion
- **THEN** HTTP 404 is returned

---

### Requirement: QR code scanning and form prefill
The citizen portal SHALL provide a QR code scanning page at `/citizens/scan` that reads a QR code from a downloaded form and redirects the citizen to the pre-filled digital voting or proposal form. The system SHALL use `QrCodeService` to generate and validate QR codes. QR codes SHALL include an HMAC signature to prevent forgery and SHALL expire 30 days after generation.

#### Scenario: Valid QR code scanned
- **WHEN** a citizen scans a valid, unexpired QR code
- **THEN** they are redirected to the corresponding digital form with fields pre-populated from the QR payload

#### Scenario: Expired QR code rejected
- **WHEN** a QR code older than 30 days is scanned
- **THEN** the citizen is shown an error page indicating the form has expired with instructions to download a new form

---

### Requirement: Offline submission import
The system SHALL provide `POST /api/citizens/submissions/import` allowing staff to upload scanned paper form images. The `OfflineSubmissionImporter` SHALL parse the form data (via OCR or structured QR payload), display a staff review preview, and — upon staff confirmation — create the corresponding digital object (CitizenVote, BudgetProposal) in OpenRegister with `notes` recording the offline origin.

#### Scenario: Staff imports a scanned voting form
- **WHEN** staff POSTs a scanned form image to `/api/citizens/submissions/import`
- **THEN** HTTP 200 is returned with parsed form data for staff preview; no vote is recorded until staff confirms

#### Scenario: Staff confirms offline submission
- **WHEN** staff confirms the parsed data via `POST /api/citizens/submissions/import/confirm`
- **THEN** a CitizenVote is created with `notes: "Ingediend via papieren formulier"` and `isProxy: false`

---

<!-- ============================================================ -->
<!-- Capability: citizen-notifications                           -->
<!-- Schema.org: schema:Message (Notification entity)           -->
<!-- GDPR: explicit opt-in, single-click unsubscribe            -->
<!-- OCP: IMailer, IEventDispatcher                             -->
<!-- ============================================================ -->

### Requirement: Notification preference management
The system SHALL provide authenticated citizens with endpoints to manage notification preferences: `GET /api/citizens/notifications/preferences` and `PUT /api/citizens/notifications/preferences`. Preferences SHALL control channel (email, inapp) and notification type (vote_opened, panel_invitation, budget_submission_open, consultation_deadline, vote_closing_soon). Preferences SHALL be stored as OpenRegister metadata on the citizen's Notification objects.

#### Scenario: Citizen retrieves preferences
- **WHEN** an authenticated citizen calls `GET /api/citizens/notifications/preferences`
- **THEN** HTTP 200 is returned with current channel and type preference settings

#### Scenario: Citizen updates preferences
- **WHEN** an authenticated citizen PUTs `{ "email": false, "inapp": true, "types": ["vote_opened"] }`
- **THEN** HTTP 200 is returned and subsequent notifications respect the updated preferences

---

### Requirement: Notification delivery triggers
The `NotificationService` SHALL dispatch notifications for the following events using `IEventDispatcher`: vote opened (`vote_opened`), vote closing within 24 hours (`vote_closing_soon`), panel invitation (`panel_invitation`), budget submission phase opened (`budget_submission_open`), and consultation deadline approaching within 48 hours (`consultation_deadline`). Email delivery SHALL use Nextcloud `IMailer`. In-app delivery SHALL create Notification objects in OpenRegister.

#### Scenario: Vote-opened notification dispatched
- **WHEN** a VotingRound for a `citizenVotingAllowed` motion opens
- **THEN** all opted-in citizens of that governance body receive a `vote_opened` notification via their preferred channels

#### Scenario: Email notification delivered
- **WHEN** a notification is dispatched for a citizen with `email: true` preference
- **THEN** `IMailer::send()` is called with the notification `subject` and `content`

---

### Requirement: GDPR-compliant notification unsubscribe
The system SHALL include a one-click unsubscribe link in all email notifications. Clicking the unsubscribe link SHALL disable all email notifications for the recipient without requiring authentication. The unsubscribe action SHALL be logged in OpenRegister audit trail per Woo requirements. No PII SHALL be stored in notification logs.

#### Scenario: One-click unsubscribe from email
- **WHEN** a citizen clicks the unsubscribe link in a notification email (no login required)
- **THEN** all email notifications are disabled for that recipient and a confirmation page is shown

#### Scenario: Unsubscribe action logged
- **WHEN** the unsubscribe action is completed
- **THEN** the event is recorded in the OpenRegister `auditTrail` of the recipient's notification preference object

#### Scenario: No PII in notification logs
- **WHEN** any Notification object is created or updated
- **THEN** the `auditTrail` entry does NOT contain BSN, date of birth, or residential address fields

---

### Requirement: Meeting public visibility flag
The `Meeting` entity (stored as CalDAV VEVENT per ADR-002) SHALL support an `isPublic` boolean property stored as `X-DECIDESK-PUBLIC` X-property. When `isPublic: true`, the meeting's agenda (if published), minutes (if published), and recording URL (if available) SHALL be visible in the public transparency portal without authentication. When `isPublic: false` (default), the meeting remains staff-only. Governance bodies MAY configure a default publication policy.

The `Meeting` schema definition in `decidesk_register.json` SHALL be updated to include `isPublic` as an optional boolean field with `default: false`. The CalDAV wrapper object in OpenRegister SHALL store `isPublic` for relational query purposes.

#### Scenario: Public meeting appears in citizen calendar
- **WHEN** a meeting has `isPublic: true` and its `scheduledDate` is in the future
- **THEN** it appears in `GET /api/citizens/meetings/calendar` response

#### Scenario: Non-public meeting hidden from citizens
- **WHEN** a meeting has `isPublic: false` (or the field is absent on an existing meeting)
- **THEN** it does NOT appear in any `/api/citizens/` endpoint

#### Scenario: Existing meetings default to non-public
- **WHEN** Decidesk is upgraded and existing Meeting objects have no `isPublic` field
- **THEN** those meetings behave as `isPublic: false` (staff-only) — no change in behavior

#### Scenario: Governance body sets default publication policy
- **WHEN** a governance body configures `publishingPolicy: "public"` in GovernanceBody settings
- **THEN** new meetings created for that body default to `isPublic: true`

---

<!-- ============================================================ -->
<!-- Modified capability: motion-and-voting                      -->
<!-- BREAKING: adds citizenVotingAllowed + citizenVotingMethod   -->
<!--           to Motion entity                                  -->
<!-- Default: false — existing motions unaffected               -->
<!-- Schema.org: opengov:Motion (existing)                      -->
<!-- ============================================================ -->

### Requirement: Citizen voting configuration on motions
The `Motion` entity SHALL support two new optional fields: `citizenVotingAllowed` (boolean, default `false`) and `citizenVotingMethod` (string enum: `simple` | `weighted` | `ranked`, optional; only meaningful when `citizenVotingAllowed: true`). When `citizenVotingAllowed: true`, the motion is eligible for citizen participation via `/api/citizens/motions/{motionId}/votes`. The voting results page SHALL display separate tabs for staff votes (from VotingRound) and citizen votes (from CitizenVote objects).

The `Motion` schema definition in `decidesk_register.json` SHALL be updated to include both fields. Existing motions without these fields SHALL default to `citizenVotingAllowed: false`.

#### Scenario: Motion enabled for citizen voting
- **WHEN** staff sets `citizenVotingAllowed: true` and `citizenVotingMethod: "simple"` on a motion
- **THEN** the motion appears in `GET /api/citizens/motions/{motionId}/votes/active` and citizens can cast CitizenVote objects

#### Scenario: Existing motions unaffected by upgrade
- **WHEN** Decidesk is upgraded and existing Motion objects have no `citizenVotingAllowed` field
- **THEN** those motions behave as `citizenVotingAllowed: false` — citizens cannot vote on them

#### Scenario: Voting results show separate tabs
- **WHEN** a motion has both a VotingRound with Vote objects and CitizenVote objects
- **THEN** the results endpoint returns both `staffVotes` and `citizenVotes` objects plus a `combined` summary

#### Scenario: citizenVotingMethod defaults to simple
- **WHEN** `citizenVotingAllowed: true` is set without specifying `citizenVotingMethod`
- **THEN** the system uses `"simple"` (Voor / Tegen / Onthoud) as the default voting method

---

<!-- ============================================================ -->
<!-- Modified capability: decisions                              -->
<!-- BREAKING: replaces isPublished boolean with enum           -->
<!-- Values: internal | public | confidential                   -->
<!-- Default: internal — existing decisions unaffected          -->
<!-- ORI: public decisions exposed at /api/ori/v1/decisions     -->
<!-- ============================================================ -->

### Requirement: Decision publication status
The `Decision` entity SHALL support `isPublished` as an enum field with three values: `internal` (staff-only, default), `public` (visible in transparency portal and ORI API), and `confidential` (explicitly restricted; hidden from all non-authenticated users including staff from other bodies). The previous `isPublished` boolean field (if present from Phase 2) SHALL be migrated: `true` → `public`, `false` → `internal`.

When `isPublished: "public"`, the decision SHALL appear in:
- `GET /api/citizens/decisions` (transparency portal search)
- `GET /api/ori/v1/decisions` (ORI API endpoint per ADR-003)

The `Decision` schema definition in `decidesk_register.json` SHALL be updated to use the enum type. A repair-step migration SHALL convert any existing boolean `isPublished` values to the corresponding enum string.

**ORI field mapping:** `isPublished: "public"` → included in ORI output; `internal` / `confidential` → excluded.

#### Scenario: Public decision appears in transparency portal
- **WHEN** a decision has `isPublished: "public"`
- **THEN** it appears in `GET /api/citizens/decisions` and `GET /api/ori/v1/decisions`

#### Scenario: Internal decision hidden from citizens
- **WHEN** a decision has `isPublished: "internal"` (or the field is absent on an existing decision)
- **THEN** it does NOT appear in any `/api/citizens/` or `/api/ori/v1/` endpoint

#### Scenario: Confidential decision excluded from all public endpoints
- **WHEN** a decision has `isPublished: "confidential"`
- **THEN** it is excluded from `/api/citizens/decisions`, `/api/ori/v1/decisions`, and all staff endpoints outside the originating governance body

#### Scenario: Boolean migration executed correctly
- **WHEN** the repair-step migration runs on an existing Decision with `isPublished: true`
- **THEN** the field value is updated to `"public"` without data loss

#### Scenario: Existing decisions default to internal on upgrade
- **WHEN** Decidesk is upgraded and an existing Decision has no `isPublished` field
- **THEN** it behaves as `isPublished: "internal"` — no change in behavior for staff workflows
