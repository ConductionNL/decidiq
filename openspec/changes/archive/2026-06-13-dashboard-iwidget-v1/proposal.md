---
kind: code
---

# Proposal: dashboard-iwidget-v1

## Summary

This change implements the one remaining unbuilt requirement of the dashboard
spec — **Nextcloud Dashboard Widget Integration** — by registering a Decidesk
widget on the **Nextcloud main dashboard** (`/apps/dashboard/`) via the
platform `OCP\Dashboard\IWidget` API. The widget shows the current user's
**pending votes count** and their **next upcoming meeting**, and clicking
through navigates to the Decidesk app.

It is the final link that flips `openspec/specs/dashboard/spec.md` from
`status: partial` to `status: done`: the in-app `CnDashboardPage` dashboard
(eleven v2 widgets) was delivered by `decidesk-dashboard-v2-widgets` +
`decidesk-dashboard-v2-layout`; this change adds the platform-level widget the
spec explicitly deferred.

## Motivation

The dashboard spec's "Nextcloud Dashboard Widget Integration" requirement
(REQ) was deferred by both archived v2 chain changes as out of scope. Without
it, Decidesk has no presence on the Nextcloud Hub dashboard — users only see
governance summaries after navigating into the app. A platform IWidget puts the
user's pending votes and next meeting in front of them at login, with a
deep-link back into Decidesk.

## Affected Projects

- [ ] Project: `decidesk` — `lib/Dashboard/DecideskDashboardWidget.php` (NEW
  IWidget), `lib/Service/DashboardWidgetService.php` (NEW per-user data
  resolution over OR ObjectService), `lib/AppInfo/Application.php`
  (`registerDashboardWidget`), `l10n/{en,en_US,nl}.json` (widget strings),
  `openspec/specs/dashboard/spec.md` (MODIFIED Nextcloud Dashboard Widget
  Integration requirement), PHPUnit tests.

## Scope

### In Scope

- A `DecideskDashboardWidget` implementing `OCP\Dashboard\IWidget`,
  `IIconWidget`, `IButtonWidget`, and `IAPIWidgetV2` (the NC32 pure-backend
  data path — no JS bundle required).
- A `DashboardWidgetService` that resolves the **current user's** pending votes
  count (open voting-rounds the user has not voted in) and next upcoming
  meeting (lifecycle=scheduled, soonest future `scheduledDate` the user
  participates in) via OR `ObjectService`, session-scoped (per-user, no IDOR),
  mirroring the existing `VotingDeadlineReminderService` voted-user resolution
  and the v2 widgets' `widgetLogic.js` computations.
- Fail-soft: a broken/absent register MUST return an empty widget, never crash
  the Nextcloud dashboard.
- Registration via `IRegistrationContext::registerDashboardWidget`.
- Deep-link the widget (and its `getUrl()` / "Open Decidesk" button) to
  `/apps/decidesk/`.
- i18n: English source keys in `l10n/en.json`, `en_US.json`, `nl.json` only.

### Out of Scope

- The in-app `CnDashboardPage` dashboard (already built).
- Any new HTTP endpoint (the widget uses the platform `getItemsV2` path; no
  Newman needed).
- A frontend JS widget bundle — the pure-backend `IAPIWidgetV2` path satisfies
  the scenario.

## Risks

- **Guessing the OCP interface method set** — mitigated by reading the installed
  `OCP\Dashboard\*` interfaces in the running NC32 container before coding
  (`getItemsV2(string $userId, ?string $since, int $limit): WidgetItems`,
  `getWidgetButtons(string $userId): array`, `getIconUrl(): string`, plus the
  base `IWidget` six methods).
- **IDOR** — the platform passes the resolved `$userId` to `getItemsV2`; all OR
  reads are scoped to that user's participant record, never an arbitrary id.
- **Crashing the Hub dashboard** — every OR call is wrapped fail-soft.
