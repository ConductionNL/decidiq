/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pure helpers for the decision lifecycle state machine UI. Mirrors the
 * server-side transition map in lib/Lifecycle/DecisionTransitionGuard.php —
 * the server is authoritative; these helpers only render state.
 *
 * @spec openspec/specs/decision-management/spec.md
 */

/** Ordered lifecycle states of the decision state machine. */
export const STATES = [
	'draft',
	'proposed',
	'deliberating',
	'voting',
	'decided',
	'enacted',
	'archived',
]

/**
 * English source label of each lifecycle state. The Lifecycle block, the step
 * bar and the status pill of the simple decision page all name a state with
 * these words, so one decision never reads two ways on one page.
 *
 * @spec openspec/changes/simple-decision-page/specs/decision-management/spec.md#requirement-req-sdp-003-a-step-bar-shows-where-the-decision-stands
 */
export const STATE_LABELS = {
	draft: 'Draft',
	proposed: 'Proposed',
	deliberating: 'Deliberating',
	voting: 'Voting',
	// Not 'Decided': that key is a column heading and reads 'Besloten op'.
	decided: 'Decision taken',
	enacted: 'Enacted',
	archived: 'Archived',
	withdrawn: 'Withdrawn',
}

/**
 * CnStatusBadge color map for lifecycle states.
 *
 * @spec openspec/specs/decision-management/spec.md
 */
export const STATE_COLORS = {
	draft: 'default',
	proposed: 'primary',
	deliberating: 'primary',
	voting: 'warning',
	decided: 'success',
	enacted: 'success',
	archived: 'default',
}

/**
 * Build the timeline model for the lifecycle visualization: every state in
 * order, marked done / current / upcoming relative to the current state.
 * Unknown states render everything as upcoming with no current marker.
 *
 * @param {string} current The decision's current lifecycle state
 * @return {Array<{state: string, status: 'done'|'current'|'upcoming'}>}
 * @spec openspec/specs/decision-management/spec.md
 */
export function buildTimeline(current) {
	const idx = STATES.indexOf(current)
	return STATES.map((state, i) => ({
		state,
		status: idx === -1 || i > idx ? 'upcoming' : i === idx ? 'current' : 'done',
	}))
}
