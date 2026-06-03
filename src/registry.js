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

import GovernanceBodyMembersTab from './components/tabs/GovernanceBodyMembersTab.vue'
import MeetingAgendaTab from './components/tabs/MeetingAgendaTab.vue'
import MeetingParticipantsTab from './components/tabs/MeetingParticipantsTab.vue'
import MeetingMinutesTab from './components/tabs/MeetingMinutesTab.vue'
import MeetingDecisionsTab from './components/tabs/MeetingDecisionsTab.vue'
import MeetingVotesTab from './components/tabs/MeetingVotesTab.vue'
import AgendaMotionsTab from './components/tabs/AgendaMotionsTab.vue'
import MotionAmendmentsTab from './components/tabs/MotionAmendmentsTab.vue'
import MotionVotesTab from './components/tabs/MotionVotesTab.vue'
import AmendmentParentMotionTab from './components/tabs/AmendmentParentMotionTab.vue'
import MinutesSignersTab from './components/tabs/MinutesSignersTab.vue'
import DecisionActionItemsTab from './components/tabs/DecisionActionItemsTab.vue'

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

export default {
	// --- Genuine exception: realtime UI, no abstract analogue. ---
	// Live meeting shell — WebSocket subscriptions, frame-by-frame UI,
	// per-vote-card animations. Documented as the canonical example for
	// a future `type: "realtime"` lib extension.
	LiveMeetingView: page(LiveMeetingView),

	// --- Detail-tab components (one per cross-schema relation). ---
	// Each lives in /components/tabs/. Full-CRUD (or read-only where
	// authoring lives elsewhere — votes are LiveMeeting-only by design).
	// Cross-schema lookups resolve inside each component rather than the
	// renderer, per the manifest-abstract-sidebar contract.
	GovernanceBodyMembersTab: page(GovernanceBodyMembersTab),
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
	AmendmentParentMotionTab: page(AmendmentParentMotionTab),
	MinutesSignersTab: page(MinutesSignersTab),
	DecisionActionItemsTab: page(DecisionActionItemsTab),
}
