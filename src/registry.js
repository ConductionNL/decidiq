/**
 * Decidesk v2 component registry (ADR-036).
 *
 * Kind-tagged map passed as the `registry` prop to CnAppRoot. CnPageRenderer
 * resolves each manifest page's `component` string against entries whose
 * `kind === "page"` (with precedence over the deprecated `customComponents`
 * prop, which decidesk no longer ships).
 *
 * Each entry that is a bespoke page or detail-tab component is wrapped with
 * `page()` so CnPageRenderer can distinguish it from future `"widget"`,
 * `"modal"`, `"form-field"` or `"cell-renderer"` entries that share the
 * same registry map.
 *
 * Genuine exceptions kept here:
 *   - `LiveMeetingView` — realtime WebSocket shell; no abstract manifest
 *     primitive exists yet (documented as the canonical example for a
 *     future `type: "realtime"` lib extension).
 *   - Detail-tab components — one per cross-schema relation; resolution
 *     happens inside each component rather than the renderer, per the
 *     manifest-abstract-sidebar contract.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import LiveMeetingView from './views/LiveMeeting.vue'
import DecisionIntegrations from './views/DecisionIntegrations.vue'
import AgendaItemIntegrations from './views/AgendaItemIntegrations.vue'

// Board portal Phase 8 — boards / board meetings / resolutions
// (openspec/changes/board-meeting-resolutions/tasks.md sections 4 + 8).
import BoardList from './views/BoardList.vue'
import BoardDetail from './views/BoardDetail.vue'
import BoardMeetingList from './views/BoardMeetingList.vue'
import BoardMeetingDetail from './views/BoardMeetingDetail.vue'
import ResolutionList from './views/ResolutionList.vue'
import ResolutionDetail from './views/ResolutionDetail.vue'

// Dashboard v2 widgets (decidesk-dashboard-v2-widgets). Eleven bespoke
// CnDashboardPage slot components registered under kind: "widget". They are
// NOT yet referenced from src/manifest.json — the follow-up config change
// decidesk-dashboard-v2-layout inserts the widgets/layout/dataSources.
import PendingVotesKpiWidget from './views/dashboard/widgets/PendingVotesKpiWidget.vue'
import UpcomingMeetingsKpiWidget from './views/dashboard/widgets/UpcomingMeetingsKpiWidget.vue'
import OverdueActionsKpiWidget from './views/dashboard/widgets/OverdueActionsKpiWidget.vue'
import ActiveDecisionsKpiWidget from './views/dashboard/widgets/ActiveDecisionsKpiWidget.vue'
import UpcomingMeetingsListWidget from './views/dashboard/widgets/UpcomingMeetingsListWidget.vue'
import PendingVotesListWidget from './views/dashboard/widgets/PendingVotesListWidget.vue'
import RunningProcessesWidget from './views/dashboard/widgets/RunningProcessesWidget.vue'
import MyActionItemsWidget from './views/dashboard/widgets/MyActionItemsWidget.vue'
import RecentDecisionsWidget from './views/dashboard/widgets/RecentDecisionsWidget.vue'
import GovernanceHealthWidget from './views/dashboard/widgets/GovernanceHealthWidget.vue'
import DashboardEmptyState from './views/dashboard/widgets/DashboardEmptyState.vue'

import GovernanceBodyMembersTab from './components/tabs/GovernanceBodyMembersTab.vue'
import GovernanceBodyTemplateTab from './components/tabs/GovernanceBodyTemplateTab.vue'
import MeetingAgendaTab from './components/tabs/MeetingAgendaTab.vue'
import MeetingParticipantsTab from './components/tabs/MeetingParticipantsTab.vue'
import MeetingMinutesTab from './components/tabs/MeetingMinutesTab.vue'
import MeetingDecisionsTab from './components/tabs/MeetingDecisionsTab.vue'
import MeetingVotesTab from './components/tabs/MeetingVotesTab.vue'
import AgendaMotionsTab from './components/tabs/AgendaMotionsTab.vue'
import MotionAmendmentsTab from './components/tabs/MotionAmendmentsTab.vue'
import MotionVotesTab from './components/tabs/MotionVotesTab.vue'
import MotionVotingRoundTab from './components/tabs/MotionVotingRoundTab.vue'
import AmendmentParentMotionTab from './components/tabs/AmendmentParentMotionTab.vue'
import MinutesSignersTab from './components/tabs/MinutesSignersTab.vue'
import MinutesApprovalTab from './components/tabs/MinutesApprovalTab.vue'
import MinutesDocumentTab from './components/tabs/MinutesDocumentTab.vue'
import DecisionActionItemsTab from './components/tabs/DecisionActionItemsTab.vue'
import DecisionLifecycleTab from './components/tabs/DecisionLifecycleTab.vue'
import DecisionVotingTab from './components/tabs/DecisionVotingTab.vue'

// User settings (user-settings-v1): in-app mount of the personal settings
// sections (notification / display / delegation / communication). The
// canonical mount is the Nextcloud personal settings panel (ISettings).
import UserSettingsPage from './views/settings/UserSettingsPage.vue'

/**
 * Wrap a Vue component into the v2 registry shape required by CnAppRoot's
 * `registry` prop (`kind: "page"` is the discriminator CnPageRenderer keys
 * page dispatch off — `kind: "widget"`/`"modal"`/`"form-field"`/
 * `"cell-renderer"` entries with the same name are NOT used for page
 * dispatch).
 *
 * @param {object} component Vue component options.
 *
 * @return {object} A `{ kind: "page", component }` registry entry.
 */
function page(component) {
	return { kind: 'page', component }
}

/**
 * Wrap a Vue component into a v2 `kind: "widget"` registry entry.
 *
 * CnAppRoot validates every `widget` entry at mount: an unknown `kind`
 * throws, but a known kind with a missing metadata field only `console.warn`s
 * (see CnAppRoot `_validateRegistry` / `REGISTRY_KIND_REQUIRED_FIELDS.widget`).
 * The five required fields are therefore always supplied:
 *   - `defaultSize` / `minSize` / `maxSize` — `{ w, h }` grid spans the
 *     dashboard layout engine clamps the widget to.
 *   - `allowedSlots` — page-slot regions the widget may be placed in.
 *   - `propsSchema` — JSON-schema-ish description of the widget's
 *     manifest-tunable props (empty `{}` when the widget self-fetches and
 *     takes no manifest props).
 *
 * @param {object} component Vue component options.
 * @param {object} [meta] Size / slot / props metadata overrides.
 *
 * @return {object} A `{ kind: "widget", component, ...meta }` registry entry.
 */
function widget(component, meta = {}) {
	return {
		kind: 'widget',
		component,
		defaultSize: meta.defaultSize || { w: 3, h: 2 },
		minSize: meta.minSize || { w: 2, h: 2 },
		maxSize: meta.maxSize || { w: 12, h: 8 },
		allowedSlots: meta.allowedSlots || ['dashboard'],
		propsSchema: meta.propsSchema || {},
	}
}

export default {
	// --- Genuine exception: realtime UI, no abstract analogue. ---
	// Live meeting shell — WebSocket subscriptions, frame-by-frame UI,
	// per-vote-card animations. Documented as the canonical example for
	// a future `type: "realtime"` lib extension.
	LiveMeetingView: page(LiveMeetingView),

	// --- Integration-registry surfaces (ADR-019 / ADR-022). ---
	// Each mounts CnDetailPage in `useRegistry` mode bound to its OR
	// object so every registered integration provider — including the
	// Email leaf (migrate-email-links-to-email-leaf) — surfaces as a
	// tab. Replaces the retired in-app EmailLink linking surface; email
	// linking is now held by the registry, not an {app}_email_links store.
	DecisionIntegrations: page(DecisionIntegrations),
	AgendaItemIntegrations: page(AgendaItemIntegrations),

	// --- Board portal pages (board-meeting-resolutions / Phase 8). ---
	// Boards index + detail, board-meeting index + detail, resolution
	// index + detail. ADR-004 modal isolation: BoardCreateModal and
	// BoardMeetingCreateModal live in src/modals/ and are imported by
	// BoardList.vue / BoardDetail.vue respectively.
	BoardList: page(BoardList),
	BoardDetail: page(BoardDetail),
	BoardMeetingList: page(BoardMeetingList),
	BoardMeetingDetail: page(BoardMeetingDetail),
	ResolutionList: page(ResolutionList),
	ResolutionDetail: page(ResolutionDetail),

	// --- Detail-tab components (one per cross-schema relation). ---
	// Each lives in /components/tabs/. Full-CRUD (or read-only where
	// authoring lives elsewhere — votes are LiveMeeting-only by design).
	// Cross-schema lookups resolve inside each component rather than the
	// renderer, per the manifest-abstract-sidebar contract.
	GovernanceBodyMembersTab: page(GovernanceBodyMembersTab),
	GovernanceBodyTemplateTab: page(GovernanceBodyTemplateTab),
	MeetingAgendaTab: page(MeetingAgendaTab),
	MeetingParticipantsTab: page(MeetingParticipantsTab),
	// Per-meeting authoring/overview tabs (refactor-decidesk-ia-alignment):
	// Minutes + Decisions are create+browse (split with the top-level
	// register index pages); Votes is the read-only post-meeting aggregate.
	MeetingMinutesTab: page(MeetingMinutesTab),
	MeetingDecisionsTab: page(MeetingDecisionsTab),
	MeetingVotesTab: page(MeetingVotesTab),
	AgendaMotionsTab: page(AgendaMotionsTab),
	MotionAmendmentsTab: page(MotionAmendmentsTab),
	MotionVotesTab: page(MotionVotesTab),
	MotionVotingRoundTab: page(MotionVotingRoundTab),
	AmendmentParentMotionTab: page(AmendmentParentMotionTab),
	MinutesSignersTab: page(MinutesSignersTab),
	// Minutes approval workflow + document generation (minutes-ui-v1):
	// lifecycle timeline with guarded submit/approve/reject actions and
	// participant correction suggestions; document generation into the
	// meeting Files folder plus the notarial proof package trigger.
	MinutesApprovalTab: page(MinutesApprovalTab),
	MinutesDocumentTab: page(MinutesDocumentTab),
	DecisionActionItemsTab: page(DecisionActionItemsTab),

	// --- Dashboard v2 widgets (decidesk-dashboard-v2-widgets). ---
	// Eleven CnDashboardPage slot components. CnPageRenderer / CnWidgetGrid
	// resolve these by name once decidesk-dashboard-v2-layout references them
	// from the Dashboard page's `widgets` array. Each self-fetches its data
	// via src/services/dashboardData.js + dashboardRefreshMixin, so propsSchema
	// is empty. KPI cards are small (3×2); list/chart widgets and the empty
	// state span wider.
	PendingVotesKpiWidget: widget(PendingVotesKpiWidget, {
		defaultSize: { w: 3, h: 2 }, minSize: { w: 2, h: 2 }, maxSize: { w: 4, h: 3 }, allowedSlots: ['dashboard', 'kpi'],
	}),
	UpcomingMeetingsKpiWidget: widget(UpcomingMeetingsKpiWidget, {
		defaultSize: { w: 3, h: 2 }, minSize: { w: 2, h: 2 }, maxSize: { w: 4, h: 3 }, allowedSlots: ['dashboard', 'kpi'],
	}),
	OverdueActionsKpiWidget: widget(OverdueActionsKpiWidget, {
		defaultSize: { w: 3, h: 2 }, minSize: { w: 2, h: 2 }, maxSize: { w: 4, h: 3 }, allowedSlots: ['dashboard', 'kpi'],
	}),
	ActiveDecisionsKpiWidget: widget(ActiveDecisionsKpiWidget, {
		defaultSize: { w: 3, h: 2 }, minSize: { w: 2, h: 2 }, maxSize: { w: 4, h: 3 }, allowedSlots: ['dashboard', 'kpi'],
	}),
	UpcomingMeetingsListWidget: widget(UpcomingMeetingsListWidget, {
		defaultSize: { w: 6, h: 4 }, minSize: { w: 4, h: 3 }, maxSize: { w: 12, h: 8 }, allowedSlots: ['dashboard'],
	}),
	PendingVotesListWidget: widget(PendingVotesListWidget, {
		defaultSize: { w: 6, h: 4 }, minSize: { w: 4, h: 3 }, maxSize: { w: 12, h: 8 }, allowedSlots: ['dashboard'],
	}),
	RunningProcessesWidget: widget(RunningProcessesWidget, {
		defaultSize: { w: 6, h: 4 }, minSize: { w: 4, h: 3 }, maxSize: { w: 12, h: 8 }, allowedSlots: ['dashboard'],
	}),
	MyActionItemsWidget: widget(MyActionItemsWidget, {
		defaultSize: { w: 6, h: 4 }, minSize: { w: 4, h: 3 }, maxSize: { w: 12, h: 8 }, allowedSlots: ['dashboard'],
	}),
	RecentDecisionsWidget: widget(RecentDecisionsWidget, {
		defaultSize: { w: 6, h: 4 }, minSize: { w: 4, h: 3 }, maxSize: { w: 12, h: 8 }, allowedSlots: ['dashboard'],
	}),
	GovernanceHealthWidget: widget(GovernanceHealthWidget, {
		defaultSize: { w: 6, h: 4 }, minSize: { w: 4, h: 3 }, maxSize: { w: 12, h: 8 }, allowedSlots: ['dashboard'],
	}),
	DashboardEmptyState: widget(DashboardEmptyState, {
		defaultSize: { w: 12, h: 5 }, minSize: { w: 6, h: 4 }, maxSize: { w: 12, h: 8 }, allowedSlots: ['dashboard', 'full'],
	}),

	// Decision state machine (decision-state-machine-v1): lifecycle
	// timeline + guarded transition buttons, and the read-only
	// decision → motion → voting-round → vote results aggregate.
	DecisionLifecycleTab: page(DecisionLifecycleTab),
	DecisionVotingTab: page(DecisionVotingTab),

	// --- User settings (user-settings-v1). ---
	// Four personal-preference sections; per-user REST endpoints only.
	UserSettingsPage: page(UserSettingsPage),
}
