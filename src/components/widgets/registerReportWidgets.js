// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Registers decidiq's report dashboard widgets into the shared
// dashboardWidgetRegistry. Kept apart from registerDetailWidgets.js: those are
// detail-page widgets, this one sits on a report dashboard. Dynamic imports,
// for the same reason as there: Vitest has no SFC transform.
//
// @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-005-aggregate-accessibility-report-per-body-and-period

/**
 * Register the report widgets. Resolve before mount: the dashboard looks a
 * widget type up synchronously when it renders.
 *
 * @return {Promise<void>}
 */
export async function registerReportWidgets() {
	const [
		{ registerDashboardWidget },
		{ default: AccessibilityReportExportWidget },
	] = await Promise.all([
		import('@conduction/nextcloud-vue'),
		import('./AccessibilityReportExportWidget.vue'),
	])

	registerDashboardWidget('accessibility-report-export', {
		renderer: AccessibilityReportExportWidget,
		form: null,
		defaultContent: {},
		displayName: 'Accessibility report export',
		icon: 'FileDelimitedOutline',
		surfaces: ['app-dashboard'],
	})
}
