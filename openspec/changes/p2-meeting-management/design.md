# Design: Meeting Management

## Context

Meeting management is the operational core of governance workflows in Decidesk. Organizations
must schedule meetings, track attendance, manage discussion flow, and maintain lifecycle
compliance across all five governance domains: municipalities, water boards, corporate boards,
NGO assemblies, and citizen participation forums.

**Current state:** The p1 changes delivered basic CRUD for Meeting objects and a simple
lifecycle endpoint. This p2 change upgrades the implementation to the full CalDAV-first
architecture (ADR-002), adds attendance tracking, speaking time management, meeting
templates, series, materials, hybrid participation modes, and ORI compatibility.

**Key constraints:**
- ADR-002 (CalDAV-first): meetings are VEVENTs in Nextcloud Calendar, not standalone
  OpenRegister rows. OpenRegister holds only a thin wrapper (`caldavUid` + `calendarId`
  + relations) for relational graph queries.
- ADR-001 (Popolo): Meeting maps to Popolo Event class and ORI Meeting/Event types
- ADR-003 (ORI): meetings must be serializable via `/api/ori/v1/events`
- ADR-005 (Security): role-based transition guards enforced server-side — never
  frontend-only (malicious clients cannot bypass by sending arbitrary lifecycle values)
- Five governance domains with differing quorum rules, meeting types, and lifecycle variants
- Wet digitaal vergaderen compliance for digital and hybrid meetings
- WCAG 2.1 AA for all lifecycle controls, attendance forms, and speaker queue UI
- sabre/vobject available via Nextcloud core `vendor/` — no new dependency required

**Stakeholders:**
- Griffier / board secretary — creates meetings, manages agenda lifecycle, records minutes
- Voorzitter / chair — opens, pauses, and adjourns; authorises quorum transitions
- Secretaris / clerk — records attendance, uploads materials, tracks speaking time
- Raadsleden / board members — speak, review agenda, receive meeting notifications
- Citizens / press — read public agenda and decisions via ORI API

---

## Goals / Non-Goals

**Goals:**
- Full CalDAV-first architecture: store meetings as VEVENTs with X-DECIDESK-* properties;
  OpenRegister wrapper objects for relational queries only
- Meeting lifecycle state machine: draft → scheduled → opened → paused → resumed →
  adjourned → closed, enforced server-side with role- and domain-aware rules
- Attendance tracking with configurable quorum calculation (absolute count, percentage,
  weighted voting) per governance body
- Speaking time management: speaker queue, per-participant time allocation, overtime tracking
- Meeting templates: reusable preset meeting type + agenda structure
- Meeting series: link related meetings with RRULE-based recurrence
- Document attachments via OpenRegister FileService + CnObjectSidebar (no custom upload UI)
- In-person, digital, and hybrid participation modes
- ORI-compatible `/api/ori/v1/events` endpoint serializing CalDAV + wrapper data
- Seed data: 3–5 realistic Dutch objects per schema (Meeting, Attendance, SpeakingTime)
  covering all five governance domains

**Non-Goals:**
- Agenda item creation and ordering (owned by p2-agenda-management)
- Motion / amendment / voting workflow (owned by p2-motion-and-voting)
- Minutes approval workflow (owned by p2-minutes-and-decisions)
- Governance body configuration UI (owned by p3-governance-bodies)
- Akoma Ntoso XML export (deferred to future phase)
- ORI harvesting protocol / push to national aggregator (future phase)
- AI-generated meeting summaries (future phase)
- Real-time WebSocket speaker queue synchronisation (future phase; polling fallback used)

---

## Decisions

### Decision 1: CalDAV as primary store (ADR-002 full implementation)

Meetings are stored as VEVENTs in Nextcloud Calendar via `\OCA\DAV\CalDAV\CalDavBackend`.
OpenRegister holds only a thin wrapper (`caldavUid` + `calendarId` + relations to
AgendaItem, Motion, Minutes). No sync layer. To get full meeting detail, the app reads
the VEVENT via `CalDavService` and merges with the wrapper.

**Why this over OpenRegister-primary:** Meetings appear natively in Nextcloud Calendar.
CalDAV clients (Thunderbird, iOS, Android) sync automatically. X-properties are RFC
5545-compliant and preserved in round-trip (Nextcloud stores raw ICS blobs in
`oc_calendarobjects`). Eliminates a sync service that adds failure modes and latency.

**Alternatives rejected:**
- OpenRegister-primary + CalDAV sync: duplicate data, sync complexity, inconsistency risk
- OpenRegister-primary without CalDAV: no Calendar integration, no external sync clients

---

### Decision 2: X-DECIDESK-* custom properties encode governance metadata in VEVENT

Governance fields are stored as `X-DECIDESK-*` custom properties in the ICS blob per
RFC 5545 §3.8.8.2. The OpenRegister wrapper holds the canonical governance state.

| Property | Values | Description |
|---|---|---|
| X-DECIDESK-LIFECYCLE | draft, scheduled, opened, paused, adjourned, closed | Meeting state machine |
| X-DECIDESK-MEETING-TYPE | regular, extraordinary, committee, public-hearing | Meeting classification |
| X-DECIDESK-MEETING-MODE | in-person, digital, hybrid | Attendance mode |
| X-DECIDESK-QUORUM-REQUIRED | integer | Minimum attendee count |
| X-DECIDESK-SERIES | string | Series identifier |
| X-DECIDESK-BODY-UID | uuid | GovernanceBody reference |

**Why X-properties:** CalDAV clients reading the VEVENT can identify it as a governance
meeting without querying OpenRegister. X-properties follow RFC 5545 and are preserved
by compliant CalDAV clients in round-trip.

**Alternatives rejected:**
- DESCRIPTION field JSON encoding: not machine-readable; breaks CalDAV display for users
- OpenRegister-only fields: VEVENT is incomplete for external CalDAV consumers

---

### Decision 3: `CalDavService` wraps sabre/vobject for ICS parsing

A dedicated `lib/Service/CalDavService.php` handles all VEVENT CRUD and uses
`sabre/vobject` (bundled in Nextcloud via `sabre/dav`) to parse/serialize ICS blobs.

**Why sabre/vobject:** Already in Nextcloud `vendor/`, proven RFC 5545 parser, handles
custom X-properties natively. No additional composer dependency.

**Alternatives rejected:**
- Raw string manipulation of ICS: fragile, RFC non-compliant
- Custom ICS parser: unnecessary reinvention; existing library covers the use case

---

### Decision 4: `LifecycleService` enforces domain-aware state transitions

State machine: `draft → scheduled → opened → paused → resumed → adjourned → closed`.
`LifecycleService` validates:
1. Allowed transitions from the current state (static transition table)
2. Role authorization — only chair (`role=chair`) can open or adjourn; secretary can close
3. Prerequisite conditions — quorum must be met before transitioning to `opened`

Domain-specific rules are configured via `workflowTemplate` on `GovernanceBody`.

**Why not frontend-only:** ADR-005 prohibits frontend-only authorization. Different
domains (Gemeentewet, Dutch Corporate Governance Code, association statutes) have
incompatible rules that must be enforced server-side.

**Alternatives rejected:**
- Generic OpenRegister lifecycle plugin only: covers basic transitions but lacks
  domain-specific role checks and the quorum gate before opening

---

### Decision 5: Attendance records in OpenRegister (not in CalDAV ATTENDEE only)

`Attendance` objects store actual presence status (present / absent / late), arrival /
departure time, proxy delegation, and quorum contribution. CalDAV `ATTENDEE` component
holds invited participants but RFC 5545 `PARTSTAT` cannot express governance semantics.

**Why OpenRegister for attendance:** Governance-specific values (late arrival, proxy
delegation, weighted vote contribution) are not expressible in standard ATTENDEE
parameters. OpenRegister relations naturally link Attendance → Person → Meeting wrapper
→ AgendaItem.

**Alternatives rejected:**
- X-DECIDESK-ATTENDANCE-STATUS on ATTENDEE sub-component: CalDAV clients do not
  consistently preserve X-parameters on ATTENDEE components (only VEVENT-level X-props
  are reliably preserved per RFC 5545 §3.8.8.2)

---

### Decision 6: Meeting templates are OpenRegister objects (not CalDAV VEVENTs)

Templates store preset meeting type, default quorum rule, and a list of standard agenda
item titles. When applied, `MeetingTemplateService` generates a new VEVENT + wrapper.

**Why not CalDAV:** Templates have no date/time — they are configuration artefacts, not
schedulable events. Storing them as VEVENTs would pollute the user's calendar.

---

### Decision 7: Meeting series use CalDAV RRULE; wrappers created lazily

Recurring series use `RRULE` in the VEVENT for iCalendar-native recurrence. An OpenRegister
Series object stores shared configuration. Individual occurrence wrappers are created lazily
(on first access) to avoid pre-generating thousands of empty wrapper rows.

**Why RRULE:** Standard CalDAV recurrence mechanism; external CalDAV clients handle it
natively. Lazy wrapper generation avoids storage bloat for long-running series.

---

### Decision 8: ORI endpoint serializes from CalDAV + OpenRegister at read time

`OriController` (`GET /api/ori/v1/events`) reads the VEVENT from CalDAV and merges the
OpenRegister wrapper to produce an ORI-compliant `Meeting` / `Event` JSON-LD object.
No separate ORI data store. Mapping: X-DECIDESK-LIFECYCLE → ORI `status`, DTSTART →
ORI `start_date`, SUMMARY → ORI `name`, X-DECIDESK-BODY-UID → ORI `organization`.

---

## Reuse Analysis

Per ADR-012 (Deduplication), the following services are leveraged and NOT rebuilt:

| Service / Component | Usage in this change |
|---|---|
| `ObjectService.saveObject()` / `findAll()` | Attendance, SpeakingTime, MeetingWrapper CRUD |
| `FileService` + `CnObjectSidebar → CnFilesTab` | Meeting document attachments (meeting-materials) |
| `AuditTrailService` + `CnAuditTrailTab` | Change history on wrapper, Attendance, SpeakingTime |
| `AuthorizationService` + `PropertyRbacHandler` | Role-based lifecycle transition guards |
| `createObjectStore` + `lifecyclePlugin` | Meeting Pinia store with lifecycle-aware state |
| `CnIndexPage` + `useListView` | MeetingList.vue list with filter/search/pagination |
| `CnDetailPage` + `CnDetailCard` | MeetingDetail.vue with tabbed detail sections |
| `CnFormDialog` | MeetingFormDialog.vue (schema-driven create/edit) |
| `CnTimelineStages` | LifecycleStateMachine.vue lifecycle visualization |
| `CnStatusBadge` | Lifecycle state badge on MeetingCard.vue |
| `CnProgressBar` | Quorum progress bar in AttendanceList.vue |
| `NotificationService` | Meeting invite and state-change notifications |
| `ConfigurationService::importFromApp()` | Seed data loading in repair step |
| `ImportService` / `CnMassExportDialog` | Meeting data export (CSV/JSON/ICS) |

**Custom logic required** (no existing service covers these):
- `CalDavService` — VEVENT CRUD via `CalDavBackend` + sabre/vobject; no existing
  governance-aware CalDAV wrapper exists in the platform
- `LifecycleService` — domain-aware state machine with role checks and quorum gate;
  the generic `lifecyclePlugin` manages state but cannot enforce governance transition rules
- `AttendanceService` — quorum calculation (absolute / percentage / weighted voting); no
  existing quorum service in OpenRegister or shared library
- `SpeakingTimeService` — speaker queue + time allocation + overtime tracking; no
  time-management service exists in the platform
- `OriController events` endpoint — Meeting → ORI Event serialization from CalDAV data

---

## Risks / Trade-offs

**[CalDAV X-property stripping]** — Third-party CalDAV clients may discard unknown
X-properties during sync, losing governance state from the VEVENT.
→ Mitigation: OpenRegister wrapper holds the canonical governance state (lifecycle,
bodyUid) as the authoritative source. X-properties are a convenience copy.

**[Quorum rule complexity]** — Governance domains use different quorum formulas.
→ Mitigation: `quorumRule` on GovernanceBody stores a structured expression parsed and
evaluated by `AttendanceService` at runtime. Domain templates ship pre-configured rules.

**[CalDAV internal API stability]** — `CalDavService` depends on
`\OCA\DAV\CalDAV\CalDavBackend`, an internal Nextcloud class with no OCP interface.
→ Mitigation: All CalDAV access isolated in `CalDavService`; integration tests cover
round-trip behaviour on upgrade.

**[Calendar proliferation]** — Large deployments with 50+ governance bodies create
50+ dedicated CalDAV calendars.
→ Mitigation: One calendar per body, created lazily on first meeting. Nextcloud Calendar
handles multiple calendars natively; users can configure visibility.

**[RRULE wrapper proliferation]** — A series with a 5-year RRULE generates ~300
occurrences; pre-creating wrappers would be expensive.
→ Mitigation: Wrappers are created lazily (on first access of a specific occurrence).
Series metadata stored in a single OpenRegister Series object.

**[ICS export legibility]** — Standard calendar clients display X-properties as raw text,
which looks noisy in a shared calendar view.
→ Mitigation: `DESCRIPTION` field contains human-readable summary of governance metadata
as fallback for non-governance CalDAV clients.

---

## Migration Plan

This is a greenfield implementation — no Meeting data existed in Decidesk before this change.
The p1 seed data (basic lifecycle examples) is superseded by the expanded seed objects below.

**Deploy steps:**
1. Repair step runs `ConfigurationService::importFromApp('decidesk', ...)` — loads Meeting,
   Attendance, SpeakingTime schemas and seed objects into OpenRegister (idempotent)
2. CalDAV calendar `decidesk-{bodySlug}` created lazily on first meeting scheduled per body
3. `OriController` routes enabled in `appinfo/routes.php`

**Rollback:**
- Remove Meeting, Attendance, SpeakingTime schemas from OpenRegister (removes wrapper
  objects and related data)
- CalDAV events in Nextcloud Calendar persist as native events and must be manually
  removed if rollback is required (they are standard Nextcloud Calendar entries)
- Routes can be disabled without data loss (CalDAV events unaffected)

---

## Open Questions

1. **Hybrid meeting video platform**: Wet digitaal vergaderen requires identification
   and recording consent for digital participants. Does the `location` field for digital
   meetings hold a Nextcloud Talk URL, an external Zoom/Teams link, or both? Affects
   `meetingMode=digital` rendering and what identification check is performed.

2. **Real-time speaker queue sync**: Should `SpeakerQueue.vue` update in real-time
   across multiple sessions (chair screen + clerk screen)? This requires Nextcloud Push
   or WebSocket — currently not scoped. Short-interval polling is the fallback.

3. **ORI harvesting push**: Will Decidesk need to push data to the national ORI
   aggregator crawler? That requires a separate push adapter outside p2 scope.

4. **Series occurrence wrapper generation**: Should `MeetingSeriesService` pre-generate
   OpenRegister wrappers for all RRULE occurrences, or create lazily per first access?
   Pre-generation enables full-text search over future meetings but adds storage cost.

---

## Seed Data

Per ADR-001 (Seed Data), 3–5 realistic Dutch seed objects per schema are defined for
`lib/Settings/decidesk_register.json`. Objects cover all five governance domains.

### Meeting wrapper objects (OpenRegister — `caldavUid` + governance fields)

```json
[
  {
    "@self": {
      "register": "decidesk",
      "schema": "Meeting",
      "slug": "meeting-gem-amsterdam-raadsvergadering-2026-04"
    },
    "caldavUid": "meeting-gem-amsterdam-raadsvergadering-2026-04-16@decidesk",
    "calendarId": "decidesk-governancebody-gem-amsterdam",
    "title": "Raadsvergadering april 2026",
    "meetingType": "regular",
    "scheduledDate": "2026-04-16T19:30:00+02:00",
    "endDate": "2026-04-16T23:00:00+02:00",
    "location": "Stadhuis Amsterdam, Stopera, Amstel 1, 1011 PN Amsterdam",
    "meetingMode": "hybrid",
    "lifecycle": "scheduled",
    "quorumRequired": 23,
    "series": "gem-amsterdam-raadsvergadering-2026"
  },
  {
    "@self": {
      "register": "decidesk",
      "schema": "Meeting",
      "slug": "meeting-wshn-algemene-vergadering-2026-04"
    },
    "caldavUid": "meeting-wshn-algemene-vergadering-2026-04-22@decidesk",
    "calendarId": "decidesk-governancebody-wshn",
    "title": "Algemene vergadering waterschap april 2026",
    "meetingType": "regular",
    "scheduledDate": "2026-04-22T10:00:00+02:00",
    "endDate": "2026-04-22T13:00:00+02:00",
    "location": "Waterschapshuis, Nieuwe Australiëlaan 1, 1216 JS Hilversum",
    "meetingMode": "in-person",
    "lifecycle": "scheduled",
    "quorumRequired": 15,
    "series": "wshn-algemene-vergadering-2026"
  },
  {
    "@self": {
      "register": "decidesk",
      "schema": "Meeting",
      "slug": "meeting-rvc-hollands-q1-2026"
    },
    "caldavUid": "meeting-rvc-hollands-q1-2026-03-15@decidesk",
    "calendarId": "decidesk-governancebody-hollands-rvc",
    "title": "Kwartaalvergadering raad van commissarissen Q1 2026",
    "meetingType": "regular",
    "scheduledDate": "2026-03-15T14:00:00+01:00",
    "endDate": "2026-03-15T17:00:00+01:00",
    "location": "Handelsweg 2, 3707 NH Zeist",
    "meetingMode": "hybrid",
    "lifecycle": "closed",
    "quorumRequired": 5,
    "series": "hollands-rvc-kwartaalvergadering-2026"
  },
  {
    "@self": {
      "register": "decidesk",
      "schema": "Meeting",
      "slug": "meeting-ledenvereniging-alv-2026"
    },
    "caldavUid": "meeting-ledenvereniging-alv-2026-06-12@decidesk",
    "calendarId": "decidesk-governancebody-ledenvereniging",
    "title": "Algemene ledenvergadering 2026",
    "meetingType": "extraordinary",
    "scheduledDate": "2026-06-12T09:00:00+02:00",
    "endDate": "2026-06-12T17:00:00+02:00",
    "location": "Papendallaan 9, 6816 VD Arnhem",
    "meetingMode": "in-person",
    "lifecycle": "draft",
    "quorumRequired": 40,
    "series": null
  },
  {
    "@self": {
      "register": "decidesk",
      "schema": "Meeting",
      "slug": "meeting-participatieraad-utrecht-mei-2026"
    },
    "caldavUid": "meeting-participatieraad-utrecht-2026-05-08@decidesk",
    "calendarId": "decidesk-governancebody-participatieraad-utrecht",
    "title": "Participatieraad Utrecht bijeenkomst mei 2026",
    "meetingType": "public-hearing",
    "scheduledDate": "2026-05-08T18:30:00+02:00",
    "endDate": "2026-05-08T21:00:00+02:00",
    "location": "Stadsplateau 1, 3521 AZ Utrecht",
    "meetingMode": "hybrid",
    "lifecycle": "scheduled",
    "quorumRequired": 8,
    "series": null
  }
]
```

### Attendance objects (OpenRegister — actual presence per participant per meeting)

```json
[
  {
    "@self": {
      "register": "decidesk",
      "schema": "Attendance",
      "slug": "attendance-gem-amsterdam-2026-04-dejong"
    },
    "attendanceStatus": "present",
    "arrivalTime": "2026-04-16T19:25:00+02:00",
    "departureTime": null,
    "proxyFor": null,
    "note": null
  },
  {
    "@self": {
      "register": "decidesk",
      "schema": "Attendance",
      "slug": "attendance-gem-amsterdam-2026-04-visser"
    },
    "attendanceStatus": "late",
    "arrivalTime": "2026-04-16T19:47:00+02:00",
    "departureTime": null,
    "proxyFor": null,
    "note": "Vertraging openbaar vervoer"
  },
  {
    "@self": {
      "register": "decidesk",
      "schema": "Attendance",
      "slug": "attendance-gem-amsterdam-2026-04-bakker"
    },
    "attendanceStatus": "absent",
    "arrivalTime": null,
    "departureTime": null,
    "proxyFor": null,
    "note": "Bericht van verhindering ingediend"
  },
  {
    "@self": {
      "register": "decidesk",
      "schema": "Attendance",
      "slug": "attendance-wshn-2026-04-dijk"
    },
    "attendanceStatus": "present",
    "arrivalTime": "2026-04-22T09:55:00+02:00",
    "departureTime": "2026-04-22T12:45:00+02:00",
    "proxyFor": null,
    "note": null
  },
  {
    "@self": {
      "register": "decidesk",
      "schema": "Attendance",
      "slug": "attendance-wshn-2026-04-peters-proxy"
    },
    "attendanceStatus": "present",
    "arrivalTime": "2026-04-22T10:00:00+02:00",
    "departureTime": null,
    "proxyFor": "member-wshn-hendriks",
    "note": "Stemmachtiging verleend door M. Hendriks"
  }
]
```

### SpeakingTime objects (OpenRegister — per speaker per agenda item per round)

```json
[
  {
    "@self": {
      "register": "decidesk",
      "schema": "SpeakingTime",
      "slug": "speakingtime-gem-amsterdam-2026-04-dejong-item1-r1"
    },
    "allocatedSeconds": 180,
    "usedSeconds": 162,
    "isOvertime": false,
    "startedAt": "2026-04-16T19:45:00+02:00",
    "endedAt": "2026-04-16T19:47:42+02:00",
    "roundNumber": 1
  },
  {
    "@self": {
      "register": "decidesk",
      "schema": "SpeakingTime",
      "slug": "speakingtime-gem-amsterdam-2026-04-visser-item1-r1"
    },
    "allocatedSeconds": 180,
    "usedSeconds": 207,
    "isOvertime": true,
    "startedAt": "2026-04-16T19:52:00+02:00",
    "endedAt": "2026-04-16T19:55:27+02:00",
    "roundNumber": 1
  },
  {
    "@self": {
      "register": "decidesk",
      "schema": "SpeakingTime",
      "slug": "speakingtime-gem-amsterdam-2026-04-dejong-item1-r2"
    },
    "allocatedSeconds": 90,
    "usedSeconds": 74,
    "isOvertime": false,
    "startedAt": "2026-04-16T20:01:00+02:00",
    "endedAt": "2026-04-16T20:02:14+02:00",
    "roundNumber": 2
  },
  {
    "@self": {
      "register": "decidesk",
      "schema": "SpeakingTime",
      "slug": "speakingtime-wshn-2026-04-dijk-item2-r1"
    },
    "allocatedSeconds": 120,
    "usedSeconds": 118,
    "isOvertime": false,
    "startedAt": "2026-04-22T10:35:00+02:00",
    "endedAt": "2026-04-22T10:36:58+02:00",
    "roundNumber": 1
  }
]
```
