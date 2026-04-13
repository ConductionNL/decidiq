# Specs: Minutes and Decisions

**Change:** p2-minutes-and-decisions
**App:** Decidesk
**Entities:** Minutes, Decision, ActionItem

---

## REQ-MIN: Minutes Lifecycle

### REQ-MIN-001 — View minutes list
A user can view a paginated, searchable list of all Minutes objects.

**GIVEN** the Minutes index page is opened
**WHEN** the page loads
**THEN** a list of minutes is displayed with columns: title, meeting (linked), lifecycle status, version, approvedAt
**AND** the list supports full-text search and filtering by lifecycle status
**AND** pagination controls are present

### REQ-MIN-002 — Create draft minutes
A secretary can create a new minutes record linked to a closed or in-progress meeting.

**GIVEN** the "Notulen aanmaken" button is clicked on the Minutes index page
**WHEN** the form is submitted with a title and a linked Meeting
**THEN** a new Minutes object is created with `lifecycle: draft` and `version: 1`
**AND** the user is redirected to the Minutes detail page

### REQ-MIN-003 — Generate draft content from meeting data
A secretary can auto-generate the initial minutes text from the linked meeting's agenda.

**GIVEN** a Minutes object is in `lifecycle: draft`
**WHEN** the user clicks "Concept genereren" on the Minutes detail page
**THEN** the backend `MinutesGenerationService` fetches the linked Meeting's AgendaItems, Motions, VotingRounds, and Decisions
**AND** a preview of the generated text is displayed before being applied
**AND** on confirmation, the `content` field is populated with the generated text
**AND** the `lifecycle` remains `draft`

### REQ-MIN-004 — Submit minutes for review
A secretary can submit draft minutes to the chair for review.

**GIVEN** a Minutes object is in `lifecycle: draft`
**WHEN** the user performs the "Ter goedkeuring indienen" lifecycle transition
**THEN** the `lifecycle` transitions to `review`
**AND** the change is recorded in the audit trail

### REQ-MIN-005 — Approve minutes
A chair or secretary can approve minutes under review.

**GIVEN** a Minutes object is in `lifecycle: review`
**WHEN** the user performs the "Goedkeuren" lifecycle transition
**THEN** the `lifecycle` transitions to `approved`
**AND** the approving user's display name is appended to the `signedBy` array
**AND** the `approvedAt` timestamp is set to the current date-time
**AND** the `version` is incremented by 1
**AND** the change is recorded in the audit trail

### REQ-MIN-006 — Digitally sign minutes
The chair and secretary sign the approved minutes.

**GIVEN** a Minutes object is in `lifecycle: approved`
**WHEN** the user performs the "Ondertekenen" lifecycle transition
**THEN** the `lifecycle` transitions to `signed`
**AND** the signing user's display name is appended to the `signedBy` array if not already present
**AND** the change is recorded in the audit trail

### REQ-MIN-007 — Publish minutes
A clerk publishes signed minutes to make them publicly available.

**GIVEN** a Minutes object is in `lifecycle: signed`
**WHEN** the user performs the "Publiceren" lifecycle transition
**THEN** the `lifecycle` transitions to `published`
**AND** the change is recorded in the audit trail

### REQ-MIN-008 — View minutes version history
A user can see the full revision history of a Minutes object.

**GIVEN** a Minutes detail page is open
**WHEN** the user opens the "Audit" tab in the sidebar (`CnObjectSidebar` → `CnAuditTrailTab`)
**THEN** all previous versions are listed with: changed fields, old value, new value, change timestamp, and user who made the change

### REQ-MIN-009 — Edit minutes content
A secretary can edit the `content` field of a Minutes object while it is in `draft` or `review` state.

**GIVEN** a Minutes object is in `lifecycle: draft` or `lifecycle: review`
**WHEN** the user edits the `content` field and saves
**THEN** the object is updated and the version number remains unchanged (version increments only on `approved` transition)
**AND** the change is recorded in the audit trail

---

## REQ-DEC: Decision Recording and Publication

### REQ-DEC-001 — Record a formal decision
A clerk can record a formal decision resulting from a vote.

**GIVEN** the "Besluit vastleggen" button is clicked on the Decisions index page
**WHEN** the form is submitted with title, text, decisionDate, and outcome (adopted / rejected)
**THEN** a new Decision object is created with `isPublished: false`
**AND** the user is redirected to the Decision detail page

### REQ-DEC-002 — Link decision to a motion
A decision can be linked to its source motion.

**GIVEN** a Decision is being created or edited
**WHEN** the user selects a Motion from the relation picker
**THEN** the Decision is linked to the Motion via the OpenRegister relation mechanism
**AND** the linked Motion is displayed on the Decision detail page

### REQ-DEC-003 — Link action items to a decision
A decision can have one or more action items associated with it.

**GIVEN** a Decision detail page is open
**WHEN** the user clicks "Actiepunt toevoegen" in the Action Items section
**THEN** a new ActionItem is created and linked to the Decision
**AND** the linked ActionItems are displayed in a table on the Decision detail page

### REQ-DEC-004 — Publish an adopted decision
A clerk can publish an adopted decision to make it publicly available.

**GIVEN** a Decision with `outcome: adopted` and `isPublished: false` is open
**WHEN** the user clicks "Publiceren"
**THEN** `isPublished` is set to `true`
**AND** `publishedAt` is set to the current date-time
**AND** the change is recorded in the audit trail
**AND** the "Publiceren" button is replaced with the publication timestamp

**GIVEN** a Decision with `outcome: rejected`
**WHEN** the user views the Decision detail page
**THEN** the "Publiceren" button is not shown (rejected decisions cannot be published)

### REQ-DEC-005 — Search and filter decisions
A user can search and filter the archive of decisions.

**GIVEN** the Decisions index page is open
**WHEN** the user enters a search term in the search bar
**THEN** decisions matching the term in title, text, or legalBasis are returned

**GIVEN** the Decisions index page is open
**WHEN** the user applies a filter on `outcome`
**THEN** only decisions with the selected outcome (adopted / rejected) are displayed

**GIVEN** the Decisions index page is open
**WHEN** the user applies a filter on `isPublished`
**THEN** only decisions with the selected publication state are displayed

### REQ-DEC-006 — Auto-archive adopted decisions
Adopted decisions are automatically archived and retrievable.

**GIVEN** a Decision with `outcome: adopted` and `isPublished: true` exists
**WHEN** a user searches the decision archive by topic, date range, or legal basis
**THEN** the decision is returned in search results
**AND** the full decision text, date, and outcome are accessible

---

## REQ-ACT: Action Item Tracking

### REQ-ACT-001 — Create an action item
A clerk can create an action item linked to a decision and/or meeting.

**GIVEN** the "Actiepunt aanmaken" button is clicked (either from the Action Items index or from a Decision detail page)
**WHEN** the form is submitted with title, assignee, dueDate, and optional description
**THEN** a new ActionItem is created with `taskStatus: open`
**AND** it is linked to the selected Decision and/or Meeting
**AND** the user is redirected to the ActionItem detail page

### REQ-ACT-002 — Update action item status
A user can update the status of an action item as work progresses.

**GIVEN** an ActionItem detail page is open
**WHEN** the user changes `taskStatus` from `open` to `in-progress`
**THEN** the status is updated and the change is recorded in the audit trail

**GIVEN** an ActionItem with `taskStatus: in-progress` is open
**WHEN** the user changes `taskStatus` to `completed`
**THEN** `completedAt` is set to the current date-time
**AND** the change is recorded in the audit trail

### REQ-ACT-003 — Detect and display overdue action items
Action items past their due date are automatically flagged as overdue.

**GIVEN** an ActionItem with `taskStatus: open` or `in-progress` and `dueDate < today`
**WHEN** the daily `OverdueActionItemsJob` background job runs
**THEN** the `taskStatus` is set to `overdue`

**GIVEN** an ActionItem with `dueDate < today` and `taskStatus: open` or `in-progress` is displayed in the list
**WHEN** the list renders
**THEN** the item is visually highlighted as overdue (e.g., `CnStatusBadge` in warning/error colour using Nextcloud CSS variables — no hardcoded colours)

### REQ-ACT-004 — Filter action items by status and assignee
A user can filter action items to find their assigned or overdue items.

**GIVEN** the Action Items index page is open
**WHEN** the user applies a filter on `taskStatus`
**THEN** only action items with the selected status are displayed

**GIVEN** the Action Items index page is open
**WHEN** the user applies a filter on `assignee`
**THEN** only action items assigned to the specified person are displayed

### REQ-ACT-005 — View action items linked to a decision
A user can see all action items that result from a specific decision.

**GIVEN** a Decision detail page is open
**WHEN** the user scrolls to the "Actiepunten" section
**THEN** all linked ActionItems are listed with: title, assignee, dueDate, taskStatus
**AND** clicking a row navigates to the ActionItem detail page

---

## REQ-DASH: Dashboard Extensions

### REQ-DASH-001 — Dashboard KPI: minutes awaiting approval
**GIVEN** the Dashboard page is open
**WHEN** the dashboard KPI widgets load
**THEN** a "Notulen ter goedkeuring" KPI card shows the count of Minutes objects with `lifecycle: review`

### REQ-DASH-002 — Dashboard KPI: published decisions
**GIVEN** the Dashboard page is open
**WHEN** the dashboard KPI widgets load
**THEN** a "Gepubliceerde besluiten" KPI card shows the count of Decision objects with `isPublished: true`

### REQ-DASH-003 — Dashboard KPI: open action items
**GIVEN** the Dashboard page is open
**WHEN** the dashboard KPI widgets load
**THEN** an "Open actiepunten" KPI card shows the count of ActionItems with `taskStatus: open` or `taskStatus: in-progress`

---

## Non-Functional Requirements

### REQ-NFR-001 — Accessibility (ADR-010)
All new views and dialogs MUST meet WCAG AA: keyboard-navigable, form fields labelled, colour not the sole status conveyor, alt text on status icons.

### REQ-NFR-002 — Internationalisation (ADR-007)
All user-visible strings in new views MUST use `t(appName, 'text')`. Dutch (nl) and English (en) translations MUST be provided.

### REQ-NFR-003 — Audit trail (ADR-001)
All lifecycle transitions on Minutes, all `isPublished` flag changes on Decisions, and all `taskStatus` changes on ActionItems MUST produce an audit trail entry via the OpenRegister built-in `AuditTrailService`.

### REQ-NFR-004 — No hardcoded colours (ADR-004 / ADR-010)
All status indicators (overdue badge, lifecycle badge) MUST use Nextcloud CSS variables. No hardcoded hex values.

### REQ-NFR-005 — Spec traceability (ADR-003)
Every new PHP class and public method introduced by this change MUST carry a `@spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-N` PHPDoc tag.
