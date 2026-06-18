---
status: done
---

# p2-minutes-and-decisions-core-t3 Specification

## Purpose
Provides real-time minutes capture during active meetings with debounced auto-save, concurrent-edit locking, and version tracking, plus a role-guarded minutes approval workflow requiring chair and secretary digital acknowledgements. The capability also automates action-item extraction from approved minutes into CalDAV VTODOs, exposes published minutes and decisions through public ORI Report and Motion endpoints, and supports full-text search, faceted filtering, multi-format export, and notification events for decision discovery.

## Requirements

### Requirement: REQ-MRT-001 Real-time minutes persistence during active meeting
The system SHALL persist Minutes content changes in real-time while a meeting is in the `opened` lifecycle state, using debounced auto-save so that no more than 500ms elapses between the last keystroke and a save attempt. The Minutes object SHALL use `schema:CreativeWork` (ORI: `meeting:Report`) type annotations. Auto-save SHALL invoke `ObjectService.saveObject()` on the backend and update the `version` field on each successful save. If the Minutes object is locked by another user, the system SHALL display a user-facing message and NOT overwrite the locked object.

#### Scenario: Auto-save during active meeting
- **GIVEN** a meeting with `lifecycle: opened` and an associated Minutes object with `lifecycle: draft`
- **WHEN** a clerk edits the Minutes `content` field
- **THEN** the system SHALL send a save request within 500ms of the last keystroke, increment the `version` field on success, and display a "Saved" indicator without navigating away

#### Scenario: Concurrent edit lock detection
- **GIVEN** a Minutes object is locked by user A via `ObjectService.lockObject()`
- **WHEN** user B attempts to save changes to the same Minutes object
- **THEN** the system SHALL display a user-facing notification "These minutes are being edited by [displayName]" and SHALL NOT overwrite the locked object

#### Scenario: Auto-save not available outside active meeting
- **GIVEN** a meeting with `lifecycle: closed` or `lifecycle: draft`
- **WHEN** a clerk attempts to edit the associated Minutes object
- **THEN** the system SHALL require explicit save via the Edit form (no auto-save), and SHALL display the meeting status to explain why auto-save is disabled

---

### Requirement: REQ-MRT-002 Real-time decision recording linked to agenda items
The system SHALL allow a clerk to record a decision (`decisionText`, `decisionDate`) against a Motion during an active meeting, with the Motion linked to its AgendaItem. The decision record SHALL be immediately persisted via `ObjectService.saveObject()` and SHALL set `lifecycle: adopted` on the Motion when a decision is recorded. The VoteAction (Schema.org) SHALL be used as the type annotation for the recording action.

#### Scenario: Record decision against agenda item during meeting
- **GIVEN** a meeting in `opened` state with an AgendaItem linked to a Motion in `voting` lifecycle
- **WHEN** the clerk enters `decisionText` and `decisionDate` and clicks "Besluit vastleggen"
- **THEN** the system SHALL save the Motion with `lifecycle: adopted`, populated `decisionText` and `decisionDate` fields, and display the decision as confirmed in the Minutes editor

#### Scenario: Decision linked to agenda item visible in minutes
- **GIVEN** a Motion with `lifecycle: adopted` linked to an AgendaItem
- **WHEN** the Minutes editor is displayed for the meeting
- **THEN** each adopted Motion SHALL appear as a formatted decision block in the Minutes content panel, showing the `decisionText` and `decisionDate`

---

### Requirement: REQ-MRT-003 Quick-link decisions to agenda items and motions
The system SHALL provide a quick-link feature in the Minutes editor allowing a clerk to associate a Decision (adopted Motion) with a specific AgendaItem by selecting from a dropdown of the current meeting's agenda items. This SHALL create an OpenRegister relation between the Motion and the AgendaItem.

#### Scenario: Link decision to agenda item via quick-link
- **GIVEN** a Minutes editor open for an active meeting
- **WHEN** the clerk selects an adopted Motion and uses the "Koppel aan agendapunt" control to select an AgendaItem
- **THEN** the system SHALL create a relation between the Motion and the AgendaItem, and display the link in both the Minutes editor and the AgendaItem detail view

---

<!-- ===================================================================== -->
<!-- Capability: minutes-approval-workflow                                   -->
<!-- ===================================================================== -->

### Requirement: REQ-MAW-001 Minutes lifecycle state machine
The system SHALL implement a lifecycle state machine for Minutes with states: `draft`, `submitted`, `approved`, `published`. Transitions SHALL be enforced by the OpenRegister `WorkflowEngineController`. The state machine SHALL be configurable per governance domain via the GovernanceBody's `workflowTemplate` field. Invalid transitions (e.g. `draft` → `published` directly) SHALL be rejected with HTTP 422 and a user-facing error message.

#### Scenario: Valid transition from draft to submitted
- **GIVEN** a Minutes object with `lifecycle: draft`
- **WHEN** an authorized clerk triggers the "Ter beoordeling indienen" action
- **THEN** the system SHALL transition the Minutes to `lifecycle: submitted`, record the transition in the audit trail, and notify configured reviewers

#### Scenario: Invalid transition rejected
- **GIVEN** a Minutes object with `lifecycle: draft`
- **WHEN** a user attempts to set `lifecycle: published` directly via the API
- **THEN** the system SHALL return HTTP 422 with message "Invalid lifecycle transition" and leave the Minutes in `draft` state

#### Scenario: Published minutes are read-only
- **GIVEN** a Minutes object with `lifecycle: published`
- **WHEN** any user attempts to edit the `content` field
- **THEN** the system SHALL reject the edit with HTTP 403 and display "Gepubliceerde notulen kunnen niet worden gewijzigd"

---

### Requirement: REQ-MAW-002 Digital signatures required for minutes approval
The system SHALL require digital acknowledgements from the authorized chair and secretary before a Minutes object can transition from `submitted` to `approved`. Each acknowledgement SHALL: (1) add the signing user's UID to the `signedBy` array on the Minutes object, and (2) record the signature action in the `AuditTrailService` with timestamp and actor UID. The transition to `approved` SHALL be blocked until both chair and secretary UIDs are present in `signedBy`.

#### Scenario: Chair acknowledgement recorded
- **GIVEN** a Minutes object with `lifecycle: submitted`
- **WHEN** the chair user selects "Akkoord verklaren" in the MinutesApprovalForm
- **THEN** the system SHALL add the chair's UID to `signedBy`, record the action in the audit trail with timestamp, and indicate to the secretary that chair approval is complete

#### Scenario: Approval transition blocked until both signatures present
- **GIVEN** a Minutes object with `lifecycle: submitted` and only the chair's UID in `signedBy`
- **WHEN** any user attempts to transition to `lifecycle: approved`
- **THEN** the system SHALL return HTTP 422 with message "Secretary acknowledgement required before approval"

#### Scenario: Both signatures present enables approval
- **GIVEN** a Minutes object with `lifecycle: submitted` and both chair and secretary UIDs in `signedBy`
- **WHEN** an authorized user triggers the "Goedkeuren" action
- **THEN** the system SHALL transition the Minutes to `lifecycle: approved` and record the approval in the audit trail

---

### Requirement: REQ-MAW-003 Role-based transition guards for minutes workflow
The system SHALL enforce role-based guards on all lifecycle transitions. Only users with the appropriate Membership role in the GovernanceBody SHALL be permitted to trigger each transition. The backend SHALL verify roles via `IGroupManager::isAdmin()` for admin-level operations and via OpenRegister's `AuthorizationService` for governance body role checks. Frontend-only role checks are NOT sufficient.

#### Scenario: Non-authorized user blocked from approval transition
- **GIVEN** a Minutes object with `lifecycle: submitted`
- **WHEN** a user without `chair` or `secretary` role in the relevant GovernanceBody attempts to trigger the "Goedkeuren" action
- **THEN** the system SHALL return HTTP 403 and display "Onvoldoende rechten voor deze actie"

#### Scenario: Admin can override stuck workflow
- **GIVEN** a Minutes object with `lifecycle: submitted` where the signing user is no longer active
- **WHEN** a Nextcloud admin triggers a workflow override via the admin settings panel
- **THEN** the system SHALL allow the transition and record "admin override" in the audit trail

---

### Requirement: REQ-MAW-004 Version tracking and revision history
The system SHALL increment the `version` integer on the Minutes object on every successful save. The full revision history SHALL be accessible via the `CnObjectSidebar` Audit Trail tab. When a Minutes object is in `approved` or `published` state, creating a new revision SHALL require transitioning back to `draft` first, which increments `version` and records the reversion in the audit trail.

#### Scenario: Version increments on save
- **GIVEN** a Minutes object with `version: 2`
- **WHEN** a clerk saves a change to the `content` field
- **THEN** the system SHALL set `version: 3` and record the change in the audit trail with before/after snapshot

#### Scenario: Revision history visible in sidebar
- **GIVEN** a Minutes detail page
- **WHEN** the clerk opens the Audit Trail tab in the `CnObjectSidebar`
- **THEN** the system SHALL display all prior versions with actor UID, timestamp, and changed fields

---

<!-- ===================================================================== -->
<!-- Capability: action-item-automation                                      -->
<!-- ===================================================================== -->

### Requirement: REQ-AIA-001 Automatic ActionItem extraction from approved minutes
The system SHALL automatically run the `ActionItemExtractor` service when a Minutes object transitions to `lifecycle: approved`. The extractor SHALL parse the `content` field using the GovernanceBody's configured regex/keyword patterns and create CalDAV VTODOs via `CalDavService` in the dedicated "DecideDesk — Actiepunten" calendar. Each VTODO SHALL include `X-DECIDESK-MEETING-UID` and, where a source Motion can be identified, `X-DECIDESK-MOTION-UID` extended properties. The `schema:Action` type annotation (Schema.org) SHALL be used for ActionItem extraction events.

#### Scenario: ActionItems extracted on minutes approval
- **GIVEN** a Minutes object transitioning to `lifecycle: approved` with content containing "ACTIEPUNT: griffier publiceert besluit vóór 22 januari"
- **WHEN** the approval workflow transition completes
- **THEN** the system SHALL create a CalDAV VTODO with `SUMMARY: "griffier publiceert besluit vóór 22 januari"`, `STATUS: NEEDS-ACTION`, and `X-DECIDESK-MEETING-UID` set to the meeting's CalDAV UID

#### Scenario: Extraction preview before VTODO creation
- **GIVEN** a Minutes object with `lifecycle: submitted` containing extractable action items
- **WHEN** the clerk selects "Actiepunten voorvertonen" before approving
- **THEN** the system SHALL display the list of to-be-created ActionItems WITHOUT creating any VTODOs, allowing the clerk to edit or remove items before committing

#### Scenario: No action items extracted when no patterns match
- **GIVEN** a Minutes object transitioning to `lifecycle: approved` with content containing no configured extraction patterns
- **WHEN** the approval transition completes
- **THEN** the system SHALL complete the transition without error and display "Geen actiepunten automatisch gevonden" in the success notification

---

### Requirement: REQ-AIA-002 Configurable extraction patterns per governance body
The system SHALL allow an admin to configure action item extraction patterns per GovernanceBody. Patterns SHALL be stored as a JSON array in `IAppConfig` under the key `decidesk.extractionPatterns.{bodyId}`. Each pattern SHALL have a `name`, `pattern` (regex), and optional `assigneeHint` field. The configuration UI SHALL provide a test mode to validate patterns against sample content without creating VTODOs.

#### Scenario: Admin configures custom extraction pattern
- **GIVEN** an admin is on the GovernanceBody settings panel
- **WHEN** the admin adds a pattern `{ "name": "actiepunt", "pattern": "ACTIEPUNT:\\s*(.+?)(?=ACTIEPUNT|BESLUIT|$)" }` and saves
- **THEN** the pattern SHALL be stored in `IAppConfig` and applied during the next extraction run for that body

#### Scenario: Pattern test mode validates without side effects
- **GIVEN** an admin has entered a new extraction pattern
- **WHEN** the admin clicks "Patroon testen" and inputs sample minutes content
- **THEN** the system SHALL display which action items would be extracted WITHOUT creating any CalDAV VTODOs

---

### Requirement: REQ-AIA-003 Manual action item creation within minutes editor
The system SHALL allow a clerk to manually create an ActionItem from within the Minutes editor by clicking "Actiepunt toevoegen", entering title, assignee, and dueDate, and saving. This SHALL create a CalDAV VTODO immediately (not deferred to approval). Manual ActionItems SHALL be linked to the current meeting via `X-DECIDESK-MEETING-UID`.

#### Scenario: Clerk manually creates action item during meeting
- **GIVEN** a Minutes editor open for an active meeting
- **WHEN** the clerk clicks "Actiepunt toevoegen", fills in title "Klachtenformulier aanpassen", assignee "L. Haisma", dueDate "2026-05-17", and saves
- **THEN** the system SHALL create a VTODO with `SUMMARY: "Klachtenformulier aanpassen"`, `ATTENDEE: L. Haisma`, `DUE: 20260517`, `STATUS: NEEDS-ACTION`, and `X-DECIDESK-MEETING-UID` set

---

### Requirement: REQ-AIA-004 Action item status lifecycle tracking
The system SHALL support status transitions for ActionItems (CalDAV VTODOs) across three states: `NEEDS-ACTION` (pending), `IN-PROCESS` (in progress), `COMPLETED`. Status updates SHALL be persisted via `CalDavService` updating the VTODO's `STATUS` property. A `COMPLETED` VTODO SHALL record the `COMPLETED` timestamp property. The `caldav:VTODO` type annotation SHALL be used.

#### Scenario: Action item marked complete
- **GIVEN** a CalDAV VTODO with `STATUS: IN-PROCESS` and `X-DECIDESK-MEETING-UID` set
- **WHEN** the assignee marks it complete in the Decidesk interface
- **THEN** the system SHALL update the VTODO to `STATUS: COMPLETED` with `COMPLETED` timestamp set to the current UTC datetime

---

<!-- ===================================================================== -->
<!-- Capability: decision-publication-ori                                    -->
<!-- ===================================================================== -->

### Requirement: REQ-DPO-001 ORI Reports endpoint for published minutes
The system SHALL expose a public, read-only endpoint `GET /api/ori/v1/reports` that returns Minutes objects with `lifecycle: published` serialized to the ORI Report schema. The endpoint SHALL be annotated `#[PublicPage]` and `#[NoCSRFRequired]`. The `OriSerializer` service SHALL map Minutes fields to ORI Report fields per the mapping in ADR-003. Response SHALL be paginated with `_page` and `_limit` parameters, returning `total`, `page`, `pages`, and `results` array.

#### Scenario: Public retrieval of published minutes
- **GIVEN** three Minutes objects exist — two with `lifecycle: published`, one with `lifecycle: approved`
- **WHEN** an unauthenticated client sends `GET /api/ori/v1/reports`
- **THEN** the system SHALL return HTTP 200 with exactly two ORI Report objects in the `results` array, with `total: 2`

#### Scenario: ORI field mapping correct for minutes
- **GIVEN** a Minutes object with `lifecycle: published`, `title: "Notulen raadsvergadering Westerbork 15-01-2026"`, and `approvedAt: "2026-01-22T10:15:00Z"`
- **WHEN** a client retrieves it via `GET /api/ori/v1/reports`
- **THEN** the ORI Report object SHALL contain `"name": "Notulen raadsvergadering Westerbork 15-01-2026"` and `"date": "2026-01-22"` mapped from `approvedAt`

#### Scenario: Unpublished minutes not exposed
- **GIVEN** a Minutes object with `lifecycle: draft`
- **WHEN** a client sends `GET /api/ori/v1/reports`
- **THEN** the response SHALL NOT include the draft Minutes object

---

### Requirement: REQ-DPO-002 ORI endpoint for published decisions (adopted motions)
The system SHALL expose `GET /api/ori/v1/motions?isPublished=true` returning adopted Motions with `isPublished: true` serialized to ORI Motion format with decision outcome fields (`decisionText`, `decisionDate`, `legalBasis`). The `opengov:Motion` type annotation SHALL be used. The endpoint SHALL be public and paginated.

#### Scenario: Published decision visible via ORI API
- **GIVEN** a Motion with `lifecycle: adopted`, `isPublished: true`, `decisionText` and `publishedAt` set
- **WHEN** a client sends `GET /api/ori/v1/motions?isPublished=true`
- **THEN** the response SHALL include the Motion with `"status": "adopted"`, `"decision_text"` mapped from `decisionText`, and `"decision_date"` mapped from `decisionDate`

#### Scenario: Rejected motions not returned as decisions
- **GIVEN** a Motion with `lifecycle: rejected`
- **WHEN** a client sends `GET /api/ori/v1/motions?isPublished=true`
- **THEN** the rejected Motion SHALL NOT appear in the response

---

### Requirement: REQ-DPO-003 Decision publication workflow
The system SHALL provide a "Publiceren" action on adopted Motions that sets `isPublished: true` and `publishedAt` to the current UTC timestamp, making the decision visible via the ORI API. This action SHALL require the user to have a governance body authority role (chair, secretary, or admin). The publication action SHALL emit a decision publication event for the notification foundation (REQ-DNT-001).

#### Scenario: Authorized user publishes decision
- **GIVEN** a Motion with `lifecycle: adopted` and `isPublished: false`
- **WHEN** an authorized chair user clicks "Publiceren"
- **THEN** the system SHALL set `isPublished: true`, `publishedAt` to current timestamp, and emit a `decision.published` event

#### Scenario: Unauthorized user cannot publish
- **GIVEN** a Motion with `lifecycle: adopted` and `isPublished: false`
- **WHEN** a user without governance body authority role attempts to set `isPublished: true` via the API
- **THEN** the system SHALL return HTTP 403 and leave `isPublished: false`

---

<!-- ===================================================================== -->
<!-- Capability: decision-discovery                                          -->
<!-- ===================================================================== -->

### Requirement: REQ-DDS-001 Full-text search on decisions
The system SHALL support full-text search on adopted Motions (decisions) across the fields `title`, `text`, `decisionText`, and `legalBasis` using OpenRegister's `IndexService`. The `schema:SearchAction` type annotation SHALL be used for the search action. Search results SHALL be ranked by relevance and paginated. The search endpoint SHALL be `GET /api/ori/v1/motions?search={query}` for public ORI access and the standard OpenRegister object search for internal access.

#### Scenario: Full-text search returns relevant decisions
- **GIVEN** three adopted Motions exist, one with `decisionText` containing "dijkversterking Vecht"
- **WHEN** a user searches for "dijkversterking"
- **THEN** the system SHALL return the matching Motion in the results with relevance ranking

#### Scenario: Search across multiple decision fields
- **GIVEN** an adopted Motion with `legalBasis: "Wet ruimtelijke ordening artikel 3.1"` and no mention of "ruimtelijke" in other fields
- **WHEN** a user searches for "ruimtelijke ordening"
- **THEN** the system SHALL return the Motion because `legalBasis` is indexed

---

### Requirement: REQ-DDS-002 Faceted filtering of decisions
The system SHALL provide faceted filtering for decisions using `CnFacetSidebar` with at minimum the following facets: `decisionDate` (date range), `lifecycle` (adopted/rejected/withdrawn), `isPublished` (boolean), GovernanceBody (linked body name), and `legalBasis` (keyword). Facet counts SHALL be provided alongside each facet option.

#### Scenario: Filter decisions by date range
- **GIVEN** decisions from January, February, and March 2026
- **WHEN** the user applies a date range filter `decisionDate: 2026-02-01 to 2026-02-28`
- **THEN** only February decisions SHALL be displayed

#### Scenario: Filter by governance body
- **GIVEN** decisions from two different GovernanceBodies
- **WHEN** the user selects one GovernanceBody from the facet sidebar
- **THEN** only decisions from that GovernanceBody SHALL be shown with correct facet counts

---

### Requirement: REQ-DDS-003 Decision export in multiple formats
The system SHALL support export of filtered decision lists in CSV, JSON, and PDF formats using OpenRegister's `ExportService` and `CnMassExportDialog`. The export SHALL include all visible columns and apply any active filters. PDF export SHALL use a business-specific template with the GovernanceBody name, export date, and applied filters in the header.

#### Scenario: Export filtered decisions to CSV
- **GIVEN** a filtered decision list showing 15 adopted decisions from a specific GovernanceBody
- **WHEN** the user selects "Exporteren" → "CSV"
- **THEN** the system SHALL download a CSV file containing the 15 decisions with all displayed columns

#### Scenario: Export includes only filtered results
- **GIVEN** the decision list is filtered to show only `lifecycle: adopted` decisions
- **WHEN** the user exports to JSON
- **THEN** the exported JSON SHALL contain only adopted decisions, not rejected or withdrawn ones

---

### Requirement: REQ-DDS-004 Decision relationship discovery
The system SHALL display related entities on the adopted Motion detail page: linked AgendaItem (via relation), linked Minutes (via the meeting's Minutes relation), linked VotingRound (via one-to-many relation), and linked ActionItems (via `X-DECIDESK-MOTION-UID` lookup in CalDAV). Each related entity SHALL be shown in a `CnDetailCard` section.

#### Scenario: Related entities visible on decision detail
- **GIVEN** an adopted Motion linked to an AgendaItem, a VotingRound, and CalDAV VTODOs with `X-DECIDESK-MOTION-UID`
- **WHEN** a user views the Motion detail page
- **THEN** the page SHALL display `CnDetailCard` sections for: "Agendapunt", "Stemronde", "Actiepunten"

---

<!-- ===================================================================== -->
<!-- Capability: decision-notifications                                      -->
<!-- ===================================================================== -->

### Requirement: REQ-DNT-001 Event emission on decision state transitions
The system SHALL emit a structured event when a Motion transitions to `isPublished: true` (decision published) and when Minutes transition to `lifecycle: published`. Events SHALL use the OpenRegister `NotificationService` with CloudEvents format. The event payload SHALL include: `entityType`, `entityId`, `governanceBodyId`, `actorUid`, `transitionTo`, `timestamp`. Events SHALL be emitted server-side, never relying on frontend to trigger notification dispatch.

#### Scenario: Decision publication event emitted
- **GIVEN** a Motion with `isPublished: false`
- **WHEN** an authorized user publishes the decision (sets `isPublished: true`)
- **THEN** the system SHALL emit a `decision.published` CloudEvent with correct payload fields via `NotificationService`

#### Scenario: Minutes publication event emitted
- **GIVEN** a Minutes object transitioning to `lifecycle: published`
- **WHEN** the workflow engine executes the approved → published transition
- **THEN** the system SHALL emit a `minutes.published` event with `entityId`, `governanceBodyId`, and `timestamp`

---

### Requirement: REQ-DNT-002 Notification preference configuration per governance body
The system SHALL provide a notification preference configuration UI per GovernanceBody, allowing an admin to specify which event types trigger notifications and which Nextcloud users or groups receive them. Preferences SHALL be stored via `IAppConfig` with key `decidesk.notificationPrefs.{bodyId}`. This forms the foundation for future integrations (mail, webhook, Slack) — the current implementation SHALL deliver Nextcloud in-app notifications only.

#### Scenario: Configure notification recipients for decision publication
- **GIVEN** an admin is on the GovernanceBody notification settings panel
- **WHEN** the admin enables "Besluit gepubliceerd" for Nextcloud group "raadsleden"
- **THEN** when a decision is published for that body, all members of the "raadsleden" group SHALL receive a Nextcloud notification via `NotificationService`

#### Scenario: Notification preference stored and retrieved
- **GIVEN** an admin saves notification preferences `{ "events": ["decision.published"], "recipients": [{"type": "group", "id": "raadsleden"}] }`
- **WHEN** the settings page is reloaded
- **THEN** the previously saved preferences SHALL be displayed correctly

---

### Requirement: REQ-MAD-001 Minutes entity expanded with lifecycle, signatures, and version tracking
The Minutes entity (ORI: `meeting:Report`, subclass of `schema:CreativeWork`) SHALL include the following fields in addition to the base properties defined in the previous `p2-minutes-and-decisions` change: `lifecycle` (string, required, values: `draft`, `submitted`, `approved`, `published`), `signedBy` (array of user UIDs, optional, default `[]`), `version` (integer, optional, default `1`), `approvedAt` (datetime, optional). These fields SHALL be reflected in the Minutes OpenRegister schema in `lib/Settings/decidesk_register.json` and SHALL be accessible via the standard OpenRegister CRUD API. All schema changes are non-breaking (optional additions).

#### Scenario: Minutes created with default lifecycle
- **GIVEN** a new Minutes object is created via `POST /api/objects/minutes`
- **WHEN** the `lifecycle` field is not specified in the request body
- **THEN** the system SHALL set `lifecycle: draft`, `version: 1`, and `signedBy: []` as defaults

#### Scenario: Minutes lifecycle exposed in list and detail views
- **GIVEN** a Minutes object with `lifecycle: submitted`
- **WHEN** a user views the Minutes list page
- **THEN** the lifecycle state SHALL be displayed as a `CnStatusBadge` with appropriate color per state (draft: grey, submitted: blue, approved: green, published: teal)

#### Scenario: Minutes with full fields retrieved correctly
- **GIVEN** a Minutes object with `signedBy: ["uid-hendriks-voorzitter", "uid-smit-griffier"]`, `version: 3`, `lifecycle: published`
- **WHEN** the Minutes object is retrieved via `GET /api/objects/minutes/{id}`
- **THEN** the response SHALL include all three fields with their stored values

---

### Requirement: REQ-MAD-002 Motion entity decision outcome fields activated
The Motion entity (Popolo: `opengov:Motion`) SHALL expose the decision outcome fields `decisionText` (string, optional), `decisionDate` (datetime, optional), `isPublished` (boolean, optional, default `false`), `publishedAt` (datetime, optional), and `legalBasis` (string, optional) through the standard OpenRegister API and in the Motion detail view's `CnDetailCard`. These fields are defined in ADR-000 and were previously not operationally wired. Akoma Ntoso vocabulary (`FRBRthis`, `FRBRuri`) SHALL be used as a secondary annotation for legislative document identification where applicable. ORI API field mappings: `decisionText` → `decision_text`, `decisionDate` → `decision_date`, `legalBasis` → `legal_basis`.

#### Scenario: Adopted motion displays decision fields in detail view
- **GIVEN** a Motion with `lifecycle: adopted`, `decisionText: "De raad stelt bestemmingsplan vast"`, `legalBasis: "Wro artikel 3.1"`
- **WHEN** a user views the Motion detail page
- **THEN** the detail page SHALL display `decisionText` and `legalBasis` in a "Besluit" `CnDetailCard` section, and `isPublished` as a `CnStatusBadge`

#### Scenario: Decision fields not shown for non-adopted motions
- **GIVEN** a Motion with `lifecycle: submitted`
- **WHEN** a user views the Motion detail page
- **THEN** the "Besluit" section SHALL NOT be displayed (no empty decision card shown)

#### Scenario: ORI field mapping verified
- **GIVEN** an adopted Motion with `isPublished: true`, `decisionText: "Vastgesteld"`, `decisionDate: "2026-01-20T20:15:00Z"`, `legalBasis: "Wro 3.1"`
- **WHEN** the ORI endpoint `GET /api/ori/v1/motions?isPublished=true` is called
- **THEN** the response SHALL contain `"decision_text": "Vastgesteld"`, `"decision_date": "2026-01-20"`, `"legal_basis": "Wro 3.1"` in the ORI Motion object
