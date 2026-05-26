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
// Features & Roadmap page — thin wrapper around the lib's
// CnFeaturesAndRoadmapView (in-product roadmap surface powered by
// OpenRegister's github-issue-proxy). See ConductionNL/hydra#251.
// Stays custom because the v2 type enum has no features-roadmap
// primitive yet.

// NOTE: MeetingIntegrations was eliminated in #237 — the manifest now
// declares the /meetings/:id/integrations route as a typed `detail`
// page with `config.sidebar.useRegistry: true`. The wrapper component
// (src/views/MeetingIntegrations.vue) is retained for reference but no
// longer imported here.

export default {
	// --- Genuine exception: realtime UI, no abstract analogue. ---
	// Live meeting shell — WebSocket subscriptions, frame-by-frame UI,
	// per-vote-card animations. Documented as the canonical example for
	// a future `type: "realtime"` lib extension.
	LiveMeetingView,

	// --- Detail-tab components (one per cross-schema relation). ---
	// Each lives in /components/tabs/. Full-CRUD (or read-only where
	// authoring lives elsewhere — votes are LiveMeeting-only by design).
	// Cross-schema lookups resolve inside each component rather than the
	// renderer, per the manifest-abstract-sidebar contract.
	GovernanceBodyMembersTab,
	MeetingAgendaTab,
	MeetingParticipantsTab,
	AgendaMotionsTab,
	MotionAmendmentsTab,
	MotionVotesTab,
	AmendmentParentMotionTab,
	MinutesSignersTab,
	DecisionActionItemsTab,
	// Features & Roadmap page (lib's CnFeaturesAndRoadmapView).
}
