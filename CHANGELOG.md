# Changelog

All notable changes to Decidesk are documented in this file.

## [Unreleased]

### Added

- **Dashboard v2 widget components** (`decidesk-dashboard-v2-widgets`):
  eleven new dashboard widget components under
  `src/views/dashboard/widgets/`, registered in `src/registry.js` via a
  new `widget()` helper (with `defaultSize`/`minSize`/`maxSize`/
  `allowedSlots`/`propsSchema` metadata).
  - **KPI widgets** — UpcomingMeetingsKpiWidget, PendingVotesKpiWidget
    (variant="warning" when pending > 0, count 0 for users without a
    participant record), OverdueActionsKpiWidget (variant="error" when
    overdue > 0), ActiveDecisionsKpiWidget (counts decisions with
    outcome null).
  - **List widgets** — UpcomingMeetingsListWidget (24h urgency
    highlight) and PendingVotesListWidget (deadline countdown, <24h red
    urgency indicator, empty state).
  - **Process & personal widgets** — RunningProcessesWidget (motions
    grouped by lifecycle stage) and MyActionItemsWidget (open/
    in-progress items sorted by dueDate with overdue indicator).
  - **Decisions & health** — RecentDecisionsWidget (outcome +
    publication badges) and GovernanceHealthWidget (live two-series
    chart of quorumPercentage / actionItemCompletionRate).
  - **DashboardEmptyState** — fresh-install welcome with Set Up Body /
    Create Meeting / Create Decision quick actions.
- **Dashboard data layer** — `src/services/dashboardData.js` fetch
  helpers, `dashboardRefreshMixin` (dashboard-wide refresh signal
  without page remount), and pure governance computations in
  `widgetLogic.js` covered by 70 passing vitest tests.
- **i18n** — all widget strings use English source keys via
  `t('decidesk', ...)`; nl/de/fr/es/it translations added (de/fr/es/it
  l10n files newly created).

### Notes

- Widgets are component-only in this change: manifest/layout wiring
  (and the corresponding browser-rendered e2e coverage plus the
  DashboardEmptyState mount test) is deferred to the follow-up change
  `decidesk-dashboard-v2-layout`.

### Added

- **MeetingDetail IA alignment** (`refactor-decidesk-ia-alignment`):
  three new sidebar tabs on the meeting detail surface so secretaries
  can author and review meeting-scoped records without leaving the
  meeting context.
  - **Minutes** (Notulen) tab — lists `minutes` scoped to the current
    meeting, creates a draft with the meeting reference pre-filled, and
    deep-links each row to MinutesDetail.
  - **Decisions** (Besluiten) tab — lists `decision` objects for the
    meeting, creates one with the meeting reference pre-filled, and
    deep-links to DecisionDetail.
  - **Votes** (Stemmingen) tab — read-only post-meeting overview that
    walks meeting → agenda-item → motion → voting-round, shows each
    round's tally and result, and deep-links to MotionDetail's votes
    tab. Vote casting stays exclusively in LiveMeeting.

### Notes

- The top-level Minutes / Decisions / Motions register pages are
  unchanged — the new tabs are an additive per-meeting surface (the
  "split" placement), not a replacement.
- Dutch + English translations added for all new strings.
- No backend, schema, lifecycle, or permission changes.
