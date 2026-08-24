---
delta: openspec/specs/dashboard/spec.md
---

# Spec Delta: dashboard — dashboard-iwidget-v1

This delta modifies the one remaining unbuilt requirement in
`openspec/specs/dashboard/spec.md`: **Nextcloud Dashboard Widget Integration**.
The in-app `CnDashboardPage` dashboard (REQ-001 through REQ-013) was delivered
by `decidesk-dashboard-v2-widgets` + `decidesk-dashboard-v2-layout` and is not
re-stated here. This change registers the platform `OCP\Dashboard\IWidget` on
the Nextcloud main dashboard. No other requirement is touched.

---

## MODIFIED Requirements

---

### Requirement: Nextcloud Dashboard Widget Integration

The system MUST register a Nextcloud Dashboard widget via `OCP\Dashboard\IWidget`
(implementing `IIconWidget`, `IButtonWidget`, and the NC32 pure-backend
`IAPIWidgetV2` data path) so that Decidesk summary data appears on the Nextcloud
main dashboard. The widget MUST resolve the **current user's** data
(session-scoped, per-user — never an arbitrary object id) via the OpenRegister
`ObjectService`, and MUST fail soft: a broken or absent register MUST NOT crash
the Nextcloud dashboard.

**Feature tier**: MVP

#### Scenario: View Decidesk widget on Nextcloud dashboard

@e2e exclude nc-chrome — the Nextcloud main dashboard is platform chrome owned by the `dashboard` app and the Decidesk widget is server-rendered PHP (`OCP\Dashboard\IWidget`, no Decidesk-owned Vue surface); the widget logic (identity, per-user pending-votes + next-meeting resolution, fail-soft) is covered by PHPUnit in tests/Unit/Dashboard and tests/Unit/Service.

- GIVEN a user with Decidesk access
- WHEN they view the Nextcloud main dashboard
- THEN a "Decidesk" widget MUST be available showing the user's pending votes count and their next upcoming meeting
- AND the pending votes count MUST be the number of open voting-rounds the current user has not yet voted in (a user with no participant record sees 0)
- AND the next meeting MUST be the soonest future `lifecycle=scheduled` meeting the current user participates in (or an empty state when none)
- AND clicking the widget (its url or its "Open Decidesk" button) MUST navigate to the Decidesk app at `/apps/decidesk/`

#### Scenario: Widget fails soft when the register is unavailable

@e2e exclude nc-chrome — backend fail-soft path; covered by tests/Unit/Service/DashboardWidgetServiceTest and tests/Unit/Dashboard/DecideskDashboardWidgetTest.

- GIVEN the OpenRegister `decidesk` register is absent or a schema read throws
- WHEN the Nextcloud dashboard requests the widget items for the current user
- THEN the widget MUST return an empty item set with an empty-content message
- AND it MUST NOT raise an exception to the Nextcloud dashboard
