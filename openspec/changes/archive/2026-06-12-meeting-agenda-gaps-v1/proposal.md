# Proposal: meeting+agenda gaps v1

## Why

The 2026-06-12 audit of the two seeded MVP specs left both at `status: partial`:

- **meeting-management** — 4/5 requirements built; missing recurring-meeting
  *generation* (the series field, filter and badge exist, but nothing turns a
  recurrence pattern into actual meeting instances), per-recipient convocation
  delivery tracking (BW 2:225 / BW 2:38 proof of notice), and the Schema.org
  `eventAttendanceMode` / `virtualLocation` mapping promised by the spec's
  acceptance criteria.
- **agenda-management** — 3.5/4 requirements built; missing statutory ALV
  agenda-item enforcement (BW 2:38 — annual report, financial statements,
  kascommissie report, board elections...), hierarchical sub-items, and
  meeting document-package assembly (vergaderstukken).

These are the remaining legal-compliance and completeness gaps blocking both
specs from reaching `status: complete`.

## What Changes

One change closes all six gaps:

1. **Recurring meeting generation** — new `MeetingSeriesService` that expands a
   recurrence pattern (`{frequency, interval, until, exceptions}` per the
   meeting-series capability spec REQ-MSR-001) into individual Meeting
   instances sharing a series slug, capped at 52 instances; new
   `MeetingSeriesTab` on the MeetingDetail page to configure the pattern,
   preview the instance count, generate the series, and list its instances.
2. **Convocation delivery tracking** — `BoardMeetingService::sendNotice()` now
   resolves the board's members and records a per-recipient delivery entry
   (`noticeDeliveries`: recipient, channel, status, sentAt) on the meeting,
   computes the statutory notice deadline from `noticePeriodDays`
   (default 15) and returns warnings when sending within 3 days of — or
   after — the deadline. BoardMeetingDetail shows the deadline warning and the
   per-recipient delivery table (and gets its broken send-notice URL fixed).
3. **Schema.org mapping** — additive `eventAttendanceMode` (enum of
   `schema:Offline/Online/MixedEventAttendanceMode`) and `virtualLocation`
   properties on the Meeting schema, each annotated with an `x-openregister`
   `schemaType`; `general_assembly` added to the meetingType enum.
4. **Statutory ALV agenda items** — shared `agendaRules` frontend module with
   the 8 statutory items (en+nl synonym matching); AgendaBuilder and the
   MeetingAgendaTab show a warning listing the missing statutory items
   whenever a `general_assembly` agenda is incomplete.
5. **Hierarchical sub-items** — additive `parentItem` property on AgendaItem;
   AgendaBuilder renders sub-items nested under their parent with their own
   type/duration, an "Add sub-item" action, and reorder semantics that keep
   children grouped under their parent (flattened parent→children order is
   persisted through the existing reorder endpoint).
6. **Meeting document package** — new `MeetingPackageService::assemble()`
   collects the documents linked to each agenda item into a structured
   folder (`NN - <item title>/`) in the acting user's files with a generated
   table of contents, triggered from a new "Assemble meeting package" action
   in the MeetingAgendaTab; endpoint `POST /api/meetings/{id}/package`.

## Capabilities touched

- `meeting-management` (spec delta: specs/meeting-management/spec.md)
- `agenda-management` (spec delta: specs/agenda-management/spec.md)

## Impact

- **Schemas** (`lib/Settings/decidesk_register.json`) — additive only:
  Meeting `eventAttendanceMode`, `virtualLocation`, `seriesPattern`,
  meetingType enum value `general_assembly`; AgendaItem `parentItem`;
  BoardMeeting `noticePeriodDays`, `noticeDeliveries`.
- **Backend** — new `MeetingSeriesService`, `MeetingPackageService`; extended
  `BoardMeetingService::sendNotice`; two new `MeetingController` endpoints +
  routes (`POST /api/meetings/{id}/series`, `POST /api/meetings/{id}/package`).
- **Frontend** — new `MeetingSeriesTab`, `agendaRules.js`, sub-item +
  statutory-warning support in AgendaBuilder (inline dialogs extracted to
  `src/dialogs/` per the modal-isolation gate), package action + tree
  rendering in MeetingAgendaTab, delivery table + deadline warning + URL fix
  in BoardMeetingDetail.
- **No breaking changes**: existing data without `parentItem`/`series`
  renders exactly as before; all schema changes are additive.
