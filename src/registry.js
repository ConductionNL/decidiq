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

// Dashboard v2 widgets (decidesk-dashboard-v2-widgets). Eleven bespoke
// CnDashboardPage slot components registered under kind: "widget". They are
// NOT yet referenced from src/manifest.json — the follow-up config change
// decidesk-dashboard-v2-layout inserts the widgets/layout/dataSources.
import CreateMeetingAction from './views/dashboard/widgets/CreateMeetingAction.vue'
import StartProcessAction from './views/dashboard/widgets/StartProcessAction.vue'
import DashboardQuickActions from './views/dashboard/widgets/DashboardQuickActions.vue'
import PendingVotesKpiWidget from './views/dashboard/widgets/PendingVotesKpiWidget.vue'
import PendingVotesListWidget from './views/dashboard/widgets/PendingVotesListWidget.vue'
import RunningProcessesWidget from './views/dashboard/widgets/RunningProcessesWidget.vue'
import MyActionItemsWidget from './views/dashboard/widgets/MyActionItemsWidget.vue'
import DashboardEmptyState from './views/dashboard/widgets/DashboardEmptyState.vue'

import ConsultationReactionsTab from './components/tabs/ConsultationReactionsTab.vue'
import GovernanceBodyMembersTab from './components/tabs/GovernanceBodyMembersTab.vue'
import GovernanceBodyTemplateTab from './components/tabs/GovernanceBodyTemplateTab.vue'
import GovernanceBodyEfficiencyTab from './components/tabs/GovernanceBodyEfficiencyTab.vue'
import MeetingAgendaTab from './components/tabs/MeetingAgendaTab.vue'
import MeetingParticipantsTab from './components/tabs/MeetingParticipantsTab.vue'
import MeetingSeriesTab from './components/tabs/MeetingSeriesTab.vue'
import MeetingMinutesTab from './components/tabs/MeetingMinutesTab.vue'
import MeetingTranscriptionTab from './components/tabs/MeetingTranscriptionTab.vue'
import GovernanceBodyRetentionTab from './components/tabs/GovernanceBodyRetentionTab.vue'
import MeetingDecisionsTab from './components/tabs/MeetingDecisionsTab.vue'
import MeetingVotesTab from './components/tabs/MeetingVotesTab.vue'
import AgendaMotionsTab from './components/tabs/AgendaMotionsTab.vue'
import MotionAmendmentsTab from './components/tabs/MotionAmendmentsTab.vue'
import MotionAmendmentOrderTab from './components/tabs/MotionAmendmentOrderTab.vue'
import MotionVotesTab from './components/tabs/MotionVotesTab.vue'
import MotionVotingRoundTab from './components/tabs/MotionVotingRoundTab.vue'
import AmendmentParentMotionTab from './components/tabs/AmendmentParentMotionTab.vue'
import AmendmentDiffTab from './components/tabs/AmendmentDiffTab.vue'
import MinutesSignersTab from './components/tabs/MinutesSignersTab.vue'
import MinutesApprovalTab from './components/tabs/MinutesApprovalTab.vue'
import MinutesDocumentTab from './components/tabs/MinutesDocumentTab.vue'
import DecisionActionItemsTab from './components/tabs/DecisionActionItemsTab.vue'
import ActionItemsSurface from './components/tabs/ActionItemsSurface.vue'
import DecisionLifecycleTab from './components/tabs/DecisionLifecycleTab.vue'
import DecisionRouteTab from './components/tabs/DecisionRouteTab.vue'
import DecisionVotingTab from './components/tabs/DecisionVotingTab.vue'
import RelatedDecisionsTab from './components/tabs/RelatedDecisionsTab.vue'

// Public-publication tabs (publish-decisions-via-opencatalogi): publish /
// withdraw / rectify actions on the decision, meeting (agenda), and minutes
// detail views. Three thin wrappers around the shared PublicationActionsTab.
import DecisionPublicationTab from './components/tabs/DecisionPublicationTab.vue'
import AgendaPublicationTab from './components/tabs/AgendaPublicationTab.vue'
import MinutesPublicationTab from './components/tabs/MinutesPublicationTab.vue'

// User settings (user-settings-v1): in-app mount of the personal settings
// sections (notification / display / delegation / communication). The
// canonical mount is the Nextcloud personal settings panel (ISettings).
import UserSettingsPage from './views/settings/UserSettingsPage.vue'

// Citizen-participation pages (citizen-participation). The consultation/budget
// list+detail pages are auto-rendered by CnPageRenderer from the manifest
// schema config; these two are bespoke action surfaces (citizen + staff
// participation, and the staff moderation queue).
import ParticipationPage from './views/participation/ParticipationPage.vue'
import ModerationQueuePage from './views/participation/ModerationQueuePage.vue'

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

	// --- Detail-tab components (one per cross-schema relation). ---
	// Each lives in /components/tabs/. Full-CRUD (or read-only where
	// authoring lives elsewhere — votes are LiveMeeting-only by design).
	// Cross-schema lookups resolve inside each component rather than the
	// renderer, per the manifest-abstract-sidebar contract.
	ConsultationReactionsTab: page(ConsultationReactionsTab),
	GovernanceBodyMembersTab: page(GovernanceBodyMembersTab),
	GovernanceBodyTemplateTab: page(GovernanceBodyTemplateTab),
	// Meeting-efficiency analytics tab (meeting-efficiency): per-body duration
	// trend, agenda completion, speaking distribution, cost trend and time
	// allocation accuracy, all computed client-side from OR objects.
	GovernanceBodyEfficiencyTab: page(GovernanceBodyEfficiencyTab),
	MeetingAgendaTab: page(MeetingAgendaTab),
	MeetingParticipantsTab: page(MeetingParticipantsTab),
	// Recurring-series generation (meeting-agenda-gaps-v1): pattern form,
	// preview count, generate action, and the series instance list.
	MeetingSeriesTab: page(MeetingSeriesTab),
	// Per-meeting authoring/overview tabs (refactor-decidesk-ia-alignment):
	// Minutes + Decisions are create+browse (split with the top-level
	// register index pages); Votes is the read-only post-meeting aggregate.
	MeetingMinutesTab: page(MeetingMinutesTab),
	MeetingTranscriptionTab: page(MeetingTranscriptionTab),
	GovernanceBodyRetentionTab: page(GovernanceBodyRetentionTab),
	MeetingDecisionsTab: page(MeetingDecisionsTab),
	MeetingVotesTab: page(MeetingVotesTab),
	AgendaMotionsTab: page(AgendaMotionsTab),
	MotionAmendmentsTab: page(MotionAmendmentsTab),
	// Chair-controlled amendment voting order (motion-amendment spec).
	MotionAmendmentOrderTab: page(MotionAmendmentOrderTab),
	MotionVotesTab: page(MotionVotesTab),
	MotionVotingRoundTab: page(MotionVotingRoundTab),
	AmendmentParentMotionTab: page(AmendmentParentMotionTab),
	// Visual diff against the parent motion text (motion-amendment spec).
	AmendmentDiffTab: page(AmendmentDiffTab),
	MinutesSignersTab: page(MinutesSignersTab),
	// Minutes approval workflow + document generation (minutes-ui-v1):
	// lifecycle timeline with guarded submit/approve/reject actions and
	// participant correction suggestions; document generation into the
	// meeting Files folder plus the notarial proof package trigger.
	MinutesApprovalTab: page(MinutesApprovalTab),
	MinutesDocumentTab: page(MinutesDocumentTab),
	DecisionActionItemsTab: page(DecisionActionItemsTab),
	// action-item-deck-board: surface switch — Deck-board projection (real
	// Nextcloud Deck cards via the OR leaf) when Deck is installed, else the
	// table tab. The manifest decision action-items tab points here.
	ActionItemsSurface: page(ActionItemsSurface),

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
	PendingVotesListWidget: widget(PendingVotesListWidget, {
		defaultSize: { w: 6, h: 4 }, minSize: { w: 4, h: 3 }, maxSize: { w: 12, h: 8 }, allowedSlots: ['dashboard'],
	}),
	RunningProcessesWidget: widget(RunningProcessesWidget, {
		defaultSize: { w: 6, h: 4 }, minSize: { w: 4, h: 3 }, maxSize: { w: 12, h: 8 }, allowedSlots: ['dashboard'],
	}),
	MyActionItemsWidget: widget(MyActionItemsWidget, {
		defaultSize: { w: 6, h: 4 }, minSize: { w: 4, h: 3 }, maxSize: { w: 12, h: 8 }, allowedSlots: ['dashboard'],
	}),
	DashboardEmptyState: widget(DashboardEmptyState, {
		defaultSize: { w: 12, h: 5 }, minSize: { w: 6, h: 4 }, maxSize: { w: 12, h: 8 }, allowedSlots: ['dashboard', 'full'],
	}),
	CreateMeetingAction: widget(CreateMeetingAction, {
		defaultSize: { w: 1, h: 1 }, minSize: { w: 1, h: 1 }, maxSize: { w: 2, h: 1 }, allowedSlots: ['dashboard'],
	}),
	StartProcessAction: widget(StartProcessAction, {
		defaultSize: { w: 1, h: 1 }, minSize: { w: 1, h: 1 }, maxSize: { w: 2, h: 1 }, allowedSlots: ['dashboard'],
	}),
	DashboardQuickActions: widget(DashboardQuickActions, {
		defaultSize: { w: 12, h: 1 }, minSize: { w: 3, h: 1 }, maxSize: { w: 12, h: 1 }, allowedSlots: ['dashboard'],
	}),

	// Decision state machine (decision-state-machine-v1): lifecycle
	// timeline + guarded transition buttons, and the read-only
	// decision → motion → voting-round → vote results aggregate.
	DecisionLifecycleTab: page(DecisionLifecycleTab),
	DecisionRouteTab: page(DecisionRouteTab),
	DecisionVotingTab: page(DecisionVotingTab),
	RelatedDecisionsTab: page(RelatedDecisionsTab),

	// Public-publication action tabs (publish-decisions-via-opencatalogi).
	DecisionPublicationTab: page(DecisionPublicationTab),
	AgendaPublicationTab: page(AgendaPublicationTab),
	MinutesPublicationTab: page(MinutesPublicationTab),

	// --- User settings (user-settings-v1). ---
	// Four personal-preference sections; per-user REST endpoints only.
	UserSettingsPage: page(UserSettingsPage),

	// --- Citizen participation (citizen-participation). ---
	// Bespoke action surfaces: the citizen+staff participation view (reaction
	// form, proposal form, advisory voting cards, staff lifecycle/publish) and
	// the staff reaction moderation queue.
	ParticipationPage: page(ParticipationPage),
	ModerationQueuePage: page(ModerationQueuePage),
}
