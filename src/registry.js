/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: Copyright (C) 2026 Conduction B.V.
 *
 * 5-kind component registry (v2 manifest pattern per hydra ADR-036).
 *
 * Decidesk's manifest declares 2 remaining `type: "custom"` pages after the
 * #237 cleanup (LiveMeeting + FeaturesRoadmap) — both with a `_note`
 * justification in manifest.json. Each is registered here under
 * `kind: 'page'`. Sidebar tab components remain in `customComponents.js`
 * because they are consumed by CnObjectSidebar via the `pages[].config
 * .sidebarTabs[*].component` reference (not the v2 5-kind registry).
 *
 * As the lib gains better primitives (realtime page type, in-product
 * roadmap type), these entries can shrink toward zero.
 */

import LiveMeetingView from './views/LiveMeeting.vue'

export default {
	LiveMeetingView: { kind: 'page', component: LiveMeetingView },
}
