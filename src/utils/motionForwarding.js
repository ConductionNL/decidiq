/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Forward motions admin setting: whether a motion forwarded to another
 * body waits for that body's chair. MotionForwardingService reads it as the
 * string 'true' or '1'.
 */

/**
 * The switch as the admin section shows it.
 *
 * @param {object} settings The GET /api/settings answer.
 * @return {{requiresApproval: boolean}} The state.
 */
export function forwardingSetting(settings) {
	const value = String(settings?.motion_forwarding_requires_approval ?? 'false')
		.trim()
		.toLowerCase()
	return { requiresApproval: ['true', '1'].includes(value) }
}

/**
 * The PUT /api/settings body for the switch.
 *
 * @param {boolean} on Whether a forwarded motion waits for the receiving chair.
 * @return {object} The payload.
 */
export function forwardingPayload(on) {
	return { motion_forwarding_requires_approval: on ? 'true' : 'false' }
}
