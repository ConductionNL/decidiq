---
status: done
---

# Specs: Minutes and Decisions — Core T1

**Change:** p2-minutes-and-decisions-core-t1
**App:** Decidesk
**Entities:** Decision, Minutes, ActionItem, DigitalDocument

---

## Purpose

This spec defines case decision documents, permit and publication decisions, statutory deadlines, urgent decisions, decision lists, audit trail completeness, and the extended decision archive for Decidesk.

# Requirements

## REQ-CDM: Case Decision Document Marking

The system SHALL satisfy the REQ-CDM (Case Decision Document Marking) requirements specified below.

### REQ-CDM-001 — Mark a DigitalDocument as a case decision document
A user can mark a DigitalDocument as a case decision document and link it to a Decision.

**GIVEN** a DigitalDocument detail page or edit form is open
**WHEN** the user sets `documentType` to `case-decision` and selects a Decision from the relation picker
**THEN** the DigitalDocument is saved with `documentType: case-decision`
**AND** an OpenRegister relation is created from the DigitalDocument to the selected Decision
**AND** the Decision detail page "Besluitdocumenten" panel shows the document with a `case-decision` badge

### REQ-CDM-002 — View case decision documents on Decision detail page
A user can see all documents linked to a Decision, grouped by document type.

**GIVEN** a Decision detail page is open
**WHEN** the user scrolls to the "Besluitdocumenten" panel
**THEN** all DigitalDocuments linked to this Decision are listed with: name, documentType badge, encodingFormat, and a download link
**AND** the list is grouped by `documentType` (case-decision, permit-decision, woo-disclosure, contract)
**AND** each document row has a "Loskoppelen" action to remove the relation without deleting the document

### REQ-CDM-003 — Filter decisions by presence of case decision documents
A user can filter the Decision index to show only decisions with or without attached case decision documents.

**GIVEN** the Decisions index page is open
**WHEN** the user applies the filter "Heeft besluitdocument" via the `CnFilterBar`
**THEN** only decisions with at least one linked DigitalDocument are displayed

---

## REQ-PPD: Permit and Publication Decisions

The system SHALL satisfy the REQ-PPD (Permit and Publication Decisions) requirements specified below.

### REQ-PPD-001 — Generate a permit decision PDF
A clerk can generate a permit decision PDF document from a Decision object.

**GIVEN** a Decision detail page is open with `legalBasis` referencing a permit regulation
**WHEN** the user clicks "Vergunningsbesluit genereren"
**THEN** `DecisionDocumentService::generatePermitDecision()` is called
**AND** a PDF is rendered using the Dutch permit decision template, populated with: decision title, text, decisionDate, legalBasis, and the calculated statutory deadline
**AND** the PDF is stored via `FileService` as a file attached to the Decision object
**AND** a DigitalDocument record is created with `documentType: permit-decision` and linked to the Decision
**AND** a success notification is shown with the generated document name

**GIVEN** the permit decision PDF has been generated
**WHEN** the user clicks "Vergunningsbesluit publiceren"
**THEN** `isPublished` is set to `true`
**AND** `publishedAt` is set to the current date-time
**AND** the change is recorded in the audit trail

### REQ-PPD-002 — Generate a Woo disclosure decision document
A clerk can generate a Woo disclosure document for a public records request decision.

**GIVEN** a Decision detail page is open
**WHEN** the user clicks "Woo-openbaarmakingsbesluit genereren"
**THEN** `DecisionDocumentService::generateWooDisclosure()` is called
**AND** a PDF is rendered using the Dutch Woo disclosure template, populated with: decision title, text, decisionDate, legalBasis, and the requester information from the Decision notes
**AND** the PDF is stored via `FileService` as a file attached to the Decision object
**AND** a DigitalDocument record is created with `documentType: woo-disclosure` and linked to the Decision
**AND** a success notification is shown

**GIVEN** the Woo disclosure PDF has been generated
**WHEN** the user views the Decision detail page
**THEN** the "Besluitdocumenten" panel displays the Woo disclosure document with a `woo-disclosure` badge

### REQ-PPD-003 — Generate a contract document from an award decision
A clerk can generate a contract document when a Decision records an award in a procurement process.

**GIVEN** a Decision detail page is open and the Decision text references a procurement award
**WHEN** the user clicks "Contract genereren"
**THEN** `DecisionDocumentService::generateContract()` is called
**AND** a PDF is rendered using the Dutch contract template, populated with: decision title, text, decisionDate, parties, and legalBasis
**AND** the PDF is stored via `FileService` as a file attached to the Decision object
**AND** a DigitalDocument record is created with `documentType: contract` and linked to the Decision

**GIVEN** a contract document has been generated
**WHEN** a user opens the Decision detail page
**THEN** the contract document is listed in the "Besluitdocumenten" panel with a `contract` badge and download link

---

## REQ-SDL: Statutory Deadline Tracking

The system SHALL satisfy the REQ-SDL (Statutory Deadline Tracking) requirements specified below.

### REQ-SDL-001 — Calculate and display the statutory deadline for a decision
A clerk can see the statutory response deadline automatically calculated from the decision's legal basis.

**GIVEN** a Decision detail page is open with a non-empty `legalBasis` field
**WHEN** the page renders the statutory deadline section
**THEN** `StatutoryDeadlineService::calculate(legalBasis)` is called
**AND** if the legal article is in the configured mapping, the calculated deadline date is displayed below the `legalBasis` field with label "Wettelijke beslistermijn"
**AND** a countdown shows the number of days remaining (or days overdue if past)
**AND** if the legal article is not in the mapping, a notice "Termijn niet automatisch bepaald — stel handmatig in" is shown

### REQ-SDL-002 — Create a statutory deadline ActionItem when generating an acknowledgement
When generating a decision acknowledgement, a statutory deadline ActionItem is created automatically.

**GIVEN** a Decision has a `legalBasis` whose statutory deadline is calculable
**WHEN** the user clicks "Ontvangstbevestiging genereren"
**THEN** `DecisionDocumentService::generateAcknowledgement()` inserts the calculated deadline date into the acknowledgement text template
**AND** a new ActionItem is created with:
  - `title: "Wettelijke beslistermijn"` + the legal article reference
  - `dueDate` set to the calculated deadline date
  - `taskStatus: open`
  - `assignee` set to the current user's display name
**AND** the ActionItem is linked to the Decision via OpenRegister relation
**AND** the acknowledgement PDF is stored as a DigitalDocument attached to the Decision

### REQ-SDL-003 — Display statutory deadline ActionItems on Decision detail page
A user can see all statutory deadline action items associated with a decision.

**GIVEN** a Decision detail page is open
**WHEN** the user scrolls to the "Actiepunten" section
**THEN** all ActionItems linked to the Decision are listed, with statutory deadline items (`title` starting with "Wettelijke beslistermijn") visually distinguished by a `CnStatusBadge` in a distinct colour using Nextcloud CSS variables
**AND** overdue statutory deadline items display the `overdue` status badge

### REQ-SDL-004 — Overdue statutory deadline items are flagged automatically
Statutory deadline ActionItems are automatically set to `overdue` when past their due date.

**GIVEN** an ActionItem with `title` containing "Wettelijke beslistermijn" and `dueDate < today` and `taskStatus: open` or `in-progress` exists
**WHEN** the existing `OverdueActionItemsJob` background job runs daily
**THEN** the `taskStatus` is set to `overdue`
**AND** a Nextcloud notification is sent to the `assignee` with message "Wettelijke beslistermijn verstreken"

---

## REQ-URG: Urgent Decision Fast-Track

The system SHALL satisfy the REQ-URG (Urgent Decision Fast-Track) requirements specified below.

### REQ-URG-001 — Flag a decision as urgent (Spoedbesluit)
A chair or secretary can flag a decision as urgent, triggering priority notifications.

**GIVEN** a Decision detail page is open and the current user has role `chair` or `secretary`
**WHEN** the user clicks "Als spoedbesluit markeren" and confirms in the dialog
**THEN** the tag `spoed` is added to the Decision's `tags` array via `DecisionService::flagUrgent()`
**AND** priority Nextcloud notifications are sent to all users with role `chair`, `secretary`, and any user tagged as legal counsel for the linked GovernanceBody
**AND** the Decision list and detail pages display a "Spoed" `CnStatusBadge` in the warning colour (using Nextcloud CSS variables — no hardcoded colours)
**AND** the audit trail records: who flagged it, at what time, with the reason note if provided

**GIVEN** the current user has role `member`, `observer`, or `guest`
**WHEN** the user views the Decision detail page
**THEN** the "Als spoedbesluit markeren" button is not visible

### REQ-URG-002 — Remove the urgent flag from a decision
A chair or secretary can remove the urgent flag from a decision.

**GIVEN** a Decision detail page is open with the `spoed` tag and the current user has role `chair` or `secretary`
**WHEN** the user clicks "Spoedbesluit verwijderen" and provides a reason in the dialog
**THEN** the `spoed` tag is removed from the Decision's `tags` array
**AND** the reason is added as a note on the Decision object
**AND** the audit trail records: who removed the flag, at what time, and the reason
**AND** the urgent badge is no longer shown on the Decision list and detail pages

### REQ-URG-003 — Urgent decisions are visually distinguished in the Decision list
Urgent decisions are clearly highlighted in the Decision index page.

**GIVEN** the Decisions index page is open
**WHEN** one or more Decisions have the `spoed` tag
**THEN** those Decisions are displayed with a "Spoed" `CnStatusBadge` in the `taskStatus`/outcome column
**AND** the `CnFilterBar` includes a "Spoedbesluit" toggle filter to show only urgent decisions
**AND** urgent decisions are sorted above non-urgent decisions within the same lifecycle state by default

---

## REQ-ADL: Auto-Generate Decision List

The system SHALL satisfy the REQ-ADL (Auto-Generate Decision List) requirements specified below.

### REQ-ADL-001 — Generate a decision list from voting results
A secretary can auto-generate a formatted decision list from all voting results linked to a meeting.

**GIVEN** a Minutes detail page is open with `lifecycle: draft` or `review`
**AND** the linked Meeting has at least one closed VotingRound with a Decision linked
**WHEN** the user clicks "Besluitenlijst genereren"
**THEN** `MinutesGenerationService::generateDecisionList()` is called
**AND** all VotingRounds linked to the Meeting are retrieved, ordered by `closedAt`
**AND** for each VotingRound, the linked Decision is retrieved (if it exists)
**AND** a formatted Dutch decision list is generated with: decision number, title, outcome (Aangenomen / Verworpen), vote totals (Voor: X, Tegen: Y, Onthouding: Z), and legal basis
**AND** a preview of the generated decision list is displayed in a dialog before being applied
**AND** on user confirmation, the generated text is appended to or replaces the Minutes `content` field

**GIVEN** no VotingRounds with linked Decisions exist for the Meeting
**WHEN** the user clicks "Besluitenlijst genereren"
**THEN** a notice "Geen stemrondes met besluiten gevonden voor deze vergadering" is shown
**AND** no changes are made to the Minutes content

### REQ-ADL-002 — Decision list preview shows warnings for incomplete data
The decision list preview alerts the secretary to data quality issues.

**GIVEN** `MinutesGenerationService::generateDecisionList()` detects VotingRounds without linked Decisions
**WHEN** the preview dialog renders
**THEN** a warning banner is shown: "X stemronde(s) hebben nog geen gekoppeld besluit — controleer de besluitenlijst na genereren"
**AND** the preview still renders the available decisions
**AND** the user can proceed with or cancel the generation

---

## REQ-ATR: Audit Trail Completeness

The system SHALL satisfy the REQ-ATR (Audit Trail Completeness) requirements specified below.

### REQ-ATR-001 — Audit trail entries for all Decision lifecycle events
Every change to a Decision object produces an audit trail entry.

**GIVEN** any of the following actions is performed on a Decision object: create, update, delete, publish (`isPublished` set to true), urgent flag added/removed, document generated and linked, statutory deadline ActionItem created
**WHEN** `ObjectService.saveObject()` or `DecisionService` methods complete the action
**THEN** an audit trail entry is automatically created by `AuditTrailService` containing: actor (display name), timestamp, action type, before snapshot, after snapshot
**AND** the entry is visible in `CnObjectSidebar` → `CnAuditTrailTab` on the Decision detail page

### REQ-ATR-002 — Audit trail entries for Minutes lifecycle transitions
Every Minutes lifecycle transition produces an audit trail entry.

**GIVEN** a Minutes object transitions between `draft`, `review`, `approved`, `signed`, and `published`
**WHEN** the transition is saved via `ObjectService.saveObject()`
**THEN** an audit trail entry is created with: actor, timestamp, from lifecycle state, to lifecycle state
**AND** for the `approved` transition, the entry includes the signer display name appended to `signedBy`

### REQ-ATR-003 — Audit trail entries for ActionItem status changes
Every ActionItem status change produces an audit trail entry.

**GIVEN** an ActionItem `taskStatus` changes (open → in-progress, in-progress → completed, any → overdue)
**WHEN** the change is saved via `ObjectService.saveObject()`
**THEN** an audit trail entry is created with: actor, timestamp, previous `taskStatus`, new `taskStatus`
**AND** for `completed` transitions, the `completedAt` value is included in the entry

### REQ-ATR-004 — Export audit trail for a Decision
A user can export the complete audit trail for a Decision.

**GIVEN** a Decision detail page is open with the `CnAuditTrailTab` visible in the sidebar
**WHEN** the user clicks "Exporteren" in the audit trail tab
**THEN** the full audit trail for the Decision is exported via `CnMassExportDialog` in CSV or JSON format
**AND** the export includes: timestamp, actor, action, before value, after value for each entry

---

## REQ-ARC: Decision Archive Extended

The system SHALL satisfy the REQ-ARC (Decision Archive Extended) requirements specified below.

### REQ-ARC-001 — Search the decision archive by topic, date range, and legal basis
A user can search and filter the complete decision archive using multiple criteria.

**GIVEN** the Decisions index page is open
**WHEN** the user enters a search term in the `CnFilterBar` search input
**THEN** decisions matching the term in `title`, `text`, or `legalBasis` are returned via `IndexService` full-text search

**GIVEN** the Decisions index page is open
**WHEN** the user applies a date range filter on `decisionDate`
**THEN** only decisions within the specified date range are displayed

**GIVEN** the Decisions index page is open
**WHEN** the user applies a facet filter from `CnFacetSidebar` on `documentType` (from linked DigitalDocuments)
**THEN** only decisions with at least one linked DigitalDocument of the selected type are displayed

### REQ-ARC-002 — Access board knowledge base and decision history
A board member can browse the decision history of their governance body.

**GIVEN** the Decisions index page is open
**WHEN** the user applies a filter on the linked GovernanceBody
**THEN** only decisions from the selected governance body are displayed
**AND** the results include both adopted and rejected decisions with their full text and legalBasis
**AND** the user can click any decision to view its full detail, linked documents, action items, and audit trail

### REQ-ARC-003 — View decision history ordered by date
A user can view all decisions in chronological order.

**GIVEN** the Decisions index page is open
**WHEN** the user sorts by `decisionDate` descending (default)
**THEN** the most recent decisions are shown first
**AND** sorting by `decisionDate` ascending shows the oldest decisions first
**AND** the sorted order is preserved when filters are applied

---

## Non-Functional Requirements

The implementation MUST satisfy the non-functional requirements (REQ-NFR) specified below.

### REQ-NFR-001 — Accessibility (ADR-010)
All new views, panels, and dialogs introduced by this change MUST meet WCAG 2.1 AA: keyboard-navigable, form fields labelled with `aria-label` or `<label>`, colour not the sole status conveyor (badges must include text labels), alt text on status icons.

### REQ-NFR-002 — Internationalisation (ADR-007)
All user-visible strings in new views and components MUST use `t(appName, 'text')`. Dutch (nl) and English (en) translations MUST be provided for all new strings.

### REQ-NFR-003 — Audit trail completeness (ADR-001)
Every create, update, delete, publish, urgent-flag, and document-generation action on Decision, Minutes, and ActionItem objects MUST produce an audit trail entry via the OpenRegister built-in `AuditTrailService`.

### REQ-NFR-004 — No hardcoded colours (ADR-004 / ADR-010)
All status indicators (urgent badge, deadline countdown, overdue badge) MUST use Nextcloud CSS variables. No hardcoded hex values or `--nldesign-*` tokens.

### REQ-NFR-005 — Spec traceability (ADR-003)
Every new PHP class and public method introduced by this change MUST carry a `@spec openspec/changes/p2-minutes-and-decisions-core-t1/tasks.md#task-N` PHPDoc tag.

### REQ-NFR-006 — No custom CRUD or audit code (ADR-001)
Document listing, filtering, relation creation, and audit trail display MUST use `ObjectService`, `CnIndexPage`, `CnDetailPage`, and `CnObjectSidebar` from the OpenRegister platform. No custom CRUD controllers or audit log handlers.
