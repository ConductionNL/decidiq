/**
 * Decision lifecycle presentation vocabulary (ADR-005).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ADR-005 folded `Motion` and `Amendment` into `Decision`, and the lifecycle
 * vocabulary moved with them. Three tab components each carried their own copy
 * of a lifecycle → badge-colour map written in the RETIRED Motion vocabulary
 * (`submitted | debating | adopted | rejected`), none of which
 * `Decision.lifecycle` can hold — so every badge fell through to the default
 * colour on every row. Three identical copies is also how that defect got to
 * happen three times, so the map now lives here and is imported.
 *
 * The map is keyed on LIFECYCLE, which is the column these tabs render. It is
 * deliberately NOT keyed on outcome: `adopted`/`rejected` are values of
 * `Decision.outcome`, a separate axis, and a `decided` decision may be either.
 * That is why `decided` is neutral rather than green — colouring it `success`
 * would tell the reader a rejected motion had passed. `enacted` is green
 * because enactment is only reachable from an adopted outcome (the enact gate
 * in DecisionTransitionGuard refuses anything else).
 */

/** Lifecycle state → badge colour, for the status column on decision tabs. */
export const DECISION_LIFECYCLE_COLORS = {
	draft: 'default',
	proposed: 'primary',
	deliberating: 'warning',
	voting: 'warning',
	decided: 'primary',
	enacted: 'success',
	archived: 'default',
	withdrawn: 'default',
}
