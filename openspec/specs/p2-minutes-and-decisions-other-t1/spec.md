---
status: done
---

# Specs: Minutes and Decisions — Other T1

**Change:** p2-minutes-and-decisions-other-t1
**App:** Decidesk
**Entities:** Decision, Motion, ActionItem, Minutes

---

## Purpose

This spec defines the digital approval workflow, strategic decision review, decision analytics, outcome tracking, auto record creation, action item tracking, and the weekly email digest for Decidesk.

# Requirements

## REQ-DAW: Digital Approval Workflow

The system SHALL satisfy the REQ-DAW (Digital Approval Workflow) requirements specified below.

### REQ-DAW-001 — Submit a Decision for legal review

A secretary or chair can submit a Decision draft for legal review, triggering notifications to legal counsel.

**GIVEN** a Decision detail page is open with `lifecycle: draft` and the current user has role `chair` or `secretary`
**WHEN** the user clicks "Ter juridische toetsing indienen" and confirms in the dialog
**THEN** `DecisionApprovalService::transitionLifecycle(decisionId, 'legal-review', actorId)` is called
**AND** the Decision `lifecycle` is updated to `legal-review` via `ObjectService.saveObject()`
**AND** a `NotificationService` notification is sent to all users with role `legal-counsel` in the linked GovernanceBody: "Besluit ter juridische toetsing: {title}"
**AND** an audit trail entry is created with actor, timestamp, from-state `draft`, to-state `legal-review`
**AND** the `CnTimelineStages` component in the Decision detail header updates to highlight the `legal-review` step

**GIVEN** the current user has role `member`, `observer`, or `guest`
**WHEN** the user views the Decision detail page with `lifecycle: draft`
**THEN** the "Ter juridische toetsing indienen" button is not visible

### REQ-DAW-002 — Complete legal review and submit for committee approval

A legal counsel can approve the legal review of a Decision and advance it to committee review.

**GIVEN** a Decision detail page is open with `lifecycle: legal-review` and the current user has role `legal-counsel`
**WHEN** the user clicks "Juridische toetsing afronden" and confirms
**THEN** `DecisionApprovalService::transitionLifecycle(decisionId, 'committee-review', actorId)` is called
**AND** the Decision `lifecycle` is updated to `committee-review`
**AND** a `NotificationService` notification is sent to all users with role `chair` or `vice-chair` in the linked GovernanceBody: "Besluit gereed voor commissiebehandeling: {title}"
**AND** an audit trail entry is created with actor, timestamp, from-state `legal-review`, to-state `committee-review`

**GIVEN** the current user has role `chair`, `secretary`, or `member`
**WHEN** the user views the Decision detail page with `lifecycle: legal-review`
**THEN** the "Juridische toetsing afronden" button is not visible

### REQ-DAW-003 — Give final board approval to a Decision

A chair or secretary can give final board approval to a Decision after committee review.

**GIVEN** a Decision detail page is open with `lifecycle: committee-review` and the current user has role `chair` or `secretary`
**WHEN** the user clicks "Bestuurlijk goedkeuren" and confirms
**THEN** `DecisionApprovalService::transitionLifecycle(decisionId, 'board-approved', actorId)` is called
**AND** the Decision `lifecycle` is updated to `board-approved`
**AND** a `NotificationService` notification is sent to all users with role `secretary` in the body: "Besluit bestuurlijk goedgekeurd: {title} — klaar voor publicatie"
**AND** an audit trail entry is created with actor, timestamp, from-state `committee-review`, to-state `board-approved`
**AND** the Decision is now eligible for publication (`isPublished: true`)

### REQ-DAW-004 — Reject a Decision during the approval workflow

A reviewer can reject a Decision at any review stage, returning it to draft with a rejection reason.

**GIVEN** a Decision detail page is open with `lifecycle` in `legal-review` or `committee-review`
**AND** the current user has the role required for that review stage
**WHEN** the user clicks "Besluit afwijzen", enters a mandatory rejection reason, and confirms
**THEN** `DecisionApprovalService::transitionLifecycle(decisionId, 'board-rejected', actorId, reason)` is called
**AND** the Decision `lifecycle` is updated to `board-rejected`
**AND** the rejection reason is added as a note on the Decision object
**AND** a `NotificationService` notification is sent to the secretary: "Besluit afgewezen: {title} — reden: {reason}"
**AND** an audit trail entry records actor, timestamp, from-state, to-state, and the rejection reason

**GIVEN** the rejection reason input is empty
**WHEN** the user attempts to confirm the rejection
**THEN** the confirm button remains disabled and an inline validation message "Reden van afwijzing is verplicht" is displayed

### REQ-DAW-005 — View the full approval progress of a Decision

Any user can see where a Decision stands in the approval workflow.

**GIVEN** a Decision detail page is open
**WHEN** the user views the "Goedkeuringsproces" section
**THEN** a `CnTimelineStages` component displays all four stages: Juridische toetsing, Commissiebehandeling, Bestuurlijke goedkeuring, Gepubliceerd
**AND** the current stage is highlighted using Nextcloud CSS variables (no hardcoded colours)
**AND** completed stages show a checkmark; future stages are greyed out
**AND** the name and timestamp of the user who last transitioned the Decision are shown below the current stage indicator

---

## REQ-SRW: Strategic Decision Review

The system SHALL satisfy the REQ-SRW (Strategic Decision Review) requirements specified below.

### REQ-SRW-001 — Assign reviewers to a Decision

A secretary or chair can assign one or more reviewers to a Decision.

**GIVEN** a Decision detail page is open and the current user has role `chair` or `secretary`
**WHEN** the user clicks "Beoordelaar toevoegen" in the "Beoordelaars" panel and selects a Person from the relation picker
**THEN** an OpenRegister relation is created from the Decision to the selected Person with label `reviewer`
**AND** a `NotificationService` notification is sent to the selected Person: "U bent aangewezen als beoordelaar voor besluit: {title}"
**AND** the person appears in the "Beoordelaars" panel with status "In behandeling"

### REQ-SRW-002 — Submit a reviewer sign-off

An assigned reviewer can approve or reject their review with a note.

**GIVEN** a Decision detail page is open
**AND** the current user is a Person linked to the Decision with relation label `reviewer`
**AND** the Decision `lifecycle` is in `legal-review` or `committee-review`
**WHEN** the user selects "Goedkeuren" or "Afwijzen" in the "Beoordelaars" panel and submits with an optional note
**THEN** `DecisionApprovalService::submitReview(decisionId, personId, value, note)` is called
**AND** a structured note is added to the Decision: `[REVIEW] {personName}: {goedgekeurd|afgewezen} — {note} — {timestamp}`
**AND** the reviewer's status in the "Beoordelaars" panel updates to "Goedgekeurd" or "Afgewezen" with the timestamp
**AND** if all assigned reviewers have submitted a sign-off, the secretary receives a notification: "Alle beoordelingen ontvangen voor besluit: {title}"

### REQ-SRW-003 — View reviewer responses on a Decision

A chair or secretary can see all reviewer responses on a Decision at a glance.

**GIVEN** a Decision detail page is open
**WHEN** the user views the "Beoordelaars" panel
**THEN** all assigned reviewers are listed with: display name, role, sign-off status (In behandeling / Goedgekeurd / Afgewezen), sign-off date, and review note if provided
**AND** a summary line shows "N van M beoordelingen ontvangen"
**AND** any reviewer who has not yet signed off has a "Herinnering sturen" button visible to the chair and secretary

---

## REQ-DAA: Decision Analytics Dashboard

The system SHALL satisfy the REQ-DAA (Decision Analytics Dashboard) requirements specified below.

### REQ-DAA-001 — View Decision KPI summary cards

A user can view a summary of key decision metrics at a glance.

**GIVEN** the user navigates to the "Besluitanalyse" analytics page (`/decisions/analytics`)
**WHEN** the page loads
**THEN** `GET /api/decisions/analytics` is called
**AND** four `CnStatsBlock` cards are displayed: "Totaal besluiten" (total Decision count), "Aangenomen" (adopted count + percentage), "In behandeling" (Decisions in `legal-review` or `committee-review` count), "Achterstallige actiepunten" (overdue ActionItems count linked to Decisions)
**AND** all four cards load in parallel via `Promise.all`
**AND** a `CnFilterBar` allows filtering all dashboard data by GovernanceBody

### REQ-DAA-002 — View a trend chart of decisions over time

A user can see how many decisions were made per month over the last year.

**GIVEN** the "Besluitanalyse" analytics page is open
**WHEN** the monthly trend section renders
**THEN** a `CnChartWidget` with type `bar` displays decision counts per month for the last 12 calendar months
**AND** the x-axis shows month abbreviations in Dutch (jan, feb, mrt, ...)
**AND** the y-axis shows integer counts
**AND** hovering a bar shows the exact count and month name in a tooltip

### REQ-DAA-003 — View a decision outcome distribution chart

A user can see the breakdown of adopted vs rejected decisions.

**GIVEN** the "Besluitanalyse" analytics page is open
**WHEN** the outcome distribution section renders
**THEN** a `CnChartWidget` with type `donut` displays the proportion of Decisions with `outcome: adopted` vs `outcome: rejected`
**AND** the chart legend shows labels "Aangenomen" and "Verworpen" with their counts and percentages
**AND** no hardcoded colours are used — chart colours use Nextcloud CSS variables via the ApexCharts theme configuration

### REQ-DAA-004 — View "My Action Items" widget

A user can see their own open and overdue action items on the analytics dashboard.

**GIVEN** the "Besluitanalyse" analytics page is open
**WHEN** the "Mijn actiepunten" section renders
**THEN** all ActionItems with `assignee` matching the current user's display name and `taskStatus` in `open`, `in-progress`, or `overdue` are listed
**AND** overdue items (past `dueDate`) are highlighted using Nextcloud CSS variable `--color-error`
**AND** each item shows: title, linked Decision title (if any), `dueDate`, and current `taskStatus`
**AND** the list is sorted: overdue items first, then by ascending `dueDate`

---

## REQ-DOT: Decision Outcome Tracking

The system SHALL satisfy the REQ-DOT (Decision Outcome Tracking) requirements specified below.

### REQ-DOT-001 — Set an implementation outcome tag on a Decision

A secretary or chair can mark whether a Decision has been implemented.

**GIVEN** a Decision detail page is open with `lifecycle: board-approved` or `lifecycle: published`
**AND** the current user has role `chair` or `secretary`
**WHEN** the user selects an implementation status from the "Implementatiestatus" dropdown in the Decision detail header: "Geïmplementeerd", "In uitvoering", or "Uitgesteld"
**THEN** `DecisionService::setOutcomeTag(decisionId, tag, actorId)` is called
**AND** any existing outcome tag (`geimplementeerd`, `implementatie-lopend`, `implementatie-uitgesteld`) is removed from the `tags` array
**AND** the new outcome tag is added to the `tags` array via `ObjectService.saveObject()`
**AND** an `ActivityService` log entry is created: "{actor} heeft implementatiestatus van besluit '{title}' gewijzigd naar {status}"
**AND** the selected status is displayed as a `CnStatusBadge` in the Decision detail header and list row

**GIVEN** the current user has role `member`, `observer`, or `guest`
**WHEN** the user views the Decision detail page
**THEN** the "Implementatiestatus" dropdown is not visible; the current outcome tag (if any) is displayed as a read-only `CnStatusBadge`

### REQ-DOT-002 — Filter decisions by implementation status in the index

A user can filter the Decision index to show only decisions at a specific implementation stage.

**GIVEN** the Decisions index page is open
**WHEN** the user selects an option from the "Implementatiestatus" facet in `CnFacetSidebar`
**THEN** only Decisions with the matching outcome tag are displayed via `IndexService` tag-based filtering
**AND** facet counts show the number of Decisions in each implementation status
**AND** multiple facet values can be selected simultaneously (OR logic)
**AND** selecting "Geen status" shows Decisions with none of the three outcome tags set

---

## REQ-ARC: Auto Record Creation from Adopted Motions

The system SHALL satisfy the REQ-ARC (Auto Record Creation from Adopted Motions) requirements specified below.

### REQ-ARC-001 — Auto-create a Decision record when a Motion is adopted

When a Motion is adopted, a Decision record is created automatically.

**GIVEN** a Motion detail page is open with `lifecycle: voting` and the current user has role `chair` or `secretary`
**WHEN** the user transitions the Motion lifecycle to `adopted` via `MotionService::transitionLifecycle()`
**THEN** `DecisionAutoRecordService::createFromAdoptedMotion(motionId)` is called
**AND** if no Decision linked to this Motion exists, a new Decision is created with:
  - `title` set to Motion.`title`
  - `text` set to Motion.`decisionText` (or Motion.`text` if `decisionText` is empty)
  - `decisionDate` set to the current date (ISO 8601)
  - `outcome` set to `adopted`
  - `legalBasis` set to Motion.`legalBasis`
  - `lifecycle` set to `draft`
  - An OpenRegister relation from Decision → Motion with label `source-motion`
**AND** a `NotificationService` notification is sent to the secretary: "Besluitrecord automatisch aangemaakt voor motie: {motionTitle} — controleer en start goedkeuringsproces"
**AND** the created Decision UUID is logged via `ActivityService`

### REQ-ARC-002 — Prevent duplicate Decision records for the same Motion

Auto-record creation is idempotent and does not create duplicate Decision records.

**GIVEN** `DecisionAutoRecordService::createFromAdoptedMotion(motionId)` is called
**AND** a Decision already exists with a relation to the same Motion (label `source-motion`)
**WHEN** the duplicate check runs via `ObjectService.findAll()` with the relation filter
**THEN** no new Decision is created
**AND** the existing Decision UUID is returned
**AND** no duplicate notification is sent
**AND** the idempotency check is logged at `INFO` level: "Decision already exists for motion {motionId} — skipping auto-creation"

### REQ-ARC-003 — View the source Motion from a Decision detail page

A user can navigate from a Decision to its originating Motion.

**GIVEN** a Decision detail page is open where the Decision was auto-created from a Motion
**WHEN** the user views the "Bronmotie" section
**THEN** the linked Motion is displayed with: title, proposer, lifecycle, and a link to the Motion detail page
**AND** the section is only visible when a relation with label `source-motion` exists on the Decision

---

## REQ-AIT: Enhanced Action Item Tracking

The system SHALL satisfy the REQ-AIT (Enhanced Action Item Tracking) requirements specified below.

### REQ-AIT-001 — Receive an escalation notification when an action item becomes overdue

Users receive an escalation notification when an action item linked to a Decision becomes overdue.

**GIVEN** an ActionItem linked to a Decision has `dueDate < today` and `taskStatus` is `open` or `in-progress`
**WHEN** the existing `OverdueActionItemsJob` background job runs daily
**THEN** the ActionItem `taskStatus` is set to `overdue`
**AND** a `NotificationService` notification is sent to the `assignee`: "Actiepunt achterstallig: {title} — termijn was {dueDate}"
**AND** if the ActionItem is linked to a Decision, an additional notification is sent to the secretary of the linked GovernanceBody: "Achterstallig actiepunt op besluit '{decisionTitle}': {actionItemTitle}"
**AND** the escalation notification to the secretary is only sent once per overdue ActionItem (tracked via the `tags` array: tag `escalation-sent` added after first send)

### REQ-AIT-002 — View all action items from a meeting grouped by status

A user can see all action items from a specific meeting in one panel.

**GIVEN** a Minutes detail page is open
**WHEN** the user views the "Actiepunten" section
**THEN** all ActionItems linked to Decisions that are linked to the same Meeting as the Minutes are listed
**AND** items are grouped by `taskStatus`: "Achterstallig" (overdue), "Open", "In uitvoering", "Afgerond"
**AND** each item shows: title, assignee, dueDate, taskStatus badge, and a link to the parent Decision
**AND** the total count per group is displayed in the group header

---

## REQ-WED: Weekly Email Digest

The system SHALL satisfy the REQ-WED (Weekly Email Digest) requirements specified below.

### REQ-WED-001 — Receive a weekly digest of upcoming decisions and deadlines

Chairs and secretaries receive a weekly email listing decisions requiring attention.

**GIVEN** `DecisionDigestJob` runs on Monday at 09:00 for a GovernanceBody that has digest enabled
**WHEN** the job assembles and sends the digest email
**THEN** the email recipient list includes all Person records with role `chair` or `secretary` in the GovernanceBody's Membership records (non-empty email addresses only)
**AND** the email subject is: "Decidesk weekoverzicht — {governanceBodyName} — {date}"
**AND** the email body includes three sections:
  - "Aankomende actiepunten" — ActionItems linked to Decisions of this body with `dueDate` within 14 days and `taskStatus != completed`, sorted ascending by `dueDate`
  - "Achterstallige actiepunten" — ActionItems with `taskStatus: overdue`, sorted ascending by `dueDate`
  - "Besluiten in behandeling" — Decisions in `legal-review` or `committee-review`, sorted ascending by `createdAt`
**AND** each section is omitted from the email if it is empty (no items)
**AND** if all three sections are empty, no email is sent for that governance body

### REQ-WED-002 — Opt out of the weekly digest per governance body

An administrator can disable the weekly digest for a specific governance body.

**GIVEN** the admin settings page (`/settings/admin/decidesk`) is open
**WHEN** the administrator finds the "Wekelijks overzicht" section and disables the digest for a specific GovernanceBody using the toggle
**THEN** `IAppConfig` key `digest_enabled_{governanceBodyId}` is set to `false`
**AND** `DecisionDigestJob` skips that governance body on its next run
**AND** the toggle state is persisted and visible on subsequent visits to the settings page

### REQ-WED-003 — Weekly digest includes deep links to each item

The weekly email digest includes deep links into the Decidesk app.

**GIVEN** `DecisionDigestJob` is assembling the HTML email body
**WHEN** an ActionItem or Decision is included in a digest section
**THEN** the HTML version of the email includes a hyperlink to the relevant detail page using `generateUrl('/apps/decidesk/')` with the entity route appended
**AND** the plain-text version includes the full URL on a separate line after the item title
**AND** a footer note states: "Inloggen in Nextcloud is vereist om de links te openen"

---

## Non-Functional Requirements

The implementation MUST satisfy the non-functional requirements (REQ-NFR) specified below.

### REQ-NFR-001 — Accessibility (ADR-010)
All new views, panels, dialogs, and widgets MUST meet WCAG 2.1 AA: keyboard-navigable, form fields labelled with `aria-label` or `<label>`, colour is not the sole status conveyor (badges and timeline stages include text labels), alt text on all status icons.

### REQ-NFR-002 — Internationalisation (ADR-007)
All user-visible strings in new views and components MUST use `t(appName, 'text')`. Dutch (nl) and English (en) translations MUST be provided for all new strings in `l10n/nl.json` and `l10n/en.json`.

### REQ-NFR-003 — Spec traceability (ADR-003)
Every new PHP class and public method introduced by this change MUST carry a `@spec openspec/changes/p2-minutes-and-decisions-other-t1/tasks.md#task-N` PHPDoc tag.

### REQ-NFR-004 — No hardcoded colours (ADR-004 / ADR-010)
All status indicators, timeline stages, chart colours, and overdue highlights MUST use Nextcloud CSS variables (`--color-primary-element`, `--color-error`, `--color-warning`, `--color-success`). No hardcoded hex values or `--nldesign-*` tokens.

### REQ-NFR-005 — No custom CRUD, audit, or chart code (ADR-001 / ADR-012)
All Decision/ActionItem listing, filtering, relation creation, audit trail display, and chart rendering MUST use `ObjectService`, `CnIndexPage`, `CnDetailPage`, `CnObjectSidebar`, and `CnChartWidget` from the OpenRegister/conduction platform. No custom CRUD controllers, audit log handlers, or chart libraries.

### REQ-NFR-006 — Analytics endpoint caching
`GET /api/decisions/analytics` MUST return a `Cache-Control: max-age=900` header. The controller MUST check `ICache` before running aggregate queries. Cache key: `decidesk_analytics_{governanceBodyId}`. Cache TTL: 900 seconds (15 minutes).

### REQ-NFR-007 — Digest job error handling
`DecisionDigestJob` MUST wrap all `IMailer::send()` calls in try/catch. Failures MUST be logged at `ERROR` level with governance body ID and exception context. A failed send MUST NOT stop the job from processing remaining governance bodies.
