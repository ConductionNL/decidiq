# Tasks: dashboard-iwidget-v1

## 1. Backend — data resolution service

- [x] 1.1 Add `lib/Service/DashboardWidgetService.php` (SPDX headers, `@spec`
  tags) resolving the current user's pending-votes count and next upcoming
  meeting via OR `ObjectService` (`findAll`/`find`, named args), user-scoped.
- [x] 1.2 Pending votes: open voting-rounds (no `closedAt`) minus rounds the
  user's participant record has voted in; no participant record ⇒ 0.
- [x] 1.3 Next meeting: lifecycle=scheduled, `scheduledDate >= now`, the user
  participates in, soonest first.
- [x] 1.4 Fail-soft on every OR call (`try/catch (\Throwable)` ⇒ empty result).

## 2. Backend — IWidget

- [x] 2.1 Add `lib/Dashboard/DecideskDashboardWidget.php` implementing
  `IWidget`, `IIconWidget`, `IButtonWidget`, `IAPIWidgetV2` (verified method set
  for NC32).
- [x] 2.2 `getId='decidesk'`, English `getTitle`, `getOrder`, icon class +
  `getIconUrl`, `getUrl` + `getWidgetButtons` deep-linking `/apps/decidesk/`.
- [x] 2.3 `getItemsV2($userId, $since, $limit)` builds `WidgetItems` from the
  service (pending-votes item + next-meeting item), with an empty message.

## 3. Registration + i18n

- [x] 3.1 `registerDashboardWidget(DecideskDashboardWidget::class)` and the
  `DashboardWidgetService` DI binding in `lib/AppInfo/Application.php`.
- [x] 3.2 Add the new English widget strings to `l10n/en.json`, `en_US.json`,
  `nl.json` (lossless sorted merge; do not touch de/fr/es/it).

## 4. Tests + spec + gates

- [x] 4.1 `tests/Unit/Service/DashboardWidgetServiceTest.php` — pending-votes
  set-difference, no-participant ⇒ 0, next-meeting soonest-future, fail-soft.
- [x] 4.2 `tests/Unit/Dashboard/DecideskDashboardWidgetTest.php` — id/title/order,
  `getItemsV2` shape, deep-link URL/button, empty/fail-soft items.
- [x] 4.3 Spec delta: MODIFIED "Nextcloud Dashboard Widget Integration" with the
  scenario `@e2e exclude` (NC-chrome).
- [x] 4.4 `php -l` all changed PHP; PHPUnit green via Docker php:8.3-cli.
- [x] 4.5 All 24 hydra gates green; `openspec validate dashboard-iwidget-v1`.
- [x] 4.6 Fixed pre-existing CVE: bumped `twig/twig` v3.27.0 → v3.27.1 in
  composer.lock (CVE-2026-46636/48805/48806/48807/48808 sandbox bypasses).
