# Design: meeting+agenda gaps v1

## Context

Decidesk is manifest-driven (CnAppRoot, ADR-036 registry). Meeting/AgendaItem
CRUD is delegated to OpenRegister's object API (ADR-022); decidesk owns only
guarded governance endpoints. The board portal (BoardMeeting*) is a classic
registry-page surface with its own controllers using
`BoardPortalControllerTrait`. The meeting-series capability spec
(REQ-MSR-001..005) and digital-meetings-and-recurrence spec (REQ-RMS-001..004)
already define series *semantics* (series slug on each instance, pattern JSON
on the template, 52-instance cap, exceptions); what is missing is the
*generation engine and UI* — this change builds exactly that and nothing that
duplicates the existing series filter/badge work.

## Decisions

### 1. Series generation: service + detail tab (not the create form)

`MeetingSeriesService` lives next to `MeetingService` and owns:

- `expandPattern(string $startDate, array $pattern): array` — pure date
  expansion. Frequencies `daily|weekly|monthly` with `interval >= 1`,
  mandatory `until` (inclusive), optional `exceptions` (Y-m-d strings,
  matched on the date part). Returns ISO datetimes *including* the template
  date, preserving the template's time-of-day and timezone offset. Hard cap
  `MAX_INSTANCES = 52` (logs a warning when truncated) per REQ-MSR-001-S3.
  Monthly steps use "same day-of-month" semantics; months lacking the day
  (e.g. 31st) are skipped rather than rolled over, so "monthly on the 31st"
  never silently lands on the 1st/3rd.
- `generateSeries(string $meetingId, array $pattern, string $actor): array` —
  loads the template via `ObjectService::find()` (OR RBAC: null → not found
  for this caller), derives the series slug
  (`slugified-title-YYYY`, reusing an existing `series` value when present),
  stamps `series` + `seriesPattern` on the template, and creates one new
  Meeting object per expanded date (excluding the template's own date) with
  copied descriptive fields and `lifecycle: scheduled`. Each instance is an
  independent OR object → independently editable (REQ-MSR-002 untouched).

UI: a new `MeetingSeriesTab` sidebar tab on MeetingDetail (manifest
`sidebarTabs` + registry entry) — pattern form (frequency NcSelect with
`inputLabel`, interval, until, exceptions), live preview count computed by the
mirrored frontend `expandRecurrence()`, a generate action calling
`POST /api/meetings/{id}/series`, and the instance list
(`fetchCollection('meeting', { series })`). The Meeting create form is a
generic CnFormDialog and is not forked.

### 2. Delivery tracking on the meeting object, not a new schema

`noticeDeliveries` is an array property on BoardMeeting — one entry per board
member at send time: `{ recipient, displayName, role, channel: 'portal',
status: 'sent', sentAt }`. Rationale: the delivery record is part of the
meeting's legal audit story (BW 2:225 proof of notice), is bounded (board
sizes), is written once at send-notice, and avoids a new schema + RBAC
surface. The audit-log entry now carries the recipient count. Recipients are
resolved through the existing `BoardMemberService::listForBoard()`.

Deadline logic is a separate pure method
`getNoticeDeadlineInfo(array $meeting, ?DateTimeImmutable $now): array`
returning `{deadline, daysUntilDeadline, warnings[]}` so PHPUnit can pin the
clock: warning when `now > deadline` (sent after the statutory deadline) and
when `0 <= daysUntilDeadline <= 3` (spec: "warning MUST be shown if sending
within 3 days of the deadline"). `noticePeriodDays` defaults to 15 (BW 2:225
BV / typical ALV statutes) and is configurable per meeting via the additive
schema property.

### 3. Schema.org: property-level x-openregister annotations

The Meeting schema already declares `x-openregister.schemaType: schema:Event`.
The two new properties carry their own `x-openregister.schemaType`
(`schema:eventAttendanceMode`, `schema:VirtualLocation`) so OR consumers and
the ORI export can map them without app-side translation. `eventAttendanceMode`
is an enum of the three Schema.org URIs in compact form. Additive; the
RegisterJsonTest schemaType assertions are unaffected (schema-level type
unchanged).

### 4. Statutory items: shared pure module, warning-only

`src/services/agendaRules.js` exports `STATUTORY_ALV_ITEMS` (8 items, each
with en+nl match synonyms) and `missingStatutoryItems(meetingType, items)`
(case-insensitive substring match on item titles; only active for
`general_assembly`). The spec mandates *prompt + warning*, not a hard block —
both AgendaBuilder and MeetingAgendaTab render an `NcNoteCard`/`CnNoteCard`
warning listing the missing items. Vitest covers the matcher.

### 5. Sub-items: parentItem + grouped flatten reorder

`parentItem` (UUID string) on AgendaItem. `buildAgendaTree(items)` groups
children under their parent (both levels sorted by `orderNumber`); unknown
parents degrade to top-level (no orphan loss). Reorder UX: drag and the
keyboard arrows move *top-level* items; sub-items move within their sibling
group. Persisting always flattens parent→children and PUTs the full id order
to the existing `/api/agendas/{meetingId}/reorder` endpoint —
`AgendaService::reorderItems()` keeps assigning global sequential
orderNumbers, which preserves sibling order on reload (children sort within
the parent group by their global number). No backend change needed.

AgendaBuilder's three pre-existing inline `NcDialog`s violate the
modal-isolation gate now that the file is touched — they are extracted to
`src/dialogs/` (`RecurringItemsDialog`, `ProposeAgendaItemDialog`,
`SpokespersonDialog`) together with the new `AddSubItemDialog`.

### 6. Package assembly: user-files folder + generated TOC

`MeetingPackageService::assemble(meetingId, userId)`:

1. `ObjectService::find()` the meeting (RBAC guard — callers without read
   access get "not found").
2. `findAll` the meeting's agenda items (filter `meeting`), sorted by
   `orderNumber`.
3. Create `Decidesk packages/<meeting title> (<date>)/` under the acting
   user's files; per item a `NN - <title>/` folder; copy each linked file
   node (resolved via `files: true` object serialization → user-folder node
   by id, falling back to path); unresolvable files are recorded in
   `skipped`, never fatal.
4. Write `00 - Table of contents.md` from the pure
   `buildTableOfContents(meeting, items)` (unit-tested): meeting header +
   numbered items + per-item document list, organized by agenda item number
   and title per the spec scenario.

Returns `{success, path, items, files, skipped, message}` so the UI can link
the folder ("available for download and distribution via convocation" — the
folder lives in normal NC Files, so sharing/download falls out of the
platform). Endpoint: `POST /api/meetings/{id}/package` on MeetingController
(auth required + OR RBAC via the find guard, same pattern as `lifecycle`).

## Risks / Trade-offs

- **Series instances are plain OR objects** — no CalDAV VEVENT generation in
  this change. The digital-meetings spec routes RRULE through
  `CalDavService::createOrUpdateVEvent()`, which is a separate (stubbed)
  surface; the OR wrapper path (what every list/detail/filter UI consumes) is
  the one completed here. Documented in the spec delta.
- **File copy depends on OR file metadata shape** — entry resolution is
  defensive (`id` → `getById`, `path` → `get`), with `skipped` reporting
  instead of failures, because OR attachment serialization differs across
  versions.
- **Delivery status granularity** — entries are written as `sent` (portal
  channel); per-channel delivered/failed updates are a future hook (the
  status enum in the schema already allows them).
