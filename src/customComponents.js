// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Custom-component registry for the manifest renderer.
 *
 * Every page in `manifest.json` resolves its `component` field
 * against this map. CnPageRenderer dispatches by route name and looks
 * up the named component here.
 *
 * Each entry is wrapped in `defineAsyncComponent` so views are still
 * lazy-loaded into separate chunks (matching the pre-Tier-4 router
 * behaviour, where every `() => import(...)` produced its own chunk).
 *
 * All entries today wrap existing app views (`useListView` /
 * `useDetailView` + slot-overridden dialogs). See
 * https://github.com/ConductionNL/nextcloud-vue/issues/90 for the
 * planned auto-wire that would let these become `type: "index"` /
 * `type: "detail"` directly without per-view registration.
 */

import { defineAsyncComponent } from 'vue'

export default {
	DashboardView: defineAsyncComponent(() => import('./views/Dashboard.vue')),
	GovernanceBodiesView: defineAsyncComponent(() => import('./views/GovernanceBodies.vue')),
	GovernanceBodyDetailView: defineAsyncComponent(() => import('./views/GovernanceBodyDetail.vue')),
	MeetingsView: defineAsyncComponent(() => import('./views/Meetings.vue')),
	MeetingDetailView: defineAsyncComponent(() => import('./views/MeetingDetail.vue')),
	LiveMeetingView: defineAsyncComponent(() => import('./views/LiveMeeting.vue')),
	ParticipantsView: defineAsyncComponent(() => import('./views/Participants.vue')),
	ParticipantDetailView: defineAsyncComponent(() => import('./views/ParticipantDetail.vue')),
	AgendaItemsView: defineAsyncComponent(() => import('./views/AgendaItems.vue')),
	AgendaItemDetailView: defineAsyncComponent(() => import('./views/AgendaItemDetail.vue')),
	MotionsView: defineAsyncComponent(() => import('./views/Motions.vue')),
	MotionDetailView: defineAsyncComponent(() => import('./views/MotionDetail.vue')),
	AmendmentDetailView: defineAsyncComponent(() => import('./views/AmendmentDetail.vue')),
	MinutesView: defineAsyncComponent(() => import('./views/Minutes.vue')),
	MinutesDetailView: defineAsyncComponent(() => import('./views/MinutesDetail.vue')),
	DecisionsView: defineAsyncComponent(() => import('./views/Decisions.vue')),
	DecisionDetailView: defineAsyncComponent(() => import('./views/DecisionDetail.vue')),
	ActionItemsView: defineAsyncComponent(() => import('./views/ActionItems.vue')),
	ActionItemDetailView: defineAsyncComponent(() => import('./views/ActionItemDetail.vue')),
	SettingsView: defineAsyncComponent(() => import('./views/SettingsView.vue')),
}
