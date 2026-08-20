/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pure meeting-efficiency analytics math (meeting-efficiency / dashboard).
 *
 * Every function is side-effect free and takes plain OR-object arrays
 * (meetings, agenda items, engagement records) so the GovernanceBodyEfficiencyTab
 * can compute everything client-side and vitest can exercise it without a DOM,
 * fetch, or fake timers.
 *
 * Date inputs are parsed defensively: an unparseable / missing date yields a
 * null contribution rather than NaN, so partially-populated objects (the common
 * case before the new openedAt/closedAt/meetingCost fields are stamped) never
 * poison an aggregate.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */

import { agendaItemCost } from './meetingCost.js'

/**
 * Parse a value into epoch milliseconds, or null when unparseable.
 *
 * @param {*} value A date string / Date / number.
 *
 * @return {number|null} Epoch ms, or null.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function toMs(value) {
	if (value === null || value === undefined || value === '') {
		return null
	}
	const ms = value instanceof Date ? value.getTime() : Date.parse(value)
	return Number.isFinite(ms) ? ms : null
}

/**
 * Whether an agenda item counts as completed for the completion-rate metric.
 * Completed = status 'completed' (final BOB stage) OR an actualDuration recorded.
 *
 * @param {object} item Agenda-item object.
 *
 * @return {boolean} True when the item is completed.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function isItemCompleted(item) {
	if (!item) {
		return false
	}
	if (item.status === 'completed') {
		return true
	}
	return Number.isFinite(item.actualDuration) && item.actualDuration > 0
}

/**
 * Duration statistics for a body's meetings: actual vs scheduled minutes per
 * meeting (chronological), the average actual duration, and an overrun flag
 * (actual exceeds scheduled) per meeting.
 *
 * actual   = closedAt − openedAt (minutes)
 * scheduled = endDate − scheduledDate (minutes), falling back to the meeting's
 *             plannedDuration / duration field when explicit end is absent.
 *
 * @param {Array} meetings Meeting objects.
 *
 * @return {{
 *   points: Array<{id: string, title: string, date: number|null, actualMinutes: number|null, scheduledMinutes: number|null, overrun: boolean}>,
 *   averageActualMinutes: number,
 *   overrunCount: number,
 *   count: number
 * }}
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function meetingDurationStats(meetings) {
	const list = Array.isArray(meetings) ? meetings : []
	const points = list
		.map((m) => {
			const openedMs = toMs(m.openedAt)
			const closedMs = toMs(m.closedAt)
			const actualMinutes =
				openedMs !== null && closedMs !== null && closedMs >= openedMs
					? Math.round((closedMs - openedMs) / 60000)
					: null

			let scheduledMinutes = null
			const startMs = toMs(m.scheduledDate ?? m.startDate ?? m.startTime)
			const endMs = toMs(m.endDate ?? m.endTime)
			if (startMs !== null && endMs !== null && endMs >= startMs) {
				scheduledMinutes = Math.round((endMs - startMs) / 60000)
			} else if (Number.isFinite(m.plannedDuration) && m.plannedDuration > 0) {
				scheduledMinutes = Math.round(m.plannedDuration)
			} else if (Number.isFinite(m.duration) && m.duration > 0) {
				scheduledMinutes = Math.round(m.duration)
			}

			const overrun =
				actualMinutes !== null
				&& scheduledMinutes !== null
				&& actualMinutes > scheduledMinutes
			return {
				id: m.id ?? m.uuid ?? null,
				title: m.title ?? '',
				date: openedMs ?? startMs,
				actualMinutes,
				scheduledMinutes,
				overrun,
			}
		})
		.sort((a, b) => (a.date ?? 0) - (b.date ?? 0))

	const actuals = points
		.map((p) => p.actualMinutes)
		.filter((v) => Number.isFinite(v))
	const averageActualMinutes =
		actuals.length > 0
			? Math.round(actuals.reduce((sum, v) => sum + v, 0) / actuals.length)
			: 0
	const overrunCount = points.filter((p) => p.overrun).length

	return { points, averageActualMinutes, overrunCount, count: list.length }
}

/**
 * Agenda completion rate (0–1) across the supplied agenda items.
 *
 * @param {Array} items Agenda-item objects.
 *
 * @return {{completed: number, total: number, rate: number}}
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function agendaCompletionRate(items) {
	const list = Array.isArray(items) ? items : []
	const total = list.length
	const completed = list.filter(isItemCompleted).length
	return { completed, total, rate: total > 0 ? completed / total : 0 }
}

/**
 * Speaking-time distribution from EngagementRecords: each participant's share
 * of the total recorded speakingDuration, sorted descending.
 *
 * @param {Array} records EngagementRecord objects ({ participant, speakingDuration }).
 * @param {object} [nameMap] Optional participantId → displayName lookup.
 *
 * @return {{
 *   rows: Array<{participantId: string, displayName: string, seconds: number, share: number}>,
 *   totalSeconds: number
 * }}
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function speakingDistribution(records, nameMap = {}) {
	const list = Array.isArray(records) ? records : []
	const byParticipant = new Map()
	for (const r of list) {
		const id = r.participant ?? r.participantId ?? null
		if (id === null) {
			continue
		}
		const seconds = Number.isFinite(r.speakingDuration)
			? Math.max(0, r.speakingDuration)
			: 0
		byParticipant.set(id, (byParticipant.get(id) ?? 0) + seconds)
	}
	const totalSeconds = [...byParticipant.values()].reduce((sum, v) => sum + v, 0)
	const rows = [...byParticipant.entries()]
		.map(([participantId, seconds]) => ({
			participantId,
			displayName: nameMap[participantId] || participantId,
			seconds,
			share: totalSeconds > 0 ? seconds / totalSeconds : 0,
		}))
		.sort((a, b) => b.seconds - a.seconds)
	return { rows, totalSeconds }
}

/**
 * Cost trend over time from persisted meetingCost values (chronological),
 * with the running total and average.
 *
 * @param {Array} meetings Meeting objects ({ meetingCost, openedAt/closedAt/scheduledDate }).
 *
 * @return {{
 *   points: Array<{id: string, title: string, date: number|null, cost: number}>,
 *   total: number,
 *   average: number
 * }}
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function costTrend(meetings) {
	const list = Array.isArray(meetings) ? meetings : []
	const points = list
		.filter((m) => Number.isFinite(m.meetingCost) && m.meetingCost > 0)
		.map((m) => ({
			id: m.id ?? m.uuid ?? null,
			title: m.title ?? '',
			date: toMs(m.closedAt ?? m.openedAt ?? m.scheduledDate),
			cost: m.meetingCost,
		}))
		.sort((a, b) => (a.date ?? 0) - (b.date ?? 0))
	const total = points.reduce((sum, p) => sum + p.cost, 0)
	const average = points.length > 0 ? total / points.length : 0
	return { points, total, average }
}

/**
 * Per-agenda-item cost breakdown for a single meeting, flagging the most
 * expensive item(s). Cost is derived from each item's recorded actualDuration.
 *
 * @param {Array} items Agenda-item objects for the meeting.
 * @param {number} attendeeCount Number of attendees.
 * @param {number} hourlyRate Hourly rate (EUR per attendee).
 *
 * @return {Array<{id: string, title: string, actualMinutes: number, cost: number, mostExpensive: boolean}>}
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function agendaItemCostBreakdown(items, attendeeCount, hourlyRate) {
	const list = Array.isArray(items) ? items : []
	const rows = list.map((item) => {
		const actualMinutes = Number.isFinite(item.actualDuration)
			? Math.max(0, item.actualDuration)
			: 0
		return {
			id: item.id ?? item.uuid ?? null,
			title: item.title ?? '',
			actualMinutes,
			cost: agendaItemCost(actualMinutes, attendeeCount, hourlyRate),
			mostExpensive: false,
		}
	})
	const maxCost = rows.reduce((max, r) => Math.max(max, r.cost), 0)
	if (maxCost > 0) {
		for (const r of rows) {
			r.mostExpensive = r.cost === maxCost
		}
	}
	return rows.sort((a, b) => b.cost - a.cost)
}

/**
 * Time-allocation accuracy grouped by agenda-item type: average estimated vs
 * average actual minutes per type, with an over/under verdict and a
 * human-readable recommendation string fragment.
 *
 * @param {Array} items Agenda-item objects across multiple meetings.
 *
 * @return {Array<{
 *   itemType: string,
 *   sampleSize: number,
 *   avgEstimated: number,
 *   avgActual: number,
 *   verdict: 'over'|'under'|'accurate',
 *   recommendation: string
 * }>}
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export function timeAllocationAccuracy(items) {
	const list = Array.isArray(items) ? items : []
	const byType = new Map()
	for (const item of list) {
		const estimated = Number.isFinite(item.estimatedDuration)
			? item.estimatedDuration
			: null
		const actual = Number.isFinite(item.actualDuration)
			? item.actualDuration
			: null
		if (estimated === null && actual === null) {
			continue
		}
		const type = item.itemType || 'unknown'
		if (!byType.has(type)) {
			byType.set(type, { estimated: [], actual: [] })
		}
		const bucket = byType.get(type)
		if (estimated !== null) {
			bucket.estimated.push(estimated)
		}
		if (actual !== null) {
			bucket.actual.push(actual)
		}
	}

	const avg = (arr) =>
		arr.length > 0 ? Math.round(arr.reduce((s, v) => s + v, 0) / arr.length) : 0

	return [...byType.entries()].map(([itemType, bucket]) => {
		const avgEstimated = avg(bucket.estimated)
		const avgActual = avg(bucket.actual)
		let verdict = 'accurate'
		if (avgEstimated > 0 && avgActual > avgEstimated * 1.15) {
			verdict = 'over'
		} else if (avgEstimated > 0 && avgActual < avgEstimated * 0.85) {
			verdict = 'under'
		}
		let recommendation = ''
		if (verdict === 'over') {
			recommendation = `${itemType} items average ${avgActual} min actual vs ${avgEstimated} min allocated — consider increasing default allocation`
		} else if (verdict === 'under') {
			recommendation = `${itemType} items average ${avgActual} min actual vs ${avgEstimated} min allocated — consider reducing default allocation`
		}
		return {
			itemType,
			sampleSize: Math.max(bucket.estimated.length, bucket.actual.length),
			avgEstimated,
			avgActual,
			verdict,
			recommendation,
		}
	})
}
