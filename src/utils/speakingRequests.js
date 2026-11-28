/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pure speaking-request logic for the agenda speakers block, the speaker
 * queue bridge and the secretariat overview (inspreekrecht-plenair).
 *
 * The queue mechanics stay in src/utils/speakerQueue.js (REQ-STM): this module
 * only turns approved speaking requests into entries of that queue, places
 * them ahead of the chair's own entries, and computes the registration
 * deadline state. The current time is an explicit `now` so it is testable
 * without fake timers.
 */

/**
 * The place key the server uses for requests on the meeting as a whole.
 */
export const MEETING_PLACE = 'meeting'

/**
 * The participant-id prefix of a queue entry that stands for a speaking
 * request: it can never collide with a participant UUID.
 */
export const PUBLIC_SPEAKER_PREFIX = 'speaking-request:'

/**
 * Hours before the registration deadline at which the overview starts warning.
 */
export const CLOSING_SOON_HOURS = 24

/**
 * The approved slots and pending requests of one place on the agenda.
 *
 * @param {object|Array|null} agenda The server's `{ [place]: { slots, pending } }` (an empty answer may be `[]`).
 * @param {string|null} place An agenda item id, or empty/null for the meeting block.
 * @return {{slots: Array, pending: Array}} The place's slots and pending requests, both possibly empty.
 *
 * @spec openspec/changes/archive/2026-11-24-inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-007-approved-insprekers-render-as-speaking-slots-on-the-agenda
 */
export function slotsFor(agenda, place) {
	const key = place || MEETING_PLACE
	const entry = agenda && !Array.isArray(agenda) && typeof agenda === 'object' ? agenda[key] : null
	return {
		slots: Array.isArray(entry?.slots) ? entry.slots : [],
		pending: Array.isArray(entry?.pending) ? entry.pending : [],
	}
}

/**
 * Turn the server's queue rows into speaker-queue entries, in the server's order.
 * Rows without a request id are dropped; a missing name falls back to a label
 * the caller supplies.
 *
 * @param {Array} rows Rows `{ requestId, name, minutes }` from the speaking-queue endpoint.
 * @param {number} now Current timestamp (ms), recorded as requestedAt.
 * @param {string} [fallbackName] The name of a speaker the request did not name.
 * @return {Array} Queue entries carrying `requestId`, `publicSpeaker: true` and `limitSeconds`.
 *
 * @spec openspec/changes/archive/2026-11-24-inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-009-approved-insprekers-preload-the-speaker-queue-and-status-flows-back
 */
export function toQueueEntries(rows, now, fallbackName = '') {
	if (!Array.isArray(rows)) return []
	return rows
		.filter((row) => row && typeof row.requestId === 'string' && row.requestId !== '')
		.map((row) => {
			const minutes = Number(row.minutes)
			return {
				participantId: PUBLIC_SPEAKER_PREFIX + row.requestId,
				displayName: (typeof row.name === 'string' && row.name.trim() !== '') ? row.name.trim() : fallbackName,
				requestedAt: now,
				startedAt: null,
				spokenMs: 0,
				speaking: false,
				requestId: row.requestId,
				publicSpeaker: true,
				limitSeconds: Number.isFinite(minutes) && minutes > 0 ? minutes * 60 : null,
			}
		})
}

/**
 * Whether a queue entry stands for a speaking request.
 *
 * @param {object|null} entry A queue entry.
 * @return {boolean} True for a preloaded public speaker.
 *
 * @spec openspec/changes/archive/2026-11-24-inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-009-approved-insprekers-preload-the-speaker-queue-and-status-flows-back
 */
export function isPublicSpeaker(entry) {
	return entry?.publicSpeaker === true
		|| (typeof entry?.participantId === 'string' && entry.participantId.startsWith(PUBLIC_SPEAKER_PREFIX))
}

/**
 * Place preloaded public speakers in the queue: after public speakers already
 * there and ahead of the chair's own entries. An entry already queued (same
 * participant id) is kept as it is, so a preload never resets a running clock.
 *
 * @param {Array} queue Current queue.
 * @param {Array} entries Entries from {@link toQueueEntries}.
 * @return {Array} New queue.
 *
 * @spec openspec/changes/archive/2026-11-24-inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-009-approved-insprekers-preload-the-speaker-queue-and-status-flows-back
 */
export function preloadSpeakers(queue, entries) {
	const queued = new Set(queue.map((e) => e.participantId))
	const added = (Array.isArray(entries) ? entries : []).filter((e) => !queued.has(e.participantId))
	if (added.length === 0) return queue
	const publicOnes = queue.filter((e) => isPublicSpeaker(e))
	const others = queue.filter((e) => !isPublicSpeaker(e))
	return [...publicOnes, ...added, ...others]
}

/**
 * The time limit that applies to an entry: its own (a preloaded speaker's
 * allotted minutes) when it has one, else the panel-wide limit.
 *
 * @param {object} entry Queue entry.
 * @param {number|null} panelLimitSeconds The limit the chair set for everyone.
 * @return {number|null} Limit in seconds, or null for none.
 *
 * @spec openspec/changes/archive/2026-11-24-inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-009-approved-insprekers-preload-the-speaker-queue-and-status-flows-back
 */
export function entryLimitSeconds(entry, panelLimitSeconds) {
	if (Number.isFinite(entry?.limitSeconds) && entry.limitSeconds > 0) {
		return entry.limitSeconds
	}
	return panelLimitSeconds
}

/**
 * The registration deadline state of a meeting: `open`, `closing` (within
 * {@link CLOSING_SOON_HOURS} of the deadline), `closed`, or `unknown` when the
 * meeting has no valid start or the policy no valid deadline.
 *
 * @param {string|number|Date|null} meetingStart The meeting's scheduled start.
 * @param {number|string|null} deadlineHours Hours before the start at which registration closes.
 * @param {number} now Current timestamp (ms).
 * @return {{state: string, deadline: number|null}} The state and the deadline timestamp (ms).
 *
 * @spec openspec/changes/archive/2026-11-24-inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-010-griffie-inspraak-overview-with-deadline-warnings-and-notifications
 */
export function deadlineState(meetingStart, deadlineHours, now) {
	const start = meetingStart instanceof Date ? meetingStart.getTime() : Date.parse(meetingStart ?? '')
	const hours = Number(deadlineHours)
	if (!Number.isFinite(start) || deadlineHours === null || deadlineHours === '' || !Number.isFinite(hours) || hours < 0) {
		return { state: 'unknown', deadline: null }
	}
	const deadline = start - hours * 3600 * 1000
	if (now >= deadline) return { state: 'closed', deadline }
	if (deadline - now <= CLOSING_SOON_HOURS * 3600 * 1000) return { state: 'closing', deadline }
	return { state: 'open', deadline }
}
