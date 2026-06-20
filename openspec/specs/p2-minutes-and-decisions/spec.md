---
status: done
---

# p2-minutes-and-decisions Specification

## Purpose
Manages the recording, approval, signing, and publication of meeting minutes and formal decisions, including draft generation from meeting agenda data and a full lifecycle visualisation with audit trail. The capability also tracks action items through their status lifecycle with overdue detection, links decisions to source motions and action items, and surfaces minutes, decisions, and action-item counts as dashboard KPIs.

## Requirements

### Requirement: REQ-ML-001 View minutes list
The system SHALL display a paginated, searchable list of all Minutes objects with columns for title, lifecycle status, version, and approvedAt. Full-text search and filtering by `lifecycle` status SHALL be supported via `CnFilterBar`.

#### Scenario: List loads with default pagination
- **GIVEN** the Minutes index page is opened
- **WHEN** the page loads
- **THEN** a list of minutes is displayed with columns: title, lifecycle (`CnStatusBadge`), version, approvedAt
- **AND** pagination controls are present
- **AND** full-text search and lifecycle filter are available via `CnFilterBar`

#### Scenario: Filter by lifecycle
- **WHEN** the user applies the `lifecycle` filter with value `review`
- **THEN** only Minutes objects with `lifecycle: review` are displayed

### Requirement: REQ-ML-002 Create draft minutes
The system SHALL allow a secretary to create a new Minutes record with `lifecycle: draft` and `version: 1`, linked to a Meeting via OpenRegister relation.

#### Scenario: Successful creation
- **GIVEN** the Minutes index page is open
- **WHEN** the user clicks "Notulen aanmaken" and submits a form with a title and linked Meeting
- **THEN** a new Minutes object is created with `lifecycle: draft` and `version: 1`
- **AND** the user is redirected to the Minutes detail page

### Requirement: REQ-ML-003 Submit minutes for review
The system SHALL allow a secretary to transition draft minutes to `review` state via a "Ter goedkeuring indienen" action. The transition SHALL be recorded in the audit trail.

#### Scenario: Submit for review
- **GIVEN** a Minutes object with `lifecycle: draft` is open
- **WHEN** the user performs the "Ter goedkeuring indienen" lifecycle transition
- **THEN** `lifecycle` transitions to `review`
- **AND** an audit trail entry is created recording the transition, user, and timestamp

### Requirement: REQ-ML-004 Approve minutes
The system SHALL allow a chair or secretary to approve minutes in `review` state. On approval the `approvedAt` timestamp SHALL be set and the approver's display name SHALL be appended to `signedBy`. `version` SHALL increment by 1.

#### Scenario: Approve
- **GIVEN** a Minutes object with `lifecycle: review` is open
- **WHEN** the user performs the "Goedkeuren" lifecycle transition
- **THEN** `lifecycle` transitions to `approved`
- **AND** the approving user's `displayName` is appended to `signedBy`
- **AND** `approvedAt` is set to the current date-time
- **AND** `version` is incremented by 1
- **AND** an audit trail entry is created

### Requirement: REQ-ML-005 Digitally sign minutes
The system SHALL allow the chair and secretary to sign approved minutes. The signer's display name SHALL be appended to `signedBy` if not already present. Signing transitions `lifecycle` to `signed`.

#### Scenario: Sign minutes
- **GIVEN** a Minutes object with `lifecycle: approved` is open
- **WHEN** the user performs the "Ondertekenen" lifecycle transition
- **THEN** `lifecycle` transitions to `signed`
- **AND** the signing user's `displayName` is appended to `signedBy` if not already present
- **AND** an audit trail entry is created

### Requirement: REQ-ML-006 Publish minutes
The system SHALL allow a clerk to publish signed minutes, transitioning `lifecycle` to `published`.

#### Scenario: Publish signed minutes
- **GIVEN** a Minutes object with `lifecycle: signed` is open
- **WHEN** the user performs the "Publiceren" lifecycle transition
- **THEN** `lifecycle` transitions to `published`
- **AND** an audit trail entry is created

### Requirement: REQ-ML-007 Lifecycle visualisation
The Minutes detail page SHALL display the lifecycle progression (`draft → review → approved → signed → published`) using `CnTimelineStages`. Only transitions permitted from the current state SHALL be shown as active buttons.

#### Scenario: Correct buttons shown for draft state
- **GIVEN** a Minutes object with `lifecycle: draft` is open on the detail page
- **WHEN** the detail page renders
- **THEN** only the "Ter goedkeuring indienen" transition button is shown as actionable
- **AND** "Goedkeuren", "Ondertekenen", and "Publiceren" are shown as future stages (inactive)

### Requirement: REQ-ML-008 View minutes version history
The system SHALL provide full revision history via the `CnAuditTrailTab` in the `CnObjectSidebar`, showing changed fields, old and new values, change timestamp, and the user who made the change.

#### Scenario: Audit tab shows history
- **GIVEN** a Minutes detail page is open
- **WHEN** the user opens the "Audit" tab in `CnObjectSidebar`
- **THEN** all previous lifecycle transitions and content edits are listed with: changed field, old value, new value, timestamp, and user

### Requirement: REQ-ML-009 Edit minutes content during draft or review
The system SHALL allow a secretary to edit the `content` field while `lifecycle` is `draft` or `review`. The version number SHALL NOT increment on content edits — only on the `approved` transition.

#### Scenario: Edit content field
- **GIVEN** a Minutes object with `lifecycle: draft` or `lifecycle: review`
- **WHEN** the user edits the `content` field and saves
- **THEN** the object is updated with the new content
- **AND** `version` is unchanged
- **AND** the change is recorded in the audit trail

<!-- ============================================================ -->
<!-- Capability: minutes-generation                               -->
<!-- OCP: IJob via MinutesGenerationService (domain-specific)    -->
<!-- ============================================================ -->

### Requirement: REQ-MG-001 Generate minutes draft from meeting data
The system SHALL provide a "Concept genereren" action on the Minutes detail page that calls `MinutesGenerationService::generateDraft()`. The service SHALL compile AgendaItems (ordered by `orderNumber`), Motions, VotingRounds, and Decisions from the linked Meeting into a structured Dutch prose template and return a preview before writing to `content`.

#### Scenario: Successful generation with preview
- **GIVEN** a Minutes object with `lifecycle: draft` is open and linked to a Meeting with agenda data
- **WHEN** the user clicks "Concept genereren"
- **THEN** `MinutesGenerationService::generateDraft()` is called via `POST /api/minutes/{minutesId}/generate-draft`
- **AND** a preview modal (`NcDialog`) is shown with the generated Dutch text
- **AND** `lifecycle` remains `draft` until the user explicitly saves

#### Scenario: User confirms and content is saved
- **GIVEN** the preview modal is shown with generated text
- **WHEN** the user confirms
- **THEN** the `content` field is updated with the generated text
- **AND** an audit trail entry is created

#### Scenario: Meeting has no agenda items
- **GIVEN** the linked Meeting has no AgendaItems
- **WHEN** `MinutesGenerationService::generateDraft()` is called
- **THEN** a minimal Dutch template is returned (meeting metadata only, no agenda sections)
- **AND** no error is thrown

#### Scenario: No linked meeting
- **GIVEN** the Minutes object has no linked Meeting relation
- **WHEN** `MinutesGenerationService::generateDraft()` is called
- **THEN** a descriptive exception is thrown and the API returns HTTP 422 with an error message

<!-- ============================================================ -->
<!-- Capability: decision-recording                               -->
<!-- Schema.org: custom:Decision (ORI: meeting:DecisionItem)     -->
<!-- ORI API fields: title, text, decisionDate, outcome          -->
<!-- ============================================================ -->

### Requirement: REQ-DR-001 Record a formal decision
The system SHALL allow a clerk to create a Decision linked to a Meeting, specifying title, text, decisionDate, outcome (adopted / rejected), and optional legalBasis. The Decision SHALL be created with `isPublished: false`.

#### Scenario: Successful decision creation
- **GIVEN** the Decisions index page is open
- **WHEN** the user clicks "Besluit vastleggen" and submits a form with title, text, decisionDate, and outcome
- **THEN** a new Decision object is created with `isPublished: false`
- **AND** the user is redirected to the Decision detail page

### Requirement: REQ-DR-002 Link decision to source motion
The system SHALL allow linking a Decision to its source Motion via the OpenRegister relation mechanism. The linked Motion SHALL be displayed on the Decision detail page.

#### Scenario: Link motion during creation
- **GIVEN** a Decision is being created or edited
- **WHEN** the user selects a Motion from the relation picker
- **THEN** the Decision is linked to the Motion via OpenRegister relations
- **AND** the linked Motion title and outcome are displayed in the Decision detail view

### Requirement: REQ-DR-003 Link action items to a decision
The system SHALL allow creating and viewing ActionItems linked to a Decision. The Decision detail page SHALL display a table of linked ActionItems with title, assignee, dueDate, and taskStatus. Clicking a row SHALL navigate to the ActionItem detail page.

#### Scenario: Create action item from decision
- **GIVEN** a Decision detail page is open
- **WHEN** the user clicks "Actiepunt toevoegen"
- **THEN** a new ActionItem is created linked to the Decision
- **AND** the ActionItem appears in the "Actiepunten" table on the Decision detail page

#### Scenario: Navigate to action item
- **GIVEN** the Decision detail page shows a linked ActionItem in the table
- **WHEN** the user clicks the ActionItem row
- **THEN** the user is navigated to the ActionItem detail page

<!-- ============================================================ -->
<!-- Capability: decision-publication                             -->
<!-- ORI API: isPublished flag; publishedAt ISO 8601             -->
<!-- ============================================================ -->

### Requirement: REQ-DP-001 Publish an adopted decision
The system SHALL display a "Publiceren" button on Decision detail pages where `outcome: adopted` and `isPublished: false`. Clicking SHALL set `isPublished: true` and `publishedAt` to the current date-time via `ObjectService::saveObject()`. The change SHALL be recorded in the audit trail.

#### Scenario: Publish adopted decision
- **GIVEN** a Decision with `outcome: adopted` and `isPublished: false` is open
- **WHEN** the user clicks "Publiceren"
- **THEN** `isPublished` is set to `true`
- **AND** `publishedAt` is set to the current ISO 8601 date-time
- **AND** an audit trail entry is created recording the publication action
- **AND** the "Publiceren" button is replaced with the formatted publication timestamp

#### Scenario: Rejected decisions cannot be published
- **GIVEN** a Decision with `outcome: rejected`
- **WHEN** the user views the Decision detail page
- **THEN** the "Publiceren" button is NOT shown

#### Scenario: Already published decision
- **GIVEN** a Decision with `isPublished: true`
- **WHEN** the user views the Decision detail page
- **THEN** the publication timestamp is displayed and no "Publiceren" button is shown

<!-- ============================================================ -->
<!-- Capability: decision-archive                                 -->
<!-- ORI API: full-text search; facets by outcome, isPublished   -->
<!-- ============================================================ -->

### Requirement: REQ-DA-001 Search decisions by full-text
The system SHALL provide full-text search across Decision objects via `IndexService`, matching against `title`, `text`, and `legalBasis` fields. Results SHALL be displayed in the Decisions index page.

#### Scenario: Full-text search
- **GIVEN** the Decisions index page is open
- **WHEN** the user enters a search term in the search bar
- **THEN** only Decisions matching the term in title, text, or legalBasis are returned

### Requirement: REQ-DA-002 Filter decisions by outcome and publication status
The system SHALL support faceted filtering of Decisions by `outcome` (adopted / rejected) and `isPublished` (true / false) via `CnFilterBar` + `CnFacetSidebar`.

#### Scenario: Filter by outcome
- **GIVEN** the Decisions index page is open
- **WHEN** the user applies the `outcome` filter with value `adopted`
- **THEN** only Decisions with `outcome: adopted` are displayed

#### Scenario: Filter by publication status
- **WHEN** the user applies the `isPublished` filter with value `true`
- **THEN** only published Decisions are displayed

### Requirement: REQ-DA-003 Archive adopted decisions for long-term retrieval
Adopted and published Decisions SHALL be permanently stored in OpenRegister and retrievable by topic, date range, governance body, and legal basis without time limit.

#### Scenario: Historical decision is retrievable
- **GIVEN** a Decision with `outcome: adopted` and `isPublished: true` was created over one year ago
- **WHEN** a user searches by a keyword from the decision title
- **THEN** the decision is returned in search results
- **AND** the full text, decisionDate, outcome, and legalBasis are accessible

<!-- ============================================================ -->
<!-- Capability: action-item-tracking                             -->
<!-- Schema.org: caldav:VTODO                                     -->
<!-- OCP: IJob (OverdueActionItemsJob) for scheduled detection   -->
<!-- ============================================================ -->

### Requirement: REQ-AIT-001 Create an action item
The system SHALL allow a clerk to create an ActionItem with title, assignee, dueDate, and optional description. The ActionItem SHALL be created with `taskStatus: open` and MAY be linked to a Decision and/or Meeting via OpenRegister relations.

#### Scenario: Create from action items index
- **GIVEN** the Action Items index page is open
- **WHEN** the user clicks "Actiepunt aanmaken" and submits a form with title, assignee, and dueDate
- **THEN** a new ActionItem is created with `taskStatus: open`
- **AND** the user is redirected to the ActionItem detail page

### Requirement: REQ-AIT-002 Update action item status
The system SHALL allow updating ActionItem `taskStatus` through the lifecycle: `open → in-progress → completed`. Setting `taskStatus: completed` SHALL automatically set `completedAt` to the current date-time. All status changes SHALL be recorded in the audit trail.

#### Scenario: Progress to in-progress
- **GIVEN** an ActionItem with `taskStatus: open` is open on the detail page
- **WHEN** the user clicks "In behandeling"
- **THEN** `taskStatus` is updated to `in-progress`
- **AND** an audit trail entry is created

#### Scenario: Complete an action item
- **GIVEN** an ActionItem with `taskStatus: in-progress` is open
- **WHEN** the user clicks "Afgerond"
- **THEN** `taskStatus` is set to `completed`
- **AND** `completedAt` is set to the current date-time
- **AND** an audit trail entry is created

### Requirement: REQ-AIT-003 Detect overdue action items via background job
The system SHALL run a daily `OverdueActionItemsJob` (`IJob`) that queries all ActionItems with `taskStatus: open` or `in-progress` and `dueDate < now()` and sets `taskStatus: overdue` via `ObjectService::saveObject()`.

#### Scenario: Background job marks overdue items
- **GIVEN** an ActionItem with `taskStatus: open` and `dueDate` in the past
- **WHEN** `OverdueActionItemsJob` runs
- **THEN** `taskStatus` is set to `overdue`
- **AND** the change is recorded in the audit trail

#### Scenario: Completed items are not modified
- **GIVEN** an ActionItem with `taskStatus: completed` and `dueDate` in the past
- **WHEN** `OverdueActionItemsJob` runs
- **THEN** the `taskStatus` remains `completed` and is not modified

#### Scenario: Items without dueDate are not modified
- **GIVEN** an ActionItem with `taskStatus: open` and no `dueDate`
- **WHEN** `OverdueActionItemsJob` runs
- **THEN** the `taskStatus` remains `open`

### Requirement: REQ-AIT-004 Display overdue indicator in list
The system SHALL compute and display a visual overdue indicator (client-side) for ActionItems where `dueDate < today` and `taskStatus !== completed`. The indicator SHALL use `CnStatusBadge` with a Nextcloud CSS variable (`--color-error`) — no hardcoded colour values.

#### Scenario: Overdue badge shown in list
- **GIVEN** an ActionItem with `dueDate < today` and `taskStatus: open` or `in-progress`
- **WHEN** the Action Items index page renders
- **THEN** the item row displays an overdue `CnStatusBadge` using `--color-error`

### Requirement: REQ-AIT-005 Filter action items by status and assignee
The system SHALL support filtering ActionItems by `taskStatus` and `assignee` via `CnFilterBar`.

#### Scenario: Filter by taskStatus
- **WHEN** the user applies the `taskStatus` filter with value `overdue`
- **THEN** only ActionItems with `taskStatus: overdue` are displayed

#### Scenario: Filter by assignee
- **WHEN** the user applies the `assignee` filter
- **THEN** only ActionItems assigned to the specified person are displayed

<!-- ============================================================ -->
<!-- Capability: dashboard KPI extensions                        -->
<!-- Platform: CnStatsBlock / CnStatsPanel (no custom layout)   -->
<!-- ============================================================ -->

### Requirement: REQ-DASH-001 Dashboard KPI: minutes awaiting approval
The Dashboard SHALL display a "Notulen ter goedkeuring" KPI card (`CnStatsBlock`) showing the count of Minutes with `lifecycle: review`.

#### Scenario: KPI shows correct count
- **GIVEN** the Dashboard is open
- **WHEN** the KPI widgets load
- **THEN** "Notulen ter goedkeuring" shows the count of Minutes with `lifecycle: review`

### Requirement: REQ-DASH-002 Dashboard KPI: published decisions
The Dashboard SHALL display a "Gepubliceerde besluiten" KPI card (`CnStatsBlock`) showing the count of Decisions with `isPublished: true`.

#### Scenario: KPI shows correct count
- **GIVEN** the Dashboard is open
- **WHEN** the KPI widgets load
- **THEN** "Gepubliceerde besluiten" shows the count of Decisions with `isPublished: true`

### Requirement: REQ-DASH-003 Dashboard KPI: open action items
The Dashboard SHALL display an "Open actiepunten" KPI card (`CnStatsBlock`) showing the count of ActionItems with `taskStatus: open` or `taskStatus: in-progress`.

#### Scenario: KPI shows correct count
- **GIVEN** the Dashboard is open
- **WHEN** the KPI widgets load
- **THEN** "Open actiepunten" shows the count of ActionItems with `taskStatus: open` or `in-progress`

### Requirement: REQ-DASH-004 KPI counts loaded in parallel
The three new Dashboard KPI counts SHALL be fetched in parallel alongside existing counts via `Promise.all` in the Dashboard `created()` hook to avoid sequential latency.

#### Scenario: Parallel fetch
- **WHEN** the Dashboard mounts
- **THEN** all KPI count requests are dispatched simultaneously in a single `Promise.all`

<!-- ============================================================ -->
<!-- Non-functional requirements                                  -->
<!-- ============================================================ -->

### Requirement: REQ-NFR-001 Accessibility (WCAG 2.1 AA)
All new views and dialogs introduced by this change SHALL meet WCAG 2.1 AA: keyboard-navigable interactive elements, labelled form fields, colour not the sole status conveyor, and alt text on status icons.

#### Scenario: Keyboard navigation on Minutes detail
- **WHEN** a user navigates the Minutes detail page using only the keyboard
- **THEN** all lifecycle transition buttons, the "Concept genereren" button, and sidebar tabs are reachable and activatable

### Requirement: REQ-NFR-002 Internationalisation
All user-visible strings in new views SHALL use `t(appName, 'text')`. Dutch (nl) and English (en) translations SHALL be provided in the app translation files.

#### Scenario: No hardcoded UI strings
- **WHEN** a developer scans `src/views/Minutes.vue`, `DecisionDetail.vue`, and `ActionItems.vue`
- **THEN** no bare string literals appear outside `t()` calls

### Requirement: REQ-NFR-003 Audit trail for all state changes
All lifecycle transitions on Minutes, all `isPublished` flag changes on Decisions, and all `taskStatus` changes on ActionItems SHALL produce an audit trail entry via the OpenRegister built-in `AuditTrailService`.

#### Scenario: Lifecycle transition produces audit entry
- **GIVEN** a Minutes object transitions from `draft` to `review`
- **WHEN** the change is saved
- **THEN** an audit entry exists recording: field `lifecycle`, old value `draft`, new value `review`, timestamp, and user

### Requirement: REQ-NFR-004 No hardcoded colours
All status indicators (overdue badge, lifecycle badge) SHALL use Nextcloud CSS variables. No hardcoded hex or RGB colour values SHALL appear in new Vue components.

#### Scenario: Overdue badge uses CSS variable
- **WHEN** an overdue ActionItem is rendered
- **THEN** the badge colour is applied via `--color-error` CSS variable, not a hardcoded colour

### Requirement: REQ-NFR-005 Spec traceability
Every new PHP class and public method introduced by this change SHALL carry a `@spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-N` PHPDoc tag referencing the corresponding task.

#### Scenario: PHPDoc tag present on service class
- **WHEN** `MinutesGenerationService.php` is inspected
- **THEN** the class and `generateDraft()` method both carry a `@spec` PHPDoc tag

---

### Requirement: REQ-AF-001 Register object stores for Minutes, Decision, and ActionItem
The `initializeStores()` function in `src/store/store.js` SHALL register Pinia object stores for Minutes, Decision, and ActionItem (using `createObjectStore(name)` with `files`, `auditTrails`, and `relations` plugins) after fetching settings from `SettingsService`.

**Replaces:** REQ-AF-001 from p1-crud-operations (originally registered Meeting and GovernanceBody stores only)

#### Scenario: Stores initialized on app boot
- **GIVEN** the Decidesk app boots and settings are fetched
- **WHEN** `initializeStores()` runs
- **THEN** Pinia stores for `minutes`, `decision`, and `actionItem` are registered with correct register/schema slugs from settings

### Requirement: REQ-AF-002 Main navigation includes Minutes, Decisions, and Action Items
The `MainMenu.vue` component SHALL include `NcAppNavigationItem` entries for Notulen (Minutes), Besluiten (Decisions), and Actiepunten (Action Items) with MDI icons and `:to` route bindings. Labels SHALL use `t(appName, 'text')`.

**Replaces:** REQ-AF-002 from p1-crud-operations (originally defined navigation for Meetings and Agenda)

#### Scenario: Navigation items visible
- **WHEN** the app sidebar is open
- **THEN** "Notulen", "Besluiten", and "Actiepunten" navigation items are visible with correct icons and routes

### Requirement: REQ-AF-003 Vue Router routes for Minutes, Decisions, and Action Items
Named routes SHALL be registered in `src/router/index.js` for `Minutes` (`/minutes`), `MinutesDetail` (`/minutes/:id`), `Decisions` (`/decisions`), `DecisionDetail` (`/decisions/:id`), `ActionItems` (`/action-items`), and `ActionItemDetail` (`/action-items/:id`).

**Replaces:** REQ-AF-003 from p1-crud-operations (route registry extended, not replaced)

#### Scenario: Route resolves to correct view
- **WHEN** the user navigates to `/decisions/abc123`
- **THEN** the `DecisionDetail` view is rendered with the Decision id `abc123`
