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

const EMPTY_DATES = new Set([null, undefined, ''])

/** Advice word per allocation verdict; an accurate verdict gets no advice. */
const ALLOCATION_ADVICE = { over: 'increasing', under: 'reducing' }

/**
 * Parse a value into epoch milliseconds, or null when unparseable.
 *
 * @param {Date|string|number|null|undefined} value A date string / Date / number.
 *
 * @return {number|null} Epoch ms, or null.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function toMs(value) {
	if (EMPTY_DATES.has(value)) {
		return null
	}
	const ms = value instanceof Date ? value.getTime() : Date.parse(value)
	return Number.isFinite(ms) ? ms : null
}

/**
 * The value when it is an array, else an empty list.
 *
 * @param {Array|null|undefined} value The value to read as a list.
 *
 * @return {Array} The list.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function asList(value) {
	return Array.isArray(value) ? value : []
}

/**
 * The sum of a list of numbers.
 *
 * @param {Array<number>} values The numbers.
 *
 * @return {number} The sum, 0 for an empty list.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function sum(values) {
	return values.reduce((total, v) => total + v, 0)
}

/**
 * The rounded average of a list of numbers.
 *
 * @param {Array<number>} values The numbers.
 *
 * @return {number} The average, 0 for an empty list.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function roundedAverage(values) {
	return values.length > 0 ? Math.round(sum(values) / values.length) : 0
}

/**
 * Chronological order of two points; a point without a date sorts as 0.
 *
 * @param {{date: number|null}} a One point.
 * @param {{date: number|null}} b The other point.
 *
 * @return {number} The sort order.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function byDate(a, b) {
	return (a.date ?? 0) - (b.date ?? 0)
}

/**
 * The id and title of a meeting, as a point shows them.
 *
 * @param {object} meeting Meeting object.
 *
 * @return {{id: string|null, title: string}} The id and title.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function identityOf(meeting) {
	return { id: meeting.id ?? meeting.uuid ?? null, title: meeting.title ?? '' }
}

/**
 * Whole minutes from a start to an end, or null when either is missing or
 * the end lies before the start.
 *
 * @param {number|null} startMs Start in epoch ms.
 * @param {number|null} endMs End in epoch ms.
 *
 * @return {number|null} The minutes, or null.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function minutesBetween(startMs, endMs) {
	if (startMs === null || endMs === null || endMs < startMs) {
		return null
	}
	return Math.round((endMs - startMs) / 60000)
}

/**
 * A positive duration rounded to whole minutes, or null.
 *
 * @param {number|string|null|undefined} value A duration in minutes.
 *
 * @return {number|null} The rounded minutes, or null.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function positiveMinutes(value) {
	return Number.isFinite(value) && value > 0 ? Math.round(value) : null
}

/**
 * Scheduled minutes of a meeting: from its start to its end, else its
 * plannedDuration, else its duration.
 *
 * @param {object} meeting Meeting object.
 * @param {number|null} startMs The meeting's scheduled start in epoch ms.
 *
 * @return {number|null} The scheduled minutes, or null.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function scheduledMinutesOf(meeting, startMs) {
	const between = minutesBetween(startMs, toMs(meeting.endDate ?? meeting.endTime))
	if (between !== null) {
		return between
	}
	return (
		positiveMinutes(meeting.plannedDuration) ?? positiveMinutes(meeting.duration)
	)
}

/**
 * Whether the actual minutes exceed the scheduled minutes.
 *
 * @param {number|null} actualMinutes Actual minutes.
 * @param {number|null} scheduledMinutes Scheduled minutes.
 *
 * @return {boolean} True on an overrun.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function isOverrun(actualMinutes, scheduledMinutes) {
	return (
		actualMinutes !== null
		&& scheduledMinutes !== null
		&& actualMinutes > scheduledMinutes
	)
}

/**
 * The duration point of one meeting.
 *
 * @param {object} meeting Meeting object.
 *
 * @return {{id: string|null, title: string, date: number|null, actualMinutes: number|null, scheduledMinutes: number|null, overrun: boolean}} The point.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function durationPoint(meeting) {
	const openedMs = toMs(meeting.openedAt)
	const actualMinutes = minutesBetween(openedMs, toMs(meeting.closedAt))
	const startMs = toMs(
		meeting.scheduledDate ?? meeting.startDate ?? meeting.startTime,
	)
	const scheduledMinutes = scheduledMinutesOf(meeting, startMs)
	return {
		...identityOf(meeting),
		date: openedMs ?? startMs,
		actualMinutes,
		scheduledMinutes,
		overrun: isOverrun(actualMinutes, scheduledMinutes),
	}
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
	const list = asList(meetings)
	const points = list.map((m) => durationPoint(m)).sort(byDate)
	const actuals = points
		.map((p) => p.actualMinutes)
		.filter((v) => Number.isFinite(v))
	const averageActualMinutes = roundedAverage(actuals)
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
 * Recorded speaking seconds per participant; a record without a participant
 * is skipped and a missing or negative duration counts as 0.
 *
 * @param {Array} records EngagementRecord objects.
 *
 * @return {Map<string, number>} Seconds per participant id, in first-seen order.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function secondsByParticipant(records) {
	const totals = new Map()
	for (const r of records) {
		const id = participantOf(r)
		if (id !== null) {
			totals.set(id, (totals.get(id) ?? 0) + speakingSeconds(r))
		}
	}
	return totals
}

/**
 * The participant id of one record, or null.
 *
 * @param {object} record EngagementRecord object.
 *
 * @return {string|null} The participant id.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function participantOf(record) {
	return record.participant ?? record.participantId ?? null
}

/**
 * The speaking seconds of one record: its duration, at least 0.
 *
 * @param {object} record EngagementRecord object.
 *
 * @return {number} The seconds.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function speakingSeconds(record) {
	return Number.isFinite(record.speakingDuration)
		? Math.max(0, record.speakingDuration)
		: 0
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
	const byParticipant = secondsByParticipant(asList(records))
	const totalSeconds = sum([...byParticipant.values()])
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
	const points = asList(meetings)
		.filter((m) => Number.isFinite(m.meetingCost) && m.meetingCost > 0)
		.map((m) => ({
			...identityOf(m),
			date: toMs(m.closedAt ?? m.openedAt ?? m.scheduledDate),
			cost: m.meetingCost,
		}))
		.sort(byDate)
	const total = sum(points.map((p) => p.cost))
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
	return [...durationsByType(asList(items)).entries()].map((entry) =>
		allocationRow(entry),
	)
}

/**
 * A finite number, or null.
 *
 * @param {number|string|null|undefined} value The value to read.
 *
 * @return {number|null} The number, or null.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function finiteOrNull(value) {
	return Number.isFinite(value) ? value : null
}

/**
 * Add a value to a list unless it is null.
 *
 * @param {Array<number>} list The list.
 * @param {number|null} value The value.
 *
 * @return {void}
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function pushPresent(list, value) {
	if (value !== null) {
		list.push(value)
	}
}

/**
 * The duration bucket of one item type, created on first use.
 *
 * @param {Map<string, object>} byType Buckets per item type.
 * @param {string} itemType The item type.
 *
 * @return {{estimated: Array<number>, actual: Array<number>}} The bucket.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function bucketOf(byType, itemType) {
	if (!byType.has(itemType)) {
		byType.set(itemType, { estimated: [], actual: [] })
	}
	return byType.get(itemType)
}

/**
 * Estimated and actual durations per item type; an item with neither is
 * skipped and an item without a type counts as 'unknown'.
 *
 * @param {Array} items Agenda-item objects.
 *
 * @return {Map<string, {estimated: Array<number>, actual: Array<number>}>} Buckets in first-seen order.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function durationsByType(items) {
	const byType = new Map()
	for (const item of items) {
		const estimated = finiteOrNull(item.estimatedDuration)
		const actual = finiteOrNull(item.actualDuration)
		if (estimated !== null || actual !== null) {
			const bucket = bucketOf(byType, item.itemType || 'unknown')
			pushPresent(bucket.estimated, estimated)
			pushPresent(bucket.actual, actual)
		}
	}
	return byType
}

/**
 * Whether the actual time runs over (above 115%) or under (below 85%) the
 * estimate, else accurate. Without an estimate the verdict is accurate.
 *
 * @param {number} avgEstimated Average estimated minutes.
 * @param {number} avgActual Average actual minutes.
 *
 * @return {'over'|'under'|'accurate'} The verdict.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function allocationVerdict(avgEstimated, avgActual) {
	if (avgEstimated > 0 && avgActual > avgEstimated * 1.15) {
		return 'over'
	}
	if (avgEstimated > 0 && avgActual < avgEstimated * 0.85) {
		return 'under'
	}
	return 'accurate'
}

/**
 * The recommendation for an allocation row, empty when it is accurate.
 *
 * @param {{itemType: string, avgEstimated: number, avgActual: number, verdict: string}} row The row.
 *
 * @return {string} The recommendation.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function allocationRecommendation(row) {
	const advice = ALLOCATION_ADVICE[row.verdict]
	if (advice === undefined) {
		return ''
	}
	return `${row.itemType} items average ${row.avgActual} min actual vs ${row.avgEstimated} min allocated — consider ${advice} default allocation`
}

/**
 * The allocation row of one item type.
 *
 * @param {Array} entry The item type and its bucket.
 *
 * @return {{itemType: string, sampleSize: number, avgEstimated: number, avgActual: number, verdict: string, recommendation: string}} The row.
 *
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
function allocationRow(entry) {
	const [itemType, bucket] = entry
	const avgEstimated = roundedAverage(bucket.estimated)
	const avgActual = roundedAverage(bucket.actual)
	const row = {
		itemType,
		sampleSize: Math.max(bucket.estimated.length, bucket.actual.length),
		avgEstimated,
		avgActual,
		verdict: allocationVerdict(avgEstimated, avgActual),
		recommendation: '',
	}
	row.recommendation = allocationRecommendation(row)
	return row
}
