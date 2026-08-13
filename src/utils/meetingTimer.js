/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pure agenda-item timer state machine (meeting-efficiency).
 *
 * All functions are side-effect free and take the current timestamp as an
 * explicit `now` parameter (milliseconds since epoch) so the tick math is
 * deterministic and unit-testable without fake timers. Components own the
 * 1-second interval; this module owns every decision.
 *
 * State shape (treat as immutable — every mutator returns a new object):
 *   {
 *     allocatedSeconds: number|null,  // null = no allocation (informational)
 *     startedAt:        number|null,  // ms epoch of start, null = not started
 *     pausedAt:         number|null,  // ms epoch of current pause, null = running
 *     pausedTotalMs:    number,       // accumulated completed pause time
 *     extensionsSeconds: number,      // chair-granted extensions
 *     finished:         boolean,      // closed by the chair
 *   }
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */

/**
 * Create a fresh (not yet started) timer state for an agenda item.
 *
 * @param {number|null} allocatedSeconds Allocated seconds, or null when the
 *   item has no time allocation (informational items — no countdown shown,
 *   but elapsed time is still tracked).
 *
 * @return {object} New timer state.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function createTimer(allocatedSeconds = null) {
	const allocated =
		Number.isFinite(allocatedSeconds) && allocatedSeconds > 0
			? Math.floor(allocatedSeconds)
			: null
	return {
		allocatedSeconds: allocated,
		startedAt: null,
		pausedAt: null,
		pausedTotalMs: 0,
		extensionsSeconds: 0,
		finished: false,
	}
}

/**
 * Start the timer. No-op if already started or finished.
 *
 * @param {object} state Timer state.
 * @param {number} now Current timestamp (ms).
 *
 * @return {object} New timer state.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function startTimer(state, now) {
	if (state.startedAt !== null || state.finished) {
		return state
	}
	return { ...state, startedAt: now }
}

/**
 * Pause a running timer (procedural interruption). The countdown freezes;
 * pause time is accounted separately from speaking/discussion time.
 *
 * @param {object} state Timer state.
 * @param {number} now Current timestamp (ms).
 *
 * @return {object} New timer state.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function pauseTimer(state, now) {
	if (state.startedAt === null || state.pausedAt !== null || state.finished) {
		return state
	}
	return { ...state, pausedAt: now }
}

/**
 * Resume a paused timer, folding the completed pause into pausedTotalMs.
 *
 * @param {object} state Timer state.
 * @param {number} now Current timestamp (ms).
 *
 * @return {object} New timer state.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function resumeTimer(state, now) {
	if (state.pausedAt === null || state.finished) {
		return state
	}
	return {
		...state,
		pausedAt: null,
		pausedTotalMs: state.pausedTotalMs + Math.max(0, now - state.pausedAt),
	}
}

/**
 * Extend the allocation by the given number of seconds (chair action,
 * e.g. "Extend 5 min" / "Extend 10 min" when the timer ran out).
 * No-op on items without an allocation and on finished timers.
 *
 * @param {object} state Timer state.
 * @param {number} seconds Extension in seconds (positive).
 *
 * @return {object} New timer state.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function extendTimer(state, seconds) {
	if (
		state.allocatedSeconds === null
		|| state.finished
		|| !Number.isFinite(seconds)
		|| seconds <= 0
	) {
		return state
	}
	return {
		...state,
		extensionsSeconds: state.extensionsSeconds + Math.floor(seconds),
	}
}

/**
 * Mark the timer finished (chair closes the item).
 * If currently paused, the open pause is folded in first.
 *
 * @param {object} state Timer state.
 * @param {number} now Current timestamp (ms).
 *
 * @return {object} New timer state.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function finishTimer(state, now) {
	if (state.finished) {
		return state
	}
	const resumed = state.pausedAt !== null ? resumeTimer(state, now) : state
	// Freeze the elapsed clock by moving startedAt into a synthetic
	// "stoppedAt" representation: we keep startedAt and record finish via a
	// frozen elapsed snapshot so elapsedSeconds() stays stable after finish.
	return {
		...resumed,
		finished: true,
		finishedElapsedSeconds: elapsedSeconds(resumed, now),
		finishedPausedSeconds: pausedSeconds(resumed, now),
	}
}

/**
 * Active (non-paused) elapsed seconds since start. 0 when not started.
 * Frozen at the finish snapshot once the timer is finished.
 *
 * @param {object} state Timer state.
 * @param {number} now Current timestamp (ms).
 *
 * @return {number} Elapsed seconds, excluding paused time.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function elapsedSeconds(state, now) {
	if (state.finished) {
		return state.finishedElapsedSeconds ?? 0
	}
	if (state.startedAt === null) {
		return 0
	}
	const reference = state.pausedAt !== null ? state.pausedAt : now
	const grossMs = Math.max(0, reference - state.startedAt)
	return Math.floor(Math.max(0, grossMs - state.pausedTotalMs) / 1000)
}

/**
 * Total paused seconds (completed pauses + the currently open one).
 * Frozen at the finish snapshot once the timer is finished.
 *
 * @param {object} state Timer state.
 * @param {number} now Current timestamp (ms).
 *
 * @return {number} Paused seconds.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function pausedSeconds(state, now) {
	if (state.finished) {
		return state.finishedPausedSeconds ?? 0
	}
	const openPauseMs =
		state.pausedAt !== null ? Math.max(0, now - state.pausedAt) : 0
	return Math.floor((state.pausedTotalMs + openPauseMs) / 1000)
}

/**
 * Remaining seconds against the (possibly extended) allocation.
 * Negative when over time. Null when the item has no allocation.
 *
 * @param {object} state Timer state.
 * @param {number} now Current timestamp (ms).
 *
 * @return {number|null} Remaining seconds (may be negative), or null.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function remainingSeconds(state, now) {
	if (state.allocatedSeconds === null) {
		return null
	}
	return (
		state.allocatedSeconds + state.extensionsSeconds - elapsedSeconds(state, now)
	)
}

/**
 * Whether the timer has exceeded its (extended) allocation.
 * Always false for items without an allocation.
 *
 * @param {object} state Timer state.
 * @param {number} now Current timestamp (ms).
 *
 * @return {boolean} True when over time.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function isOverTime(state, now) {
	const remaining = remainingSeconds(state, now)
	return remaining !== null && remaining < 0
}

/**
 * Format a (possibly negative) second count as a clock string.
 * 75 -> "1:15", 3675 -> "1:01:15", -90 -> "-1:30".
 *
 * @param {number} seconds Seconds (may be negative).
 *
 * @return {string} Clock string.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function formatClock(seconds) {
	const safe = Number.isFinite(seconds) ? Math.trunc(seconds) : 0
	const sign = safe < 0 ? '-' : ''
	const abs = Math.abs(safe)
	const h = Math.floor(abs / 3600)
	const m = Math.floor((abs % 3600) / 60)
	const s = abs % 60
	const two = (n) => String(n).padStart(2, '0')
	if (h > 0) {
		return `${sign}${h}:${two(m)}:${two(s)}`
	}
	return `${sign}${m}:${two(s)}`
}
