# Design: Meeting Management — Core T1

## Context

Decidesk is a universal decision-making platform for governance bodies. Meeting management is the highest-demand feature (demand score 520+, 105 tender mentions across 25+ market entries). This change (T1 — first tranche) delivers the foundational meeting management capabilities needed across all five governance domains: legislative bodies, associations, corporate governance, corporate operations, and citizen participation.

**Current state:** Decidesk has OpenRegister schemas and data model definitions (ADR-000) but no implemented meeting management features. The GovernanceBody, Person, Membership, and Post entities are defined in the data model. The CalDAV-first storage architecture (ADR-002) is decided but not yet implemented.

**Constraints:**
- ADR-002 mandates CalDAV VEVENT as primary storage for meetings — not OpenRegister
- ADR-001 mandates Popolo as the primary data standard — Meeting maps to Popolo `Event`
- ADR-003 requires ORI-compatible output for Dutch municipal consumers
- Frontend must use Vue 2 + Pinia + `@conduction/nextcloud-vue` components (ADR-004)
- All domain data except Meeting/ActionItem lives in OpenRegister (ADR-001/002)
- The Wet digitaal vergaderen (Digital Meeting Act) provides legal basis for hybrid/digital governance meetings

**Stakeholders:** Board secretaries (primary — prepare meetings, agendas, minutes), governance body chairs (run meetings), members (attend, vote), citizens (observe public meetings), IT administrators (configure), and external systems consuming ORI data.

## Goals / Non-Goals

**Goals:**
- Full meeting CRUD with CalDAV VEVENT storage and native Nextcloud Calendar integration
- Lifecycle state machine configurable per governance domain (5 presets)
- Agenda item management with ordering, typing, and duration tracking
- Attendee management via Membership relation with attendance tracking
- Quorum calculation (fixed count or percentage) with enforcement on state transitions
- Recurring meeting series with pattern-based instance generation
- Meeting list view with filtering by body, date range, and lifecycle status
- Meeting detail view with related entities, edit form, and lifecycle action buttons
- ORI API exposure for Meeting entity (Dutch open data compliance)
- Audit trail integration for all meeting changes

**Non-Goals:**
- Voting during meetings (deferred to p2-motion-and-voting)
- Minutes generation and approval workflows (deferred to p2-minutes-and-decisions)
- Speech/debate tracking and transcription (later phase — Speech entity deferred per ADR-001)
- AV/webcast integration (external system — out of scope)
- Citizen-facing public meeting portal (separate capability)
- CalDAV sync with external calendar clients (Nextcloud handles this natively)
- Meeting document generation/PDF export (deferred to docudesk integration)
- Notification/reminder system for upcoming meetings (deferred to later phase)

## Decisions

### 1. CalDAV VEVENT as primary storage for Meeting (per ADR-002)

Meetings are stored as VEVENTs in Nextcloud's CalDAV server (sabre/dav). Standard fields (SUMMARY, DTSTART, DTEND, LOCATION, DESCRIPTION, ATTENDEE, STATUS) map directly. Governance-specific metadata is stored in X-DECIDESK-* properties (RFC 5545 Section 3.8.8.2).

**Why not OpenRegister-only:** Storing meetings in OpenRegister would require a sync layer to make them visible in Nextcloud Calendar. ADR-002 explicitly rejected this approach to avoid duplicate data and sync complexity. With CalDAV-first, meetings appear instantly in the user's calendar.

**Why not dual-write (both CalDAV and OpenRegister):** Dual-write introduces consistency risks — if one write succeeds and the other fails, data drifts. A single source of truth (CalDAV) with lightweight OpenRegister wrappers avoids this entirely.

### 2. OpenRegister wrapper objects for relational queries

CalDAV has no relational query engine. For queries like "all agenda items for meeting X" or "all meetings for governance body Y", OpenRegister holds thin wrapper objects containing:
- `caldavUid` — the VEVENT UID
- `calendarId` — the Nextcloud calendar ID
- OpenRegister relations to GovernanceBody, AgendaItem, Minutes, etc.

The wrapper does NOT duplicate CalDAV data. Meeting detail is always read from the VEVENT.

**Why not query CalDAV directly for relations:** CalDAV supports CalDAV-SEARCH (RFC 5323) for basic property queries but not cross-entity joins. Governance queries (e.g., "meetings with pending quorum for body X") require relational capabilities that only OpenRegister provides.

### 3. CalDavService PHP class wrapping Nextcloud's CalDavBackend

A `CalDavService` class encapsulates all CalDAV operations:
- CRUD for VEVENTs via `\OCA\DAV\CalDAV\CalDavBackend`
- ICS parsing via `sabre/vobject` (already bundled with Nextcloud)
- X-DECIDESK-* property read/write
- Per-body calendar management (one calendar per GovernanceBody)
- ATTENDEE management mapped from Person/Membership entities

**Nextcloud OCP interfaces used:**
- `\OCA\DAV\CalDAV\CalDavBackend` — CalDAV CRUD operations
- `\OCP\Calendar\IManager` — Calendar discovery
- `\OCP\IUserSession` — User identity for calendar ownership

**Why not use CalendarEventService:** ADR-002 explicitly eliminates the CalendarEventService sync pattern. CalDavService writes directly to CalDAV — no intermediate sync.

### 4. AgendaItem stored in OpenRegister (not CalDAV)

AgendaItems have rich relational needs: linked motions, decisions, speakers, duration tracking, and ordering. CalDAV VEVENT has no concept of sub-items with relations. AgendaItems are stored as OpenRegister objects related to the Meeting wrapper.

**Schema.org type:** `meeting:AgendaItem` (ADR-000)

**Why not VALARM or custom CalDAV sub-components:** VALARM is for reminders, not agenda structure. Custom sub-components would not survive CalDAV client round-trips. OpenRegister provides the relational query capabilities agenda management requires.

### 5. Lifecycle state machine per governance domain

Each GovernanceBody has a `workflowTemplate` property (already in ADR-000) that references a domain preset. The CalDavService maps lifecycle states to the X-DECIDESK-LIFECYCLE property on the VEVENT.

**States:** `draft` → `scheduled` → `opened` → `paused` | `adjourned` → `closed` (+ `cancelled` from any state)

**Domain presets define:**
- Which transitions are allowed (e.g., legislative allows adjournment, operational does not)
- Whether quorum is enforced before `opened`
- Whether chair approval is required for state changes

| Domain | Allows Pause | Allows Adjourn | Quorum Enforced | Chair-Only Transitions |
|--------|-------------|----------------|-----------------|----------------------|
| legislative | Yes | Yes | Yes (Gemeentewet) | opened → adjourned |
| association | No | Yes | Yes (statutes) | None |
| corporate | No | No | Yes (articles) | None |
| operations | No | No | No | None |
| citizen | No | Yes | No | opened → adjourned |

**Why not a generic workflow engine (BPMN):** Meeting lifecycle is a finite state machine with at most 7 states and ~15 transitions. BPMN overhead is not justified. The domain presets are simple JSON configuration, not executable process models. If future phases require BPMN integration (e.g., for document approval flows), WorkflowEngineController can be used alongside without replacing the lifecycle FSM.

### 6. Quorum as service-layer validation

QuorumService reads `GovernanceBody.quorumRule` which is a string in format `fixed:N` or `percentage:N`. It counts active Membership records with `attendanceStatus = present` for the meeting and compares against the rule.

Quorum validation is enforced on the `scheduled → opened` transition. The chair can override if quorum is technically not met but legally sufficient (e.g., proxy votes counted separately).

**Why not a hard database constraint:** Quorum depends on runtime attendance data that changes throughout the meeting. A constraint would prevent legitimate governance procedures (e.g., starting with reduced quorum when statutes allow it). Service-layer validation with override capability matches real-world governance practices.

### 7. Frontend architecture

- **meetingStore:** `createObjectStore('meetings')` with `relationsPlugin`, `filesPlugin`, `auditTrailsPlugin`, `lifecyclePlugin`, `searchPlugin`
- **List view:** `CnIndexPage` with `useListView` composable — filters by GovernanceBody, date range, lifecycle status
- **Detail view:** `CnDetailPage` with `CnDetailCard` sections for header, schedule, attendees, agenda, minutes link
- **Forms:** `CnFormDialog` (schema-driven) for meeting creation/editing
- **Lifecycle actions:** Buttons in header-actions slot, calling `POST /api/meetings/{id}/actions/{action}`

CalDAV data (DTSTART, DTEND, ATTENDEE, LOCATION) is enriched on the backend before the API response — the frontend never queries CalDAV directly.

**Why not direct CalDAV API calls from frontend:** The backend enrichment pattern keeps the frontend simple (one API endpoint per entity) and avoids exposing CalDAV internals. It also allows the backend to merge OpenRegister relations with CalDAV data atomically.

## Risks / Trade-offs

| Risk | Impact | Mitigation |
|------|--------|------------|
| X-DECIDESK-* properties stripped by non-compliant CalDAV clients | Meeting governance metadata lost on round-trip | RFC 5545 requires X-property preservation; document warning in admin docs; validate on read |
| Quorum race condition — attendees change between check and meeting start | Meeting started without valid quorum | Re-validate quorum on every `scheduled → opened` transition; chair override available |
| OpenRegister wrapper drift from CalDAV source | Stale relational data for meetings | CalDavService updates wrapper atomically with VEVENT write; no independent wrapper mutation |
| Series generation creates many future instances | Slow creation, storage bloat | Limit to 52 instances (1 year ahead); lazy generation beyond that; cleanup job for past instances |
| Calendar permissions leak — users see meetings from bodies they don't belong to | Privacy violation in multi-body installations | Per-body calendars with Nextcloud sharing rules; CalDavService enforces body membership check |
| ADR-002 CalDAV approach is new — no existing implementation to reference | Higher implementation risk, unknown edge cases | Start with single meeting CRUD, validate CalDAV round-trip early, expand to complex features |

## Reuse Analysis (ADR-012)

### OpenRegister services leveraged

| Service | Usage |
|---------|-------|
| ObjectService | CRUD for AgendaItem, Meeting wrapper objects; Membership queries for attendees |
| AuditTrailService | Automatic change tracking for all OpenRegister entities (built-in) |
| ConfigurationService | Schema import via `importFromApp()` in repair step |
| SearchTrailService | Full-text search for meetings (via wrapper objects) |
| FileService | Agenda document attachments on meeting wrapper objects |
| AuthorizationService | RBAC on meeting CRUD operations |

### @conduction/nextcloud-vue components leveraged

| Component | Usage |
|-----------|-------|
| CnIndexPage | Meeting list view |
| CnDetailPage | Meeting detail view |
| CnFormDialog | Meeting create/edit form (schema-driven) |
| CnDetailCard | Sections: schedule, attendees, agenda, minutes |
| CnDataTable | Agenda item table, attendee table |
| CnActionsBar | List view actions (add, search, toggle) |
| CnStatusBadge | Lifecycle state display |
| CnObjectSidebar | Files, notes, audit trail tabs |
| CnDashboardPage | Dashboard with meeting widgets |
| CnChartWidget | Meeting status distribution chart |
| CnStatsBlock | Meeting KPI cards |
| CnPagination | List pagination |
| CnTimelineStages | Lifecycle state visualization |

### Nextcloud platform services leveraged

| Service | Usage |
|---------|-------|
| CalDavBackend | VEVENT storage (primary meeting storage per ADR-002) |
| sabre/vobject | ICS parsing for X-DECIDESK-* properties |
| IRepairStep | Schema registration on install |
| IGroupManager | Admin authorization checks |
| IL10N | Translation service |
| IUserSession | User identity |

### Deduplication findings

- **No overlap** with existing OpenRegister services for meeting-specific logic (CalDavService, QuorumService, WorkflowService are domain-specific)
- **ObjectService** handles all OpenRegister CRUD — no custom mappers needed for AgendaItem or wrapper objects
- **AuditTrailService** provides change tracking — no custom audit logging
- **createObjectStore** provides frontend CRUD store — no custom Pinia stores for data management

## Seed Data (ADR-001)

### Meeting objects (5)

```json
[
  {
    "@self": {
      "register": "decidesk-meetings",
      "schema": "meeting",
      "slug": "meeting-gemeenteraad-delft-apr-2026"
    },
    "title": "Vergadering Gemeenteraad Delft — april 2026",
    "meetingType": "regular",
    "scheduledDate": "2026-04-23T19:30:00+02:00",
    "endDate": "2026-04-23T22:00:00+02:00",
    "location": "Raadzaal, Markt 87, 2611 GS Delft",
    "meetingMode": "hybrid",
    "lifecycle": "scheduled",
    "quorumRequired": 20,
    "series": "gemeenteraad-delft-2026"
  },
  {
    "@self": {
      "register": "decidesk-meetings",
      "schema": "meeting",
      "slug": "meeting-waterschap-delfland-mei-2026"
    },
    "title": "Algemeen Bestuur Hoogheemraadschap van Delfland",
    "meetingType": "regular",
    "scheduledDate": "2026-05-14T14:00:00+02:00",
    "endDate": "2026-05-14T17:00:00+02:00",
    "location": "Gemeenlandshuis, Phoenixstraat 32, 2611 AL Delft",
    "meetingMode": "in-person",
    "lifecycle": "draft",
    "quorumRequired": 16,
    "series": "ab-delfland-2026"
  },
  {
    "@self": {
      "register": "decidesk-meetings",
      "schema": "meeting",
      "slug": "meeting-alv-de-meeuwen-2026"
    },
    "title": "Algemene Ledenvergadering Sportvereniging De Meeuwen",
    "meetingType": "regular",
    "scheduledDate": "2026-06-10T20:00:00+02:00",
    "endDate": "2026-06-10T22:00:00+02:00",
    "location": "Clubhuis De Meeuwen, Sportlaan 5, 2624 KH Delft",
    "meetingMode": "in-person",
    "lifecycle": "scheduled",
    "quorumRequired": 25,
    "series": null
  },
  {
    "@self": {
      "register": "decidesk-meetings",
      "schema": "meeting",
      "slug": "meeting-rvc-havenschap-apr-2026"
    },
    "title": "Vergadering Raad van Commissarissen Havenschap Delft-Schiedam N.V.",
    "meetingType": "regular",
    "scheduledDate": "2026-04-28T10:00:00+02:00",
    "endDate": "2026-04-28T12:30:00+02:00",
    "location": "Boardroom, Schieweg 15, 2627 AN Delft",
    "meetingMode": "digital",
    "lifecycle": "scheduled",
    "quorumRequired": 3,
    "series": "rvc-havenschap-2026-q2"
  },
  {
    "@self": {
      "register": "decidesk-meetings",
      "schema": "meeting",
      "slug": "meeting-inspraak-westzone-2026"
    },
    "title": "Inspraakavond Bestemmingsplan Westzone",
    "meetingType": "public-hearing",
    "scheduledDate": "2026-05-20T19:00:00+02:00",
    "endDate": "2026-05-20T21:30:00+02:00",
    "location": "Cultuurhuis De Schakel, Westplantsoen 12, 2613 GL Delft",
    "meetingMode": "hybrid",
    "lifecycle": "draft",
    "quorumRequired": null,
    "series": null
  }
]
```

### AgendaItem objects (5)

```json
[
  {
    "@self": {
      "register": "decidesk-meetings",
      "schema": "agenda-item",
      "slug": "agendaitem-gr-delft-apr-opening"
    },
    "title": "Opening en mededelingen",
    "itemType": "procedural",
    "orderNumber": 1,
    "estimatedDuration": 5,
    "actualDuration": null,
    "description": "Opening door de voorzitter, vaststelling agenda, ingekomen stukken en mededelingen.",
    "isRecurring": true
  },
  {
    "@self": {
      "register": "decidesk-meetings",
      "schema": "agenda-item",
      "slug": "agendaitem-gr-delft-apr-notulen"
    },
    "title": "Vaststelling notulen vorige vergadering",
    "itemType": "procedural",
    "orderNumber": 2,
    "estimatedDuration": 10,
    "actualDuration": null,
    "description": "Vaststelling van de notulen van de raadsvergadering van 26 maart 2026.",
    "isRecurring": true
  },
  {
    "@self": {
      "register": "decidesk-meetings",
      "schema": "agenda-item",
      "slug": "agendaitem-gr-delft-apr-bestemmingsplan"
    },
    "title": "Bestemmingsplan Westzone — vaststelling",
    "itemType": "decision",
    "orderNumber": 3,
    "estimatedDuration": 45,
    "actualDuration": null,
    "description": "Behandeling en vaststelling van het bestemmingsplan Westzone, inclusief nota van beantwoording zienswijzen.",
    "isRecurring": false
  },
  {
    "@self": {
      "register": "decidesk-meetings",
      "schema": "agenda-item",
      "slug": "agendaitem-gr-delft-apr-motie-energie"
    },
    "title": "Motie duurzame energie gemeentelijke gebouwen",
    "itemType": "motion",
    "orderNumber": 4,
    "estimatedDuration": 30,
    "actualDuration": null,
    "description": "Behandeling motie ingediend door fractie GroenLinks over verduurzaming van gemeentelijk vastgoed.",
    "isRecurring": false
  },
  {
    "@self": {
      "register": "decidesk-meetings",
      "schema": "agenda-item",
      "slug": "agendaitem-gr-delft-apr-rondvraag"
    },
    "title": "Rondvraag en sluiting",
    "itemType": "procedural",
    "orderNumber": 5,
    "estimatedDuration": 15,
    "actualDuration": null,
    "description": "Gelegenheid voor raadsleden om vragen te stellen aan het college. Sluiting door de voorzitter.",
    "isRecurring": true
  }
]
```

## Open Questions

1. **Calendar sharing model:** Should per-body calendars be shared with all body members automatically, or should sharing be configured explicitly by the admin? Recommendation: auto-share with body members via Membership relation.
2. **Quorum override audit:** When the chair overrides a quorum failure, should this require a reason text that is captured in the audit trail? Recommendation: yes, mandatory reason field.
3. **Series deletion cascade:** When a meeting series is deleted, should all future instances be cancelled or deleted? Recommendation: cancel (preserve history), don't delete.
4. **CalDAV ATTENDEE mapping:** Should external attendees (guests without a Person record) be represented as CalDAV ATTENDEEs with email-only references? Recommendation: yes, using `ATTENDEE;CN=Guest Name:mailto:guest@example.nl` format.
