// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Custom-component registry for decidesk's manifest-driven app shell.
//
// Every entry here is the "escape hatch" — pages or sidebar tabs that
// don't fit one of the manifest's built-in types/widgets. Keep this
// file SHORT. Adding entries should require explicit justification in
// the design doc; deleting them is the right direction.
//
// Resolution order at runtime:
//   1. Built-in page types          (CnIndexPage, CnDetailPage, …)
//   2. Built-in widget types        (version-info, register-mapping, …)
//   3. customComponents (this file) ← consumer-injected components
//
// See:
//   - openspec/changes/decidesk-manifest-v1/design.md
//   - @conduction/nextcloud-vue → docs/migrating-to-manifest.md

import LiveMeetingView from './views/LiveMeeting.vue'

// Detail-tab custom components — sidebar tabs the manifest references by
// name from `pages[].config.sidebarTabs[*].component`. Each is currently
// a stub (renders a CnNoteCard placeholder) per the cleanup-commit
// design; full implementations will follow once the lib release lands
// and we can run end-to-end against real schemas.
import GovernanceBodyMembersTab from './components/tabs/GovernanceBodyMembersTab.vue'
import MeetingAgendaTab from './components/tabs/MeetingAgendaTab.vue'
import MeetingParticipantsTab from './components/tabs/MeetingParticipantsTab.vue'
import AgendaMotionsTab from './components/tabs/AgendaMotionsTab.vue'
import MotionAmendmentsTab from './components/tabs/MotionAmendmentsTab.vue'
import MotionVotesTab from './components/tabs/MotionVotesTab.vue'
import AmendmentParentMotionTab from './components/tabs/AmendmentParentMotionTab.vue'
import MinutesSignersTab from './components/tabs/MinutesSignersTab.vue'
import DecisionActionItemsTab from './components/tabs/DecisionActionItemsTab.vue'

export default {
	// --- Genuine exception: realtime UI, no abstract analogue. ---
	// Live meeting shell — WebSocket subscriptions, frame-by-frame UI,
	// per-vote-card animations. Documented as the canonical example for
	// a future `type: "realtime"` lib extension.
	LiveMeetingView,

	// --- Detail-tab components (one per cross-schema relation). ---
	// Each lives in /components/tabs/. Stubs today; flesh out under
	// individual follow-up tickets (see TODO comments inside each file).
	GovernanceBodyMembersTab,
	MeetingAgendaTab,
	MeetingParticipantsTab,
	AgendaMotionsTab,
	MotionAmendmentsTab,
	MotionVotesTab,
	AmendmentParentMotionTab,
	MinutesSignersTab,
	DecisionActionItemsTab,
}
