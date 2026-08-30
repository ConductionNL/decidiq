# Design: dashboard-iwidget-v1

## Context

The dashboard spec's "Nextcloud Dashboard Widget Integration" requirement asks
for a Decidesk widget on the **Nextcloud main dashboard** (the Hub, served by
the `dashboard` app at `/apps/dashboard/`), distinct from the in-app
`CnDashboardPage`. Nextcloud exposes this through the `OCP\Dashboard\IWidget`
family of interfaces; an app registers a PHP widget class via
`IRegistrationContext::registerDashboardWidget(string $widgetClass)`.

## Decisions

### Decision 1 — Interface set: IWidget + IIconWidget + IButtonWidget + IAPIWidgetV2

Verified against the installed NC32 OCP in the running container
(`/var/www/html/lib/public/Dashboard/`):

- `IWidget`: `getId(): string`, `getTitle(): string`, `getOrder(): int`,
  `getIconClass(): string`, `getUrl(): ?string`, `load(): void`.
- `IIconWidget extends IWidget`: `getIconUrl(): string`.
- `IButtonWidget extends IWidget`: `getWidgetButtons(string $userId): array`
  (array of `OCP\Dashboard\Model\WidgetButton`, type constants
  `TYPE_NEW|TYPE_MORE|TYPE_SETUP`).
- `IAPIWidgetV2 extends IWidget`: `getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems`.

`WidgetItems(array $items = [], string $emptyContentMessage = '', string $halfEmptyContentMessage = '')`
holds `WidgetItem(string $title, string $subtitle, string $link, string $iconUrl, string $sinceId, string $overlayIconUrl)`.

We implement **IAPIWidgetV2** (the modern pure-backend data path) so **no JS
bundle is needed** — the Hub renders the items from `getItemsV2`. We add
`IIconWidget` for a crisp icon URL and `IButtonWidget` for an explicit
"Open Decidesk" deep-link button.

### Decision 2 — Per-user data via a thin DashboardWidgetService over OR ObjectService

The widget delegates data resolution to a `DashboardWidgetService` (testable in
isolation, no NC framework). Two computations, both **session/user-scoped**:

- **Pending votes count**: `findAll(register=decidesk, schema=voting-round)`
  filtered to open rounds (no `closedAt`), minus rounds the user's participant
  record has already voted in. The user → participant link is
  `participant.nextcloudUserId == $userId`; a vote belongs to the user when
  `vote.participant == participantId`. A user with **no** participant record is
  not a voting member ⇒ count 0 (matches `widgetLogic.pendingVotingRounds`
  Decision 4 and `VotingDeadlineReminderService.resolveVotedUserIds`).
- **Next meeting**: `findAll(register=decidesk, schema=meeting)` filtered to
  `lifecycle == scheduled` and `scheduledDate >= now`, restricted to meetings
  the user participates in (participant record with matching `nextcloudUserId`
  referencing that meeting), sorted ascending by `scheduledDate`, first row.

This reuses the exact field semantics the v2 widgets and
`VotingDeadlineReminderService` already rely on, so there is one domain truth.

### Decision 3 — Fail-soft, never crash the Hub

Every OR call is wrapped in `try/catch (\Throwable)`. On any failure (OR absent,
register missing, schema drift) the service returns
`['pendingVotes' => 0, 'nextMeeting' => null]` and the widget renders an empty
`WidgetItems` with an "All caught up" empty message. The Hub dashboard never
sees an exception.

### Decision 4 — Deep-link to /apps/decidesk/

`getUrl()` and the `WidgetButton` link both point at
`\OCP\IURLGenerator::linkToRouteAbsolute('decidesk.dashboard.page')` (the app
root, `dashboard#page` at `/`). Individual items also link to the app root —
the pending-votes view and next-meeting detail live inside the SPA, and the app
root lands on the in-app dashboard from which the user reaches them.

### Decision 5 — Browser coverage via @e2e exclude

The NC main dashboard is platform chrome owned by the `dashboard` app; the
Decidesk widget is server-rendered PHP with no Decidesk-owned Vue surface. The
scenario carries a reason-bearing `@e2e exclude` (NC-chrome) and the widget
logic is fully covered by PHPUnit (widget identity, per-user pending-votes +
next-meeting resolution, fail-soft).

## Alternatives Considered

- **IAPIWidget (V1) / JS `registerWidget` bundle** — rejected: V2 is the current
  pure-backend path and avoids shipping a webpack entry for a handful of items.
- **A new HTTP endpoint feeding the widget** — rejected: the platform already
  calls `getItemsV2` server-side; no controller/route/Newman surface is needed.
