// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The steps of an amendment, for the step bar on the amendment page in the
 * simple structure (openspec/changes/simple-amendment-page).
 *
 * An amendment is a decision of type amendment with a shorter road than a
 * decision: draft, proposed, deliberating, voting, decided. The server holds
 * that road in MotionLifecycleTransitioner::AMENDMENT_TRANSITIONS, and the
 * spec compares this list with it.
 *
 * Pure functions, no Vue and no fetch, so the bar and its spec read one
 * implementation.
 *
 * @spec openspec/changes/simple-amendment-page/specs/amendment-workflow/spec.md#requirement-req-sap-003-a-step-bar-shows-where-the-amendment-stands
 */

/** The five steps, in order. */
export const AMENDMENT_STEPS = [
	'draft',
	'proposed',
	'deliberating',
	'voting',
	'decided',
]

/** The steps the header button takes: the two before the vote. */
export const AMENDMENT_HEADER_STEPS = ['draft', 'proposed']

/** The steps that are taken in the Voting round block. */
export const AMENDMENT_VOTE_STEPS = ['deliberating', 'voting']

/**
 * The five steps, each done, current or upcoming.
 *
 * An amendment without a state, or with one this road does not have, is on
 * no step: all five read as upcoming.
 *
 * @param {string} lifecycle The amendment's state.
 * @return {Array<{state: string, status: string}>} The steps.
 * @spec openspec/changes/simple-amendment-page/specs/amendment-workflow/spec.md#requirement-req-sap-003-a-step-bar-shows-where-the-amendment-stands
 */
export function buildAmendmentSteps(lifecycle) {
	const at = AMENDMENT_STEPS.indexOf(lifecycle)
	return AMENDMENT_STEPS.map((state, index) => {
		let status = 'upcoming'
		if (at >= 0 && index < at) {
			status = 'done'
		} else if (index === at) {
			status = 'current'
		}
		return { state, status }
	})
}

/**
 * Which sentence the bar adds under the steps.
 *
 * `who` for the two steps the header button takes, which the server keeps
 * for the chair and the secretary. `vote` while the next step is the vote,
 * which opens and closes in the Voting round block. `adopted` or `rejected`
 * once the vote is recorded. '' when there is nothing to add.
 *
 * @param {string} lifecycle The amendment's state.
 * @param {string} [outcome] The recorded result of the vote.
 * @return {string} `who`, `vote`, `adopted`, `rejected` or ''.
 * @spec openspec/changes/simple-amendment-page/specs/amendment-workflow/spec.md#requirement-req-sap-004-the-page-says-who-takes-the-next-step-and-where
 */
export function amendmentNote(lifecycle, outcome) {
	if (AMENDMENT_HEADER_STEPS.includes(lifecycle)) {
		return 'who'
	}
	if (AMENDMENT_VOTE_STEPS.includes(lifecycle)) {
		return 'vote'
	}
	if (lifecycle === 'decided' && ['adopted', 'rejected'].includes(outcome)) {
		return outcome
	}
	return ''
}
