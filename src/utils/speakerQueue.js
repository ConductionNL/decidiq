/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pure speaker-queue logic (meeting-efficiency / speaking time management).
 *
 * The queue is an ordered array of immutable entries:
 *   {
 *     participantId: string,   // OR participant UUID
 *     displayName:   string,
 *     requestedAt:   number,   // ms epoch of the request-to-speak
 *     startedAt:     number|null, // ms epoch the floor was given, null = waiting
 *     spokenMs:      number,   // accumulated completed speaking time
 *     speaking:      boolean,  // currently holds the floor
 *   }
 *
 * Every function returns a new array / entry; the current timestamp is an
 * explicit `now` parameter so the math is unit-testable without fake timers.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */

/**
 * Add a participant to the speaker queue (request to speak).
 * Duplicate participant ids are ignored — a participant queues once.
 *
 * @param {Array} queue Current queue.
 * @param {object} participant `{ id, displayName }` of the participant.
 * @param {number} now Current timestamp (ms) — recorded as requestedAt.
 *
 * @return {Array} New queue in order of request.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function addSpeaker(queue, participant, now) {
	if (!participant?.id || queue.some((e) => e.participantId === participant.id)) {
		return queue
	}
	return [
		...queue,
		{
			participantId: participant.id,
			displayName: participant.displayName || participant.id,
			requestedAt: now,
			startedAt: null,
			spokenMs: 0,
			speaking: false,
		},
	]
}

/**
 * Remove a participant from the queue. If they hold the floor, the floor is
 * released (their spoken time is discarded with the entry — recording the
 * speech is the caller's responsibility via stopSpeaker first).
 *
 * @param {Array} queue Current queue.
 * @param {string} participantId Participant UUID to remove.
 *
 * @return {Array} New queue.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function removeSpeaker(queue, participantId) {
	return queue.filter((e) => e.participantId !== participantId)
}

/**
 * Move a waiting speaker up (-1) or down (+1) in the queue (chair reorder).
 * Out-of-range moves and unknown ids are no-ops.
 *
 * @param {Array} queue Current queue.
 * @param {string} participantId Participant UUID to move.
 * @param {number} direction -1 = up (earlier), +1 = down (later).
 *
 * @return {Array} New queue.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function moveSpeaker(queue, participantId, direction) {
	const index = queue.findIndex((e) => e.participantId === participantId)
	const target = index + Math.sign(direction)
	if (index === -1 || target < 0 || target >= queue.length) {
		return queue
	}
	const next = [...queue]
	const [entry] = next.splice(index, 1)
	next.splice(target, 0, entry)
	return next
}

/**
 * Give the floor to a queued participant. Any current speaker is stopped
 * first (their completed speech duration is reported in the result so the
 * caller can record it via the engagement endpoint).
 *
 * @param {Array} queue Current queue.
 * @param {string} participantId Participant UUID to give the floor to.
 * @param {number} now Current timestamp (ms).
 *
 * @return {{queue: Array, stopped: {participantId: string, durationSeconds: number}|null}}
 *   New queue plus the stopped speaker's recordable speech, if any.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function startSpeaker(queue, participantId, now) {
	const exists = queue.some((e) => e.participantId === participantId)
	if (!exists) {
		return { queue, stopped: null }
	}
	const current = currentSpeaker(queue)
	if (current?.participantId === participantId) {
		return { queue, stopped: null }
	}
	const { queue: afterStop, stopped } = current
		? stopSpeaker(queue, now)
		: { queue, stopped: null }
	const next = afterStop.map((e) => (
		e.participantId === participantId
			? { ...e, speaking: true, startedAt: now }
			: e
	))
	return { queue: next, stopped }
}

/**
 * Stop the current speaker. Their elapsed floor time is folded into
 * spokenMs, and reported so the caller can persist the speech.
 *
 * @param {Array} queue Current queue.
 * @param {number} now Current timestamp (ms).
 *
 * @return {{queue: Array, stopped: {participantId: string, durationSeconds: number}|null}}
 *   New queue plus the stopped speech (null when nobody held the floor).
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function stopSpeaker(queue, now) {
	const current = currentSpeaker(queue)
	if (!current) {
		return { queue, stopped: null }
	}
	const turnMs = current.startedAt !== null ? Math.max(0, now - current.startedAt) : 0
	const next = queue.map((e) => (
		e.participantId === current.participantId
			? { ...e, speaking: false, startedAt: null, spokenMs: e.spokenMs + turnMs }
			: e
	))
	return {
		queue: next,
		stopped: {
			participantId: current.participantId,
			durationSeconds: Math.round(turnMs / 1000),
		},
	}
}

/**
 * The entry currently holding the floor, or null.
 *
 * @param {Array} queue Current queue.
 *
 * @return {object|null} Speaking entry, or null.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function currentSpeaker(queue) {
	return queue.find((e) => e.speaking) ?? null
}

/**
 * Total speaking seconds for an entry: completed turns plus the running one.
 *
 * @param {object} entry Queue entry.
 * @param {number} now Current timestamp (ms).
 *
 * @return {number} Speaking seconds.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function speakerElapsedSeconds(entry, now) {
	if (!entry) {
		return 0
	}
	const runningMs = entry.speaking && entry.startedAt !== null
		? Math.max(0, now - entry.startedAt)
		: 0
	return Math.floor((entry.spokenMs + runningMs) / 1000)
}

/**
 * Whether a speaker exceeded the configured per-speaker limit.
 * No limit (null/0/negative) means never over limit.
 *
 * @param {object} entry Queue entry.
 * @param {number|null} limitSeconds Configured per-speaker limit.
 * @param {number} now Current timestamp (ms).
 *
 * @return {boolean} True when speaking time exceeds the limit.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function isOverLimit(entry, limitSeconds, now) {
	if (!Number.isFinite(limitSeconds) || limitSeconds <= 0) {
		return false
	}
	return speakerElapsedSeconds(entry, now) > limitSeconds
}
