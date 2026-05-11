# Spec: Collaboration

This file contains delta specifications for the p4-collaboration change.
It covers 9 new capabilities and 2 modified existing capabilities.

---

## ADDED Requirements

<!-- ============================================================ -->
<!-- Capability: task-delegation                                   -->
<!-- ============================================================ -->

### Requirement: REQ-TD-001 Create governance task with delegation
The system SHALL allow any authenticated governance participant to create a Task object
in OpenRegister containing a title, description, assignee (Person reference), delegator
(Person reference), due date, and initial `taskStatus` of `pending`. The Task SHALL be
stored as an OpenRegister object with full audit trail. The system SHALL notify the
assignee per their NotificationPreference when a task is assigned to them.

#### Scenario: Task created and assignee notified
- **GIVEN** an authenticated user with edit rights on the governance body
- **WHEN** they submit a new Task with a valid assignee, title, and due date
- **THEN** the Task object is persisted in OpenRegister with `taskStatus: pending`
- **THEN** the assignee receives a notification (in-app or email) based on their NotificationPreference

#### Scenario: Task creation rejected when required fields missing
- **WHEN** a user submits a Task without a title or assignee
- **THEN** the system returns HTTP 422 with validation errors for the missing fields
- **THEN** no Task object is created

### Requirement: REQ-TD-002 Substitute delegation during absence
The system SHALL allow a delegator to create a Delegation object that specifies a
substitute Person and an optional `expiresAt` date, enabling time-bound absence coverage.
While a Delegation with `status: active` exists, the substitute SHALL receive task
assignment notifications and SHALL have the same rights over the delegated Tasks as the
original assignee. The delegation SHALL automatically transition to `status: expired` when
the current date exceeds `expiresAt`.

#### Scenario: Tasks routed to substitute during active delegation
- **GIVEN** a Delegation with `status: active` linking a delegator to a substitute with a future `expiresAt`
- **WHEN** a new Task is assigned to the delegator
- **THEN** the substitute also receives an assignment notification
- **THEN** the substitute can view and update the Task

#### Scenario: Delegation expires automatically
- **GIVEN** a Delegation with `expiresAt` set to a past timestamp
- **WHEN** the system evaluates the delegation status
- **THEN** `status` is set to `expired`
- **THEN** new task assignments no longer route to the former substitute

#### Scenario: Delegation revoked manually
- **WHEN** the delegator sets the Delegation `status` to `revoked`
- **THEN** the substitute immediately loses routing rights for subsequent tasks
- **THEN** existing in-progress tasks assigned via the delegation are unaffected

### Requirement: REQ-TD-003 Reclaim delegated task
The system SHALL allow the original delegator to reclaim a Task from its current
assignee at any time by invoking the reclaim action. After reclaim, `taskStatus` SHALL
be set to `pending` and the assignee SHALL revert to the delegator.

#### Scenario: Delegator reclaims in-progress task
- **GIVEN** a Task with `taskStatus: in-progress` assigned to a delegate
- **WHEN** the original delegator invokes the reclaim action on the task
- **THEN** the Task `assignee` is updated to the delegator
- **THEN** `taskStatus` is set to `pending`
- **THEN** the former delegate receives a notification that the task was reclaimed

#### Scenario: Non-delegator cannot reclaim
- **GIVEN** a Task assigned via delegation
- **WHEN** a user who is neither the delegator nor an admin attempts the reclaim action
- **THEN** the system returns HTTP 403
- **THEN** the Task is unchanged

<!-- ============================================================ -->
<!-- Capability: task-tracking                                     -->
<!-- ============================================================ -->

### Requirement: REQ-TT-001 Track task status lifecycle
The system SHALL enforce the Task lifecycle state machine:
`pending → in-progress → completed`. Any state SHALL be reclaimed back to `pending` by
the delegator. Invalid state transitions (e.g. `completed → in-progress`) SHALL be
rejected with HTTP 422.

#### Scenario: Valid status transition accepted
- **GIVEN** a Task with `taskStatus: pending`
- **WHEN** the assignee updates `taskStatus` to `in-progress`
- **THEN** the change is persisted and the audit trail records the transition

#### Scenario: Invalid status transition rejected
- **GIVEN** a Task with `taskStatus: completed`
- **WHEN** a user attempts to set `taskStatus` to `in-progress` via the API
- **THEN** the system returns HTTP 422
- **THEN** `taskStatus` remains `completed`

### Requirement: REQ-TT-002 Task progress visibility for assignee, delegator, and team
The system SHALL provide a "My Tasks" dashboard widget showing tasks where the current
user is assignee or delegator, grouped by status (overdue, due this week, other). The
widget SHALL display task count, overdue count, and delegated-by-me count as KPI cards
using `CnStatsBlock`. The task list page SHALL support filtering by `assignedToMe`,
`delegatedByMe`, status, and due date range.

#### Scenario: Dashboard widget shows correct task counts
- **GIVEN** a user with 2 assigned tasks (1 overdue, 1 pending) and 3 tasks delegated by them
- **WHEN** the user opens the dashboard
- **THEN** the "My Tasks" widget shows assigned count: 2, delegated count: 3, overdue count: 1

#### Scenario: Task list filtered by assignee
- **WHEN** a user applies the filter `assignedToMe` on the task list page
- **THEN** only Tasks where `assignee` matches the current user's Person UUID are returned

<!-- ============================================================ -->
<!-- Capability: collaboration-workspace                           -->
<!-- ============================================================ -->

### Requirement: REQ-CW-001 Create a bounded collaboration workspace
The system SHALL allow an authorized user to create a CollaborationWorkspace with a
name, type (faction, committee, or task-group), purpose description, and access level
(private, restricted, or public). The creator SHALL be assigned the `owner` role.
`accessLevel: private` SHALL be the default. The workspace SHALL be stored in
OpenRegister with full audit trail.

#### Scenario: Faction workspace created with private access
- **GIVEN** an authenticated user with governance body edit rights
- **WHEN** they create a CollaborationWorkspace with `type: faction`, `accessLevel: private`
- **THEN** the workspace is persisted in OpenRegister with the creator as `owner`
- **THEN** the workspace is only visible to the owner until members are added

#### Scenario: Workspace creation requires name and type
- **WHEN** a user submits a workspace without `name` or `type`
- **THEN** the system returns HTTP 422 with validation errors
- **THEN** no workspace is created

### Requirement: REQ-CW-002 Workspace member access control
The system SHALL enforce role-based access within a CollaborationWorkspace:
- `owner`: full read/write/admin and member management
- `editor`: read/write on workspace content; cannot manage members
- `viewer`: read-only on workspace content

Non-members SHALL NOT be able to view or access a private or restricted workspace.
The owner SHALL be able to add and remove members and assign roles via the workspace
members endpoint.

#### Scenario: Non-member cannot access private workspace
- **GIVEN** a CollaborationWorkspace with `accessLevel: private`
- **WHEN** an authenticated user who is not a member requests the workspace detail
- **THEN** the system returns HTTP 403

#### Scenario: Editor can add content but not manage members
- **GIVEN** a workspace where user A has the `editor` role
- **WHEN** user A attempts to add a new member to the workspace
- **THEN** the system returns HTTP 403

### Requirement: REQ-CW-003 Scope tasks and agenda items within workspace
The system SHALL allow tasks to be associated with a CollaborationWorkspace by setting
a workspace reference on the Task. Tasks scoped to a workspace SHALL be visible to all
workspace members with at least `viewer` role. The workspace detail page SHALL display
a task list filtered to workspace-scoped tasks and SHALL show the workspace's relevant
agenda items from the current meeting cycle.

#### Scenario: Task scoped to workspace visible to members
- **GIVEN** a Task with a workspace reference set to workspace W
- **WHEN** a member of workspace W with `viewer` role requests the workspace detail page
- **THEN** the task appears in the workspace task list

#### Scenario: Task scoped to workspace hidden from non-members
- **GIVEN** a Task with a workspace reference set to workspace W with `accessLevel: private`
- **WHEN** a user who is not a member of W requests the task list
- **THEN** the task does not appear in the results

<!-- ============================================================ -->
<!-- Capability: discussion-and-comments                           -->
<!-- ============================================================ -->

### Requirement: REQ-DC-001 Threaded comments on governance artifacts
The system SHALL allow authenticated users to post Comment objects on AgendaItems,
Motions, Amendments, and Decisions via an OpenRegister relation reference encoded as
`{register}:{schema}:{uuid}` in the Comment `target` field. Comments SHALL support
threaded replies via a `parentComment` reference. The target object SHALL be validated
to exist before the Comment is persisted.

#### Scenario: Comment posted on motion
- **GIVEN** a Motion object with a known UUID
- **WHEN** an authenticated user posts a Comment with `target` referencing that Motion
- **THEN** the Comment is persisted in OpenRegister
- **THEN** the comment appears in the CommentThread on the MotionDetailPage

#### Scenario: Reply creates thread
- **GIVEN** an existing Comment C on an AgendaItem
- **WHEN** a user posts a Comment with `parentComment` referencing C
- **THEN** the reply is rendered nested below C in the CommentThread component

#### Scenario: Comment on non-existent target rejected
- **WHEN** a user posts a Comment with a `target` UUID that does not exist in OpenRegister
- **THEN** the system returns HTTP 422
- **THEN** no Comment is persisted

### Requirement: REQ-DC-002 @mention in comment triggers notification
The system SHALL parse Comment text for `@{name}` patterns during save. Each resolved
Person reference in the `mentions` array SHALL trigger a notification to the mentioned
person, respecting their NotificationPreference (`commentMention` flag and `deliveryMethod`).

#### Scenario: Mentioned person receives notification
- **GIVEN** a Comment containing `@Maaike` that resolves to a Person with `commentMention: true`
- **WHEN** the Comment is saved
- **THEN** Maaike receives a notification via her preferred delivery method

#### Scenario: No notification when mention preference disabled
- **GIVEN** a Person with NotificationPreference `commentMention: false`
- **WHEN** a Comment mentioning that person is saved
- **THEN** no notification is dispatched to that person

### Requirement: REQ-DC-003 Comment author can edit and delete own comment
The system SHALL allow a Comment author to update the `text` field of their own Comment
and to delete it. The `updatedAt` field SHALL be set on edit. Other users SHALL NOT be
able to edit another user's Comment. Governance body admins MAY delete any comment.
Deleted comments SHALL be removed from the thread display.

#### Scenario: Author edits own comment
- **GIVEN** a Comment authored by user U
- **WHEN** user U submits an edit with new `text`
- **THEN** the Comment `text` is updated and `updatedAt` reflects the edit time

#### Scenario: Non-author cannot edit comment
- **GIVEN** a Comment authored by user U
- **WHEN** user V (not an admin) attempts to update the comment text
- **THEN** the system returns HTTP 403

<!-- ============================================================ -->
<!-- Capability: email-integration                                 -->
<!-- ============================================================ -->

### Requirement: REQ-EI-001 Link email to decision dossier
The system SHALL allow an authenticated user to create an EmailLink object associating
a Nextcloud Mail email (identified by `emailUid` and `mailboxId`) with a Decision or
AgendaItem via an OpenRegister relation. The system SHALL store the email `subject`,
`from`, `receivedAt`, and a text snippet in the EmailLink object. The system SHALL set
the OpenRegister `_mail` metadata field on the target object to enable reverse linkage
visible in the Nextcloud Mail app sidebar.

#### Scenario: Email linked to decision
- **GIVEN** a Decision object and an email visible in Nextcloud Mail
- **WHEN** an authenticated user creates an EmailLink with the email UID and a reference to the Decision
- **THEN** the EmailLink is persisted in OpenRegister
- **THEN** the Decision's `_mail` metadata is updated with the email UID
- **THEN** the linked email appears in the Nextcloud Mail sidebar when the Decision is open

#### Scenario: Email integration degrades gracefully when Mail app absent
- **GIVEN** the Nextcloud Mail app is not installed
- **WHEN** a user opens a Decision detail page
- **THEN** the EmailDossierCard shows an empty state explaining that the Mail app is required
- **THEN** the "Link Email" button is disabled

### Requirement: REQ-EI-002 Linked emails visible in decision detail
The system SHALL render a dedicated EmailDossierCard on the DecisionDetailPage and
AgendaItemDetailPage displaying all EmailLinks associated with the object. Each row
SHALL show sender, subject, received date, and a text snippet. Clicking a row SHALL
open the email in the Nextcloud Mail app.

#### Scenario: Email dossier card shows linked emails
- **GIVEN** a Decision with 3 linked EmailLink objects
- **WHEN** a user opens the Decision detail page
- **THEN** the EmailDossierCard displays all 3 linked emails with sender, subject, and date

### Requirement: REQ-EI-003 Auto-suggest email linking by decision reference
The system SHALL provide an email picker that surfaces emails whose subject or body
contains a recognized decision reference pattern (e.g., `B-YYYY-NNN`, `Besluit-YYYY-NNN`)
as suggestions when a user opens the "Link Email" picker for a Decision.

#### Scenario: Email with matching reference surfaced as suggestion
- **GIVEN** a Decision with identifier `B-2026-042`
- **WHEN** a user opens the "Link Email" picker for that Decision
- **THEN** emails in the inbox whose subject contains `B-2026-042` appear at the top of the picker

<!-- ============================================================ -->
<!-- Capability: notification-preferences                          -->
<!-- ============================================================ -->

### Requirement: REQ-NP-001 User configures notification preferences per event type
The system SHALL provide a NotificationPreference object per Person, configurable via
the user settings modal (`NcAppSettingsDialog`). The preference SHALL include boolean
flags for: `meetingCreated`, `votingOpened`, `decisionPublished`, `taskAssigned`,
`commentMention`. Each preference SHALL specify a `deliveryMethod` enum:
`in-app`, `email`, `both`. Default preference SHALL have all flags true with
`deliveryMethod: in-app`.

#### Scenario: User saves notification preferences
- **GIVEN** a user opens the Notification Preferences section in settings
- **WHEN** they disable `meetingCreated` and set `deliveryMethod` to `email` and save
- **THEN** the updated NotificationPreference is persisted
- **THEN** subsequent meeting-created events do not produce in-app notifications for this user

#### Scenario: Default preferences created on first access
- **GIVEN** a Person with no NotificationPreference object
- **WHEN** the settings page loads the notification section
- **THEN** a NotificationPreference with all flags true and `deliveryMethod: in-app` is auto-created

### Requirement: REQ-NP-002 Notification delivery respects preference method
The system SHALL check the target person's NotificationPreference before dispatching
any notification. If `deliveryMethod` is `in-app`, only a Nextcloud notification SHALL
be sent. If `email`, only an email SHALL be sent via MailerService. If `both`, both SHALL
be sent. If a flag is false for the event type, no notification SHALL be dispatched
regardless of delivery method.

#### Scenario: In-app-only user does not receive email notification
- **GIVEN** a user with `deliveryMethod: in-app` and `taskAssigned: true`
- **WHEN** a task is assigned to them
- **THEN** a Nextcloud in-app notification is created
- **THEN** no email is dispatched

### Requirement: REQ-NP-003 Notifications suppressed during absence delegation
The system SHALL check whether the recipient has an active Delegation before dispatching
a task-assignment notification. If an active Delegation with a substitute exists, the
notification SHALL also be sent to the substitute, in addition to the original assignee,
using the substitute's own NotificationPreference.

#### Scenario: Substitute notified during active delegation
- **GIVEN** Person A has an active Delegation to substitute Person B
- **WHEN** a Task is assigned to Person A
- **THEN** Person A is notified per their preference
- **THEN** Person B is also notified per their own preference

<!-- ============================================================ -->
<!-- Capability: participant-engagement-tracking                   -->
<!-- ============================================================ -->

### Requirement: REQ-PE-001 Capture speech contributions during meeting
The system SHALL allow a meeting clerk (or chair) to create EngagementRecord entries
during an active meeting via the SpeechCaptureDialog. Each speech capture SHALL record
the participant (Person reference), start time, end time, spoken role (chair, member,
guest), and optional text snippet. Multiple speech entries per participant per meeting
SHALL be aggregated into a single EngagementRecord per participant.

#### Scenario: Speech captured and aggregated
- **GIVEN** an open meeting and a SpeechCaptureDialog
- **WHEN** the clerk records two speeches for Person P in the same meeting
- **THEN** both speeches are linked to a single EngagementRecord for P in that meeting
- **THEN** `speakingDuration` on the EngagementRecord equals the sum of both speech durations

#### Scenario: Speech references current agenda item
- **GIVEN** the meeting is currently on agenda item AI-3
- **WHEN** the clerk records a speech without changing the agenda item
- **THEN** the speech is automatically linked to AI-3 via an OpenRegister relation

### Requirement: REQ-PE-002 Capture questions raised and topics suggested
The system SHALL allow participants to raise questions (linked to a Motion or Decision)
and suggest topics for future meetings during the live meeting UI. Questions SHALL be
stored in the EngagementRecord `questionsRaised` array. Topic suggestions SHALL be stored
in `topicsSuggested`. Both SHALL reference the meeting and participant.

#### Scenario: Question captured and linked to motion
- **GIVEN** an open meeting where a motion M is being discussed
- **WHEN** the clerk captures a question from Person P referencing motion M
- **THEN** the question is added to P's EngagementRecord for that meeting
- **THEN** the question includes a relation to motion M

#### Scenario: Topic suggestion captured for future meeting
- **WHEN** a participant suggests the topic "Evaluatie parkeerbeleid binnenstad" for a future agenda
- **THEN** the topic is stored in the EngagementRecord `topicsSuggested` array
- **THEN** the topic appears in the agenda planning tool for the governance body's next meeting cycle

### Requirement: REQ-PE-003 Engagement summary shown in meeting minutes
The system SHALL render a MeetingEngagementSummary component in the minutes review view
(before the chair and secretary approve the minutes) showing all EngagementRecords for
the meeting. The summary SHALL display participant name, number of speeches, total
speaking duration, questions raised count, and topics suggested count. An `engagementScore`
(0–100 derived metric) SHALL be shown per participant.

#### Scenario: Engagement summary rendered before minutes approval
- **GIVEN** a meeting with EngagementRecords for 5 participants
- **WHEN** the secretary opens the minutes for review
- **THEN** the MeetingEngagementSummary shows one row per participant with speech count, duration, and score

<!-- ============================================================ -->
<!-- Capability: motion-coauthoring                               -->
<!-- ============================================================ -->

### Requirement: REQ-MC-001 Add and remove co-authors on a motion
The system SHALL allow the motion proposer (or governance body admin) to add and remove
co-authors on a Motion via the CoauthorList component. Co-authors SHALL be stored in
`Motion.coAuthors` as an array of Person references. Co-authors are distinct from
`Motion.coSigners`: co-authors have edit rights on the motion text; co-signers are
political endorsers only.

#### Scenario: Co-author added to motion
- **GIVEN** a Motion in `lifecycle: draft` with a proposer
- **WHEN** the proposer adds Person B as a co-author
- **THEN** Person B is added to `Motion.coAuthors`
- **THEN** Person B can edit the motion text

#### Scenario: Co-author cannot be added after motion is submitted
- **GIVEN** a Motion with `lifecycle: submitted`
- **WHEN** the proposer attempts to add a co-author
- **THEN** the system returns HTTP 422
- **THEN** the co-authors list is unchanged

### Requirement: REQ-MC-002 Co-author can edit motion text with version capture
The system SHALL allow any Person in `Motion.coAuthors` (or the proposer) to update
`Motion.text` while the lifecycle is `draft`. Each text update SHALL append a snapshot
to `Motion.versionHistory` containing the author's Person reference, timestamp, the full
text at that point, and an optional change summary. Motion editing SHALL be locked when
`lifecycle` advances beyond `draft`.

#### Scenario: Co-author edit captured in version history
- **GIVEN** a Motion in `lifecycle: draft` with co-author C
- **WHEN** C updates the motion text
- **THEN** the new text is persisted
- **THEN** a version snapshot is appended to `versionHistory` with C as author and current timestamp

#### Scenario: Non-author cannot edit motion text
- **GIVEN** a Motion in `lifecycle: draft`
- **WHEN** an authenticated user not in `coAuthors` and not the proposer attempts to update `text`
- **THEN** the system returns HTTP 403

### Requirement: REQ-MC-003 Overlapping edits flagged before save
The system SHALL detect when two co-authors have submitted text changes that overlap
on the same paragraph segment (same line range with different content) within the same
version window. Overlapping changes SHALL be flagged with HTTP 409 and return both
versions for manual resolution. Non-overlapping changes SHALL be merged automatically.

#### Scenario: Overlapping paragraph edits produce conflict response
- **GIVEN** co-authors A and B both loaded motion text version V at the same time
- **WHEN** A saves a change to paragraph 2 and then B attempts to save a change to the same paragraph 2
- **THEN** the system returns HTTP 409 with A's saved text and B's proposed text for comparison

#### Scenario: Non-overlapping edits merged automatically
- **GIVEN** co-authors A and B both loaded motion text version V
- **WHEN** A saves a change to paragraph 1 and B saves a change to paragraph 3
- **THEN** both changes are merged and persisted as a single new version

<!-- ============================================================ -->
<!-- Capability: participant-identification                        -->
<!-- ============================================================ -->

### Requirement: REQ-PI-001 Enhanced participant profile with official title and party
The system SHALL store `officialTitle` (string, e.g. "Wethouder Financiën") and
`partyAffiliation` (string, e.g. "GroenLinks") as optional fields on the Person schema.
The `officialTitle` and `partyAffiliation` SHALL be used as convenience denormalizations
refreshed by ParticipantService when the Person's active Membership or Post changes.
The canonical values remain on `Membership.party` and `Post.label` respectively. The
Person detail page SHALL provide a photo upload using `FileService` with the stored URL
in `Person.image` (Popolo: image).

#### Scenario: Official title and party shown on participant card
- **GIVEN** a Person with `officialTitle: "Wethouder Verkeer"` and `partyAffiliation: "D66"`
- **WHEN** the participant's profile card is rendered in the meeting participant list
- **THEN** both the title and party are displayed alongside the name and photo

#### Scenario: Photo upload stored and displayed
- **GIVEN** a Person detail page
- **WHEN** an admin uploads a JPG photo via the file attachment area
- **THEN** the photo URL is stored in `Person.image`
- **THEN** the photo is rendered in the ParticipantCard component across all views

### Requirement: REQ-PI-002 Participant quick-lookup by name, party, and role
The system SHALL provide a ParticipantLookupDialog with a search field supporting
name, party affiliation, and governance body role filters. Results SHALL display as
ParticipantCard components showing photo, name, officialTitle, partyAffiliation, and
primary contact method. The dialog SHALL be accessible from the meeting participant list,
motion proposer field, and vote result breakdown.

#### Scenario: Lookup by name returns matching participants
- **GIVEN** the ParticipantLookupDialog is open
- **WHEN** the user types "Vermeer" in the search field
- **THEN** all Persons with "Vermeer" in their name are returned as ParticipantCard results

#### Scenario: Lookup filtered by party
- **WHEN** the user applies a party filter "VVD"
- **THEN** only Persons with `partyAffiliation: "VVD"` are shown
- **THEN** results include photo, official title, and contact method

#### Scenario: Lookup accessible via keyboard navigation
- **WHEN** the ParticipantLookupDialog is open
- **THEN** the user can navigate results with arrow keys and activate a result with Enter
- **THEN** all interactive elements have visible focus indicators meeting WCAG 2.1 AA

---

## MODIFIED Requirements

<!-- ============================================================ -->
<!-- Capability: meeting-management                                -->
<!-- ============================================================ -->

### Requirement: REQ-MM-001 Meeting lifecycle includes engagement capture phase
The meeting lifecycle (draft → scheduled → opened → paused → adjourned → closed) SHALL
include an engagement capture phase active while the meeting is in `opened` state. During
this phase, the meeting detail view SHALL display the SpeechCaptureDialog launcher,
TopicSuggestionForm, and QuestionCapture accessible to clerk and chair roles. On
transition to `closed`, a MeetingEngagementSummary SHALL be rendered in the minutes
review view showing all EngagementRecords for the meeting before approval. The system
SHALL integrate the comment section from `discussion-and-comments` into the
AgendaItemDetailPage within the meeting context.

#### Scenario: Engagement capture available in opened state
- **GIVEN** a Meeting with `lifecycle: opened`
- **WHEN** a user with the `clerk` or `chair` role views the meeting detail page
- **THEN** the SpeechCaptureDialog launcher, QuestionCapture, and TopicSuggestionForm are visible and active

#### Scenario: Engagement UI hidden in closed state
- **GIVEN** a Meeting with `lifecycle: closed`
- **WHEN** any user views the meeting detail page
- **THEN** the SpeechCaptureDialog launcher and QuestionCapture are not visible

#### Scenario: Minutes review includes engagement summary
- **GIVEN** a Meeting transitioning to `closed` with 5 captured EngagementRecords
- **WHEN** the secretary opens the minutes review view
- **THEN** the MeetingEngagementSummary renders all 5 records before the approve button

<!-- ============================================================ -->
<!-- Capability: governance-bodies                                 -->
<!-- ============================================================ -->

### Requirement: REQ-GB-001 Person schema extended with identification fields
The Person entity SHALL be extended with two optional fields aligned with the
`participant-identification` capability: `officialTitle` (string — current formal title,
e.g. "Wethouder Ruimtelijke Ordening") and `partyAffiliation` (string — current political
party or faction, e.g. "GroenLinks"). Both fields are optional and default to null.
These are denormalized convenience fields; the canonical values are `Post.label` (via
active Membership) and `Membership.party` (Popolo `on_behalf_of`) respectively.
Schema.org annotation: `officialTitle` maps to `schema:jobTitle`; `partyAffiliation`
maps to `schema:affiliation`. The PersonDetailPage SHALL display both fields and allow
an admin to edit them.

#### Scenario: Person record shows official title and party
- **GIVEN** a Person with `officialTitle: "Raadslid"` and `partyAffiliation: "PvdA"`
- **WHEN** the PersonDetailPage is opened
- **THEN** both fields are displayed in the profile section

#### Scenario: Fields are optional and default null
- **GIVEN** a Person created before the p4-collaboration schema migration
- **WHEN** the Person is retrieved via the API
- **THEN** `officialTitle` and `partyAffiliation` are null (no error, non-breaking)

### Requirement: REQ-GB-002 Membership extended with post-based position lookup
The Membership schema SHALL support lookup by Post via the existing `Post` relation
(per ADR-000). The ParticipantLookupService SHALL use Membership-Post joins to support
role-based participant filtering (e.g. "find all current Chairs"). The governance body
member list SHALL display Post.label as the position label alongside Membership.role.

#### Scenario: Lookup by governance role returns post-holders
- **GIVEN** a GovernanceBody with 3 Memberships, one linked to Post `label: "Voorzitter"`
- **WHEN** a user filters participants by role `chair`
- **THEN** the member with the Voorzitter Post is returned

#### Scenario: Member list shows Post label
- **GIVEN** a Membership linked to Post with `label: "Secretaris"`
- **WHEN** the governance body member list is rendered
- **THEN** "Secretaris" is displayed as the member's position label
