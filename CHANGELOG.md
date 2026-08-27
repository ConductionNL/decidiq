# Changelog

All notable changes to Decidiq are documented in this file.

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
  `t('decidiq', ...)`; nl/de/fr/es/it translations added (de/fr/es/it
  l10n files newly created).

### Changed

- **Dashboard v2 layout** (`decidesk-dashboard-v2-layout`): rewired the
  `Dashboard` page in `src/manifest.json` to the five-row, 11-widget v2
  grid with English widget titles.
  - **Row 1** — four KPI cards (Active Decisions, Upcoming meetings,
    Pending votes, Overdue actions) as custom slot widgets, each 3
    columns wide.
  - **Row 2** — Upcoming meetings list + Pending votes list (6 cols
    each).
  - **Row 3** — Running processes + My action items (6 cols each).
  - **Row 4** — Recent decisions spanning the full 12 columns.
  - **Row 5** — "Minutes awaiting approval" stats-block + Governance
    health chart (6 cols each).
  - `DashboardEmptyState` is declared in the manifest's `widgets[]` +
    `slots` map (excluded from `layout[]`) for the fresh-install empty
    state; the removed `published-decisions` and `open-action-items`
    placeholders are gone.
  - Full-dashboard Playwright e2e coverage added
    (`tests/e2e/spec-coverage/dashboard-layout.spec.ts`); host
    browser-verified 2026-06-13 — all 11 widgets render with live data.

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

## [0.1.7]

### Added

- **MCP Tools Provider** (`mcp-tools`) — first per-app exemplar of
  `OCA\OpenRegister\Mcp\IMcpToolProvider` for the OpenRegister AI Chat Companion.
  `OCA\Decidiq\Mcp\DecidiqToolProvider` exposes 5 MCP tools to the LLM:
  - `decidesk.listOpenActionItems` — list incomplete action items (scope: mine | all)
  - `decidesk.listRecentMeetings` — recent meetings ordered by date desc
  - `decidesk.getMeetingDetails` — meeting + agenda + decisions + action items inlined
  - `decidesk.startMeeting` — transition `scheduled` → `in-progress` (chair/admin only)
  - `decidesk.addActionItem` — create an action item attached to a meeting
  - Per-object authorisation runs inside `invokeTool()` (ADR-005, IDOR-safe): every
    object-targeting tool verifies the caller is a participant / chair / admin and
    returns a structured `{isError, error: 'forbidden'}` envelope on denial.
  - Every success path carries a mandatory `sources` citation array (deep links),
    capped at 20 with `sourcesTruncated` / `sourcesTotalCount` markers.
  - Registered via `registerServiceAlias('OCA\OpenRegister\Mcp\IMcpToolProvider::decidiq', …)`
    so OpenRegister's `McpToolsService` discovers it.
  - Consumes existing decidiq services (MeetingService, TaskService, ParticipantResolver)
    and OpenRegister's `ObjectService` (ADR-022/ADR-001) — no new schemas, endpoints,
    or business logic.
  - Operator docs at `docs/features/mcp-tools.md`; unit + integration test coverage
    under `tests/Unit/Mcp/` and `tests/Integration/Mcp/`.
