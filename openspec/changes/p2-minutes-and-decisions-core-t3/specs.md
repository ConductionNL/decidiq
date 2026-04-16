# Specs: Minutes and Decisions — Core T3

**Change:** p2-minutes-and-decisions-core-t3
**App:** Decidesk
**Entities:** Minutes, Decision, ActionItem, Meeting

---

## REQ-MAA: Multi-Meeting Action Item Analytics

### REQ-MAA-001 — Display action item analytics KPIs on the Dashboard
A user can see a summary of action item status across all meetings on the Dashboard.

**GIVEN** the Dashboard page is open
**WHEN** the `ActionItemAnalyticsWidget` loads
**THEN** `ActionItemAnalyticsService::getSummary()` is called and returns: total open action items, total overdue items, items completed this month, and average days-to-close for the current calendar year
**AND** these four values are displayed as `CnStatsBlock` KPI cards in the analytics panel
**AND** the panel header shows the active date range ("Cijfers voor {jaar}")

### REQ-MAA-002 — Display per-meeting completion rate chart
A user can see the action item completion rate for recent meetings in a bar chart.

**GIVEN** the Dashboard page is open
**WHEN** the analytics panel renders the chart section
**THEN** `ActionItemAnalyticsService::getCompletionRates(limit: 6)` is called
**AND** a `CnChartWidget` bar chart displays the last 6 meetings with: x-axis = meeting title (truncated), y-axis = completion percentage (completed / total × 100)
**AND** meetings with zero action items are shown with a 0% bar and a tooltip "Geen actiepunten"

### REQ-MAA-003 — Display personal "My Action Items" grouped list
A logged-in user can see their own open action items grouped by urgency on the Dashboard.

**GIVEN** the Dashboard page is open and the current user is logged in
**WHEN** the personal list section renders
**THEN** `ActionItemAnalyticsService::getMyItems(userId)` returns all ActionItems where `assignee` matches the current user's display name and `taskStatus` is not `completed`
**AND** items are grouped: "Achterstallig" (overdue), "Deze week" (dueDate within 7 days), "Later"
**AND** each item shows: title, linked meeting name, dueDate, and a `CnStatusBadge` for `taskStatus`
**AND** the "Achterstallig" group uses the `--color-error` Nextcloud CSS variable for the group header (no hardcoded colours)

### REQ-MAA-004 — Navigate to action item detail from analytics list
A user can navigate from an analytics list item to the full ActionItem detail.

**GIVEN** the "My Action Items" list on the Dashboard is visible with at least one entry
**WHEN** the user clicks on an action item row
**THEN** the router navigates to `ActionItemDetail` with the item's `id` as route parameter
**AND** the browser URL updates to `/apps/decidesk/action-items/{id}`

---

## REQ-LDR: Live Decision Recording

### REQ-LDR-001 — Record a decision in real time during an active meeting
A secretary can create a Decision object directly from the Meeting detail page while the meeting is active.

**GIVEN** a Meeting detail page is open with `lifecycle: opened`
**WHEN** the user clicks the "Besluiten" tab on the Meeting detail page
**THEN** a `LiveDecisionPanel.vue` is rendered showing a quick-entry form with fields: title (required), text (required), outcome (adopted/rejected, required), legalBasis (optional)
**AND** a "Opslaan" button saves the Decision via `ObjectService.saveObject()` with a relation to the parent Meeting
**AND** on success the new Decision appears in the panel's list immediately

**GIVEN** the current user submits the quick-entry form with an empty `title`
**WHEN** "Opslaan" is clicked
**THEN** an inline validation error "Titel is verplicht" is shown
**AND** no save operation is performed

### REQ-LDR-002 — Auto-create draft Minutes when the first decision is recorded
When the first decision is recorded in a meeting, a draft Minutes object is automatically created.

**GIVEN** a Meeting in `lifecycle: opened` has no linked Minutes object
**WHEN** the secretary saves the first Decision via the live panel
**THEN** a new Minutes object is created with:
  - `title: "Concept notulen — {meeting.title}"`
  - `lifecycle: draft`
  - `version: 1`
**AND** an OpenRegister relation is created from the Minutes to the Meeting
**AND** a success notification displays "Conceptnotulen aangemaakt voor deze vergadering"

**GIVEN** a Meeting already has a linked Minutes object
**WHEN** the secretary saves another Decision via the live panel
**THEN** no additional Minutes object is created
**AND** the new Decision is linked to the existing Minutes via OpenRegister relation

### REQ-LDR-003 — Live panel is disabled for non-active meetings
The live decision panel is not available when the meeting is not in the opened state.

**GIVEN** a Meeting detail page is open with `lifecycle` set to `draft`, `scheduled`, `paused`, `adjourned`, or `closed`
**WHEN** the user views the "Besluiten" tab
**THEN** the quick-entry form is replaced with a read-only list of linked Decisions
**AND** a status notice is shown: "Live invoer beschikbaar wanneer de vergadering geopend is"
**AND** no "Opslaan" button is visible

---

## REQ-ALV: ALV Minutes Template and Distribution

### REQ-ALV-001 — Generate ALV minutes from Dutch general assembly template
A secretary can generate a structured Dutch ALV minutes draft for a General Assembly meeting.

**GIVEN** a Minutes detail page is open and the linked Meeting has `meetingType` containing `alv` or `algemene-ledenvergadering`
**WHEN** the user clicks "Genereer ALV-notulen"
**THEN** `ALVMinutesService::generateALVDraft(minutesId)` is called
**AND** the service fetches: the linked Meeting (title, scheduledDate, quorumRequired), AgendaItems ordered by `orderNumber`, Participants of the linked GovernanceBody, and any linked Decisions
**AND** the generated Dutch ALV template is populated with: opening (datum, aanvangstijd, voorzitter), quorum statement ("X van Y leden aanwezig — quorum {behaald|niet behaald}"), agenda items as sections, adopted resolutions with vote totals, and a "Rondvraag en sluiting" section
**AND** a preview dialog opens showing the generated content before applying
**AND** on user confirmation, the Minutes `content` field is updated via `ObjectService.saveObject()`

**GIVEN** the linked Meeting has `meetingType` that does not contain `alv` or `algemene-ledenvergadering`
**WHEN** the user clicks "Genereer ALV-notulen"
**THEN** a validation notice is shown: "Deze vergadering is niet gekwalificeerd als ALV — gebruik 'Concept genereren' voor standaard notulen"
**AND** no content is generated or applied

### REQ-ALV-002 — Distribute approved ALV minutes to members
A secretary can distribute approved Minutes to all active members of the GovernanceBody via Nextcloud notifications.

**GIVEN** a Minutes detail page is open with `lifecycle: approved` or `lifecycle: signed`
**WHEN** the user clicks "Distribueren aan leden"
**THEN** `ALVMinutesService::distribute(minutesId)` is called
**AND** a preview dialog shows the recipient list: all Participants of the linked GovernanceBody where `leftAt` is null
**AND** on user confirmation, a Nextcloud notification is sent to each recipient with: subject "{meeting title} — {minutes title}", body "De notulen zijn beschikbaar. Klik hier om ze te bekijken.", and a deep link to the Minutes detail page
**AND** a success notification shows "Notulen verzonden aan {N} leden"

**GIVEN** the linked GovernanceBody has no active Participants
**WHEN** the distribution preview dialog opens
**THEN** a warning is shown: "Geen actieve leden gevonden — controleer de ledenlijst van dit bestuur"
**AND** the confirm button is disabled

### REQ-ALV-003 — Distribution blocked for unapproved minutes
Minutes in `draft` or `review` state cannot be distributed.

**GIVEN** a Minutes detail page is open with `lifecycle: draft` or `lifecycle: review`
**WHEN** the user views the Minutes action buttons
**THEN** the "Distribueren aan leden" button is not visible or is visibly disabled
**AND** a tooltip or inline notice explains: "Notulen moeten goedgekeurd zijn voor distributie"

---

## REQ-MAW: Minutes Approval Workflow Notifications

### REQ-MAW-001 — Submit minutes for approval and notify approvers
A secretary can formally submit draft minutes for approval with automatic notifications.

**GIVEN** a Minutes detail page is open with `lifecycle: draft`
**WHEN** the user clicks "Ter goedkeuring indienen"
**THEN** the `lifecycle` is transitioned from `draft` to `review` via `WorkflowEngineController`
**AND** `NotificationService` sends notifications to all users with active Membership `role` of `chair` or `secretary` in the linked GovernanceBody
**AND** each notification contains: title "Notulen ter goedkeuring: {minutes.title}", body "Klik om de notulen te bekijken en goed te keuren.", and a deep link to the Minutes detail page
**AND** the button label changes to "In behandeling" (disabled) after the transition

**GIVEN** no GovernanceBody is linked to the Minutes' parent Meeting
**WHEN** "Ter goedkeuring indienen" is clicked
**THEN** the lifecycle transitions to `review`
**AND** a warning notification is shown to the current user: "Geen bestuur gekoppeld — goedkeurers konden niet automatisch worden gewaarschuwd"

### REQ-MAW-002 — Notify submitter when minutes are approved or rejected
The secretary is notified when the chair or secretary approves or rejects the submitted minutes.

**GIVEN** a Minutes object is in `lifecycle: review`
**WHEN** a user with role `chair` or `secretary` transitions the lifecycle to `approved`
**THEN** a Nextcloud notification is sent to the user who last set `lifecycle: review` (identified from the audit trail) with message "Notulen goedgekeurd: {minutes.title}"
**AND** the `approvedAt` field is set to the current timestamp
**AND** the approving user's display name is appended to the `signedBy` array

**GIVEN** a Minutes object is in `lifecycle: review`
**WHEN** a user with role `chair` or `secretary` transitions the lifecycle back to `draft` (rejection)
**THEN** a Nextcloud notification is sent to the submitter with message "Notulen teruggestuurd: {minutes.title} — zie notities voor toelichting"

### REQ-MAW-003 — Approval action is restricted to chair and secretary roles
Only authorised users can approve or reject submitted minutes.

**GIVEN** a Minutes detail page is open with `lifecycle: review`
**WHEN** the current user does NOT have role `chair` or `secretary` in the linked GovernanceBody
**THEN** the "Goedkeuren" and "Terugsturen" action buttons are not visible
**AND** a read-only banner is shown: "In behandeling — wacht op goedkeuring door voorzitter of secretaris"

---

## REQ-AAI: Auto-Extract Action Items from Minutes

### REQ-AAI-001 — Extract action item candidates from minutes content
A secretary can automatically extract action item candidates from the minutes text.

**GIVEN** a Minutes detail page is open with `lifecycle: draft`, `review`, or `approved`
**WHEN** the user clicks "Actiepunten extraheren"
**THEN** `ActionItemExtractionService::extractFromContent(minutesContent)` is called
**AND** the service parses the `content` string for lines matching patterns: starting with `Actie:`, `AI:`, `Taak:`, `Actiepunt:`, or containing `wordt verzocht`, `zal worden`, `is toegezegd`
**AND** a preview modal opens listing each candidate with: extracted title (editable), suggested assignee (if a known Participant name is detected in the line), suggested dueDate (empty by default), and a checkbox (checked by default)
**AND** unchecked candidates are not saved

**GIVEN** the minutes `content` contains no action item markers
**WHEN** the user clicks "Actiepunten extraheren"
**THEN** a notice is shown: "Geen actiepunten gevonden in de notulen — gebruik de standaard CRUD om actiepunten handmatig toe te voegen"
**AND** no modal opens

### REQ-AAI-002 — Save confirmed action items linked to the Minutes
Confirmed action item candidates are saved and linked to the parent Minutes.

**GIVEN** the extraction preview modal is open with at least one candidate checked
**WHEN** the user clicks "Geselecteerde actiepunten opslaan"
**THEN** for each checked candidate, an ActionItem is created via `ObjectService.saveObject()` with: `title` from the edited candidate, `assignee` (if provided), `dueDate` (if provided), `taskStatus: open`
**AND** each ActionItem is linked to the parent Minutes via OpenRegister relation
**AND** a success notification shows "{N} actiepun{ten|t} aangemaakt"
**AND** the modal closes

### REQ-AAI-003 — Extracted action items appear in the Minutes linked items section
Newly created action items are visible on the Minutes detail page.

**GIVEN** action items have been extracted and saved for a Minutes object
**WHEN** the user views the "Actiepunten" section on the Minutes detail page
**THEN** all linked ActionItems are listed with: title, assignee, dueDate, and `taskStatus` badge
**AND** overdue items display the `overdue` status badge (red using `--color-error`)

---

## REQ-DRT: Decision Rationale Documentation

### REQ-DRT-001 — Capture decision rationale in the Overwegingen section
A user can record the reasoning and considerations behind a formal decision.

**GIVEN** a Decision detail page is open in edit mode
**WHEN** the user opens the "Overwegingen en motivering" section
**THEN** a plain-text input field is shown labelled "Overwegingen"
**AND** saving the value creates or updates a note in the Decision's `notes` array with `label: "overwegingen"` via `ObjectService.saveObject()`
**AND** the note is stored as plain text within the built-in notes structure

**GIVEN** a Decision detail page is open in view mode and the Decision has a note with `label: "overwegingen"`
**WHEN** the user views the "Overwegingen en motivering" section
**THEN** the rationale text is displayed in a `CnDetailCard` section labelled "Overwegingen en motivering"
**AND** the section is collapsed by default if the rationale is empty

### REQ-DRT-002 — Decision rationale is included in generated minutes
When minutes are generated, the decision rationale is included in the output.

**GIVEN** a Minutes object is linked to a Meeting that has Decisions with rationale notes
**WHEN** `MinutesGenerationService::generateDraft()` is called
**THEN** for each Decision included in the generated minutes, the `overwegingen` note (if present) is appended below the decision text as "Overwegingen: {rationale}"
**AND** if no rationale note exists for a Decision, the overwegingen section is omitted for that decision

### REQ-DRT-003 — Rationale is included in ALV minutes template
ALV minutes generation includes the rationale for each adopted resolution.

**GIVEN** `ALVMinutesService::generateALVDraft()` is called and the meeting has Decisions with rationale notes
**WHEN** the ALV template renders each agenda item's resolution
**THEN** each adopted resolution includes "Overwegingen:" followed by the rationale text if a `notes` entry with `label: "overwegingen"` exists on the linked Decision
**AND** rejected motions do not include an overwegingen section

---

## REQ-DNT: Decision Notification Dispatch

### REQ-DNT-001 — Notify configured recipients when a decision is published
Stakeholders receive a Nextcloud notification when an adopted decision is published.

**GIVEN** a Decision has `isPublished: false` and a user clicks "Publiceren" (from p2-minutes-and-decisions)
**WHEN** `DecisionService` sets `isPublished: true` and saves via `ObjectService.saveObject()`
**THEN** `DecisionNotificationService::notifyOnPublish(decisionId)` is called
**AND** recipients are resolved from Memberships of the linked GovernanceBody with `role` in the `decision_notify_roles` config (default: `chair`, `secretary`, `member`)
**AND** each resolved recipient receives a Nextcloud notification with: title "Besluit gepubliceerd: {decision.title}", body "{outcome} — {decisionDate}", and a deep link to the Decision detail page
**AND** if the GovernanceBody has no linked Memberships, the notification is not dispatched and a warning is logged

### REQ-DNT-002 — Notification recipients are configurable
An administrator can configure which roles receive decision publication notifications.

**GIVEN** the admin settings page is open under "Besluitnotificaties"
**WHEN** the administrator selects or deselects roles from the `decision_notify_roles` multi-select (options: chair, secretary, member, observer)
**AND** saves via `POST /api/settings`
**THEN** the `decision_notify_roles` value is saved via `IAppConfig`
**AND** subsequent decision publications use the updated role set

### REQ-DNT-003 — Notification is not sent for rejected or non-published decisions
The notification is only triggered for the explicit publish action.

**GIVEN** a Decision has `outcome: rejected`
**WHEN** any user views or edits the Decision
**THEN** no publication notification is triggered (rejected decisions are never published)

**GIVEN** a Decision has `isPublished: false` and is updated (not published)
**WHEN** `ObjectService.saveObject()` is called for a non-publication change (e.g. title edit)
**THEN** no notification is dispatched
**AND** `DecisionNotificationService::notifyOnPublish()` is NOT called

---

## Non-Functional Requirements

### REQ-NFR-001 — Accessibility (ADR-010)
All new views, panels, modals, and widgets introduced by this change MUST meet WCAG 2.1 AA: keyboard-navigable, form fields labelled with `aria-label` or `<label>`, colour not the sole status conveyor (overdue group header uses text label AND colour), alt text on chart icons.

### REQ-NFR-002 — Internationalisation (ADR-007)
All user-visible strings in new views and components MUST use `t(appName, 'text')`. Dutch (nl) and English (en) translations MUST be provided for all new strings. Chart axis labels and group headings must be translated.

### REQ-NFR-003 — No hardcoded colours (ADR-004 / ADR-010)
Analytics group headers (overdue = error, this-week = warning, later = default) MUST use Nextcloud CSS variables (`--color-error`, `--color-warning`). No hardcoded hex values or `--nldesign-*` tokens.

### REQ-NFR-004 — Spec traceability (ADR-003)
Every new PHP class and public method introduced by this change MUST carry a `@spec openspec/changes/p2-minutes-and-decisions-core-t3/tasks.md#task-N` PHPDoc tag.

### REQ-NFR-005 — No custom CRUD code (ADR-001)
ActionItem creation, Decision saving, and Minutes content updates MUST use `ObjectService`. No custom Mapper or Controller logic for data persistence beyond routing.

### REQ-NFR-006 — SPDX headers (ADR-014)
Every new `.php`, `.vue`, and `.js` file MUST include the SPDX licence header (`// SPDX-License-Identifier: EUPL-1.2` for PHP/JS, `<!-- SPDX-License-Identifier: EUPL-1.2 -->` for Vue).

### REQ-NFR-007 — Live panel gate enforced on backend
The live decision recording endpoint (`POST /api/meetings/{id}/live-decisions`) MUST verify the Meeting `lifecycle` is `opened` on the backend via `ObjectService.findObject()`. Frontend-only gating is insufficient (ADR-005).
