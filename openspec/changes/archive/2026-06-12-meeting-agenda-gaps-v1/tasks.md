# Tasks: meeting+agenda gaps v1

## 1. Schemas (additive)

- [x] 1.1 Meeting schema: add `eventAttendanceMode` (enum of the three
      Schema.org attendance-mode URIs, property-level
      `x-openregister.schemaType: schema:eventAttendanceMode`),
      `virtualLocation` (uri, `schemaType: schema:VirtualLocation`),
      `seriesPattern` (object), append `general_assembly` to the
      meetingType enum; bump schema version.
- [x] 1.2 AgendaItem schema: add `parentItem` (string UUID of parent item);
      bump schema version.
- [x] 1.3 BoardMeeting schema: add `noticePeriodDays` (integer, default 15)
      and `noticeDeliveries` (array of per-recipient entries); bump schema
      version.

## 2. Backend — series generation

- [x] 2.1 `lib/Service/MeetingSeriesService.php`: `expandPattern()` (pure
      date expansion: daily/weekly/monthly, interval, until, exceptions,
      52-instance cap with warning, day-of-month skip semantics).
- [x] 2.2 `MeetingSeriesService::generateSeries()`: load template via
      ObjectService (RBAC), derive/reuse series slug, stamp
      `series`+`seriesPattern` on template, create per-date instances with
      `lifecycle: scheduled`, audit-log the generation.
- [x] 2.3 `MeetingController::createSeries()` + route
      `POST /api/meetings/{id}/series` (NoAdminRequired + auth + OR RBAC
      guard pattern).

## 3. Backend — convocation delivery tracking

- [x] 3.1 `BoardMeetingService::getNoticeDeadlineInfo()` — pure deadline
      computation (noticePeriodDays default 15; warnings for after-deadline
      and within-3-days sends).
- [x] 3.2 `BoardMeetingService::sendNotice()` — resolve board members via
      BoardMemberService, write per-recipient `noticeDeliveries` entries +
      `noticeSentDate`, return `warnings`, include recipient count in the
      audit entry.

## 4. Backend — document package

- [x] 4.1 `lib/Service/MeetingPackageService.php`: `buildTableOfContents()`
      (pure markdown TOC) and `assemble()` (meeting RBAC guard, agenda-item
      collection, per-item `NN - title/` folders, defensive file copy with
      `skipped` reporting, TOC file).
- [x] 4.2 `MeetingController::assemblePackage()` + route
      `POST /api/meetings/{id}/package`.

## 5. Frontend

- [x] 5.1 `src/services/agendaRules.js`: `STATUTORY_ALV_ITEMS`,
      `missingStatutoryItems()`, `buildAgendaTree()`, `flattenTree()`,
      `expandRecurrence()` (mirror of PHP expansion for the preview).
- [x] 5.2 Extract AgendaBuilder's inline dialogs to `src/dialogs/`
      (RecurringItemsDialog, ProposeAgendaItemDialog, SpokespersonDialog)
      and add `AddSubItemDialog`.
- [x] 5.3 AgendaBuilder: `meetingType` prop + statutory-items warning;
      nested sub-item rendering with per-sibling-group reorder and
      parent→children flatten persisted via the existing reorder endpoint;
      "Add sub-item" action.
- [x] 5.4 `src/components/tabs/MeetingSeriesTab.vue` + registry entry +
      MeetingDetail manifest `sidebarTabs` entry: pattern form, preview
      count, generate action, instance list.
- [x] 5.5 MeetingAgendaTab: tree-ordered rows with sub-item indicator,
      statutory warning (fetches parent meeting type), "Assemble meeting
      package" action calling the new endpoint and linking the folder.
- [x] 5.6 BoardMeetingDetail: fix the broken send-notice URL
      (`/notice` → `/send-notice`), render statutory-deadline warning and
      the per-recipient delivery table; OR object-API fallback for the
      meeting fetch.
- [x] 5.7 LiveMeeting passes `meeting-type` to AgendaBuilder.

## 6. i18n

- [x] 6.1 New strings extracted into `l10n/en.json` (English keys) and
      translated across the app's l10n set (`en`, `en_US`, `nl` — the
      only language files decidesk ships).

## 7. Tests

- [x] 7.1 PHPUnit `MeetingSeriesServiceTest`: expansion dates (monthly 9
      instances Apr→Dec), exceptions skipped, 52 cap, validation errors,
      generation creates instances with shared slug + template stamped.
- [x] 7.2 PHPUnit `BoardMeetingServiceTest` additions: per-recipient
      delivery entries written, deadline warnings (late / within 3 days /
      comfortable), recipient count audited.
- [x] 7.3 PHPUnit `MeetingPackageServiceTest`: TOC content ordered by item
      number, meeting-not-found guard.
- [x] 7.4 Vitest `agendaRules.spec.js`: recurrence expansion mirror,
      statutory matcher (missing/complete/non-ALV), tree build + flatten.
- [x] 7.5 Playwright spec-coverage additions for the new/changed scenarios
      (series tab UI, ALV warning, sub-item nesting, package action,
      delivery table) — UI-only.
- [x] 7.6 Newman: contract requests for `POST /api/meetings/{id}/series`
      and `POST /api/meetings/{id}/package` (401 unauthenticated, 4xx
      validation, happy path).

## 8. Verification

- [x] 8.1 `php -l` all changed PHP; PHPUnit unit suite green; vitest green;
      `npm run build` clean; l10n check green.
- [x] 8.2 Hydra gates pass on the diff.
- [x] 8.3 Archive change; update both main specs' status/status-note.
