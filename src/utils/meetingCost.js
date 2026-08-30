/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pure meeting cost math (meeting-efficiency / cost calculator).
 *
 * Mirrors the server-side formula in lib/Service/MeetingCostService.php —
 * the live panel computes for display only; the persisted meetingCost is
 * stamped server-side on close so analytics can trust it.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */

/**
 * Running meeting cost: elapsed hours x attendee count x hourly rate.
 *
 * Spec worked example: 45 minutes x 12 attendees x EUR 75 = EUR 675.
 * Invalid/negative inputs yield 0 so the panel never renders NaN.
 *
 * @param {number} elapsedSeconds Seconds the meeting has been running.
 * @param {number} attendeeCount Number of attendees.
 * @param {number} hourlyRate Hourly rate in EUR per attendee.
 *
 * @return {number} Cost in EUR (unrounded).
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function computeMeetingCost(elapsedSeconds, attendeeCount, hourlyRate) {
	const seconds = Number.isFinite(elapsedSeconds) ? Math.max(0, elapsedSeconds) : 0
	const attendees = Number.isFinite(attendeeCount) ? Math.max(0, attendeeCount) : 0
	const rate = Number.isFinite(hourlyRate) ? Math.max(0, hourlyRate) : 0
	return (seconds / 3600) * attendees * rate
}

/**
 * Cost of a single agenda item from its recorded actual minutes.
 *
 * @param {number} actualMinutes Recorded actualDuration (minutes).
 * @param {number} attendeeCount Number of attendees.
 * @param {number} hourlyRate Hourly rate in EUR per attendee.
 *
 * @return {number} Cost in EUR (unrounded).
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function agendaItemCost(actualMinutes, attendeeCount, hourlyRate) {
	const minutes = Number.isFinite(actualMinutes) ? Math.max(0, actualMinutes) : 0
	return computeMeetingCost(minutes * 60, attendeeCount, hourlyRate)
}

/**
 * Format an EUR amount: integers without decimals ("EUR 675"), fractional
 * amounts with two ("EUR 675.50"). Deterministic (no locale dependency) so
 * the value is stable in tests and across user locales.
 *
 * @param {number} amount Amount in EUR.
 *
 * @return {string} Formatted amount.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function formatEur(amount) {
	const safe = Number.isFinite(amount) ? amount : 0
	const rounded = Math.round(safe * 100) / 100
	const text = Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(2)
	return `EUR ${text}`
}
