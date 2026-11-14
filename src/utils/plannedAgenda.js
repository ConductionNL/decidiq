/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The rules of the long-term agenda (planned-agenda-item), kept free of Vue
 * so the board, the dialogs and the dashboard count share one copy.
 *
 * A planned period is `YYYY-Qn` or `YYYY-MM`. It sorts and expires by its last
 * day: `2026-11` ends on 2026-11-30, `2026-Q4` on 2026-12-31. On a tie the
 * month comes before the quarter that encloses it.
 *
 * Every write here builds the whole object (PUT semantics): the caller hands
 * the item it read and gets back the item to save, with the existing fields
 * and shift-history entries carried forward unchanged.
 *
 * @spec openspec/changes/termijnagenda
 */

export const PLANNED_AGENDA_SCHEMA = 'planned-agenda-item'

export const TERMINAL_STATES = ['realised', 'lapsed']

export const OPEN_STATES = ['planned', 'postponed']

const PERIOD_PATTERN = /^(\d{4})-(?:Q([1-4])|(0[1-9]|1[0-2]))$/

/**
 * Split a period into its year, last month and granularity.
 *
 * @param {string} period A `YYYY-Qn` or `YYYY-MM` string.
 * @return {{year: number, month: number, quarter: boolean}|null} Null when the text is no period.
 *
 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-002-planned-period-at-month-or-quarter-granularity
 */
export function parsePeriod(period) {
	const match = PERIOD_PATTERN.exec(String(period ?? '').trim())
	if (!match) return null
	const year = Number(match[1])
	if (match[2]) {
		return { year, month: Number(match[2]) * 3, quarter: true }
	}
	return { year, month: Number(match[3]), quarter: false }
}

/**
 * Whether the text is a period the schema accepts.
 *
 * @param {string} period The text.
 * @return {boolean} True for `YYYY-Qn` and `YYYY-MM`.
 *
 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-002-planned-period-at-month-or-quarter-granularity
 */
export function isValidPeriod(period) {
	return parsePeriod(period) !== null
}

/**
 * The last day of a period as `YYYY-MM-DD`.
 *
 * @param {string} period A `YYYY-Qn` or `YYYY-MM` string.
 * @return {string|null} The last day, or null when the text is no period.
 *
 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-002-planned-period-at-month-or-quarter-granularity
 */
export function periodLastDay(period) {
	const parsed = parsePeriod(period)
	if (!parsed) return null
	const day = new Date(Date.UTC(parsed.year, parsed.month, 0)).getUTCDate()
	return `${parsed.year}-${String(parsed.month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

/**
 * Compare two periods chronologically: by last day, month before quarter on
 * a tie, text that is no period last.
 *
 * @param {string} a The first period.
 * @param {string} b The second period.
 * @return {number} Negative, zero or positive, as Array.prototype.sort wants.
 *
 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-002-planned-period-at-month-or-quarter-granularity
 */
export function comparePeriods(a, b) {
	const dayA = periodLastDay(a)
	const dayB = periodLastDay(b)
	if (dayA === null || dayB === null) {
		if (dayA === dayB) return String(a ?? '').localeCompare(String(b ?? ''))
		return dayA === null ? 1 : -1
	}
	if (dayA !== dayB) return dayA < dayB ? -1 : 1
	const quarterA = parsePeriod(a).quarter ? 1 : 0
	const quarterB = parsePeriod(b).quarter ? 1 : 0
	return quarterA - quarterB
}

/**
 * Today as `YYYY-MM-DD` in local time.
 *
 * @param {Date|number} now The moment.
 * @return {string} The date.
 */
function localDay(now) {
	const date = new Date(now)
	return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

/**
 * Whether an item is in a final state.
 *
 * @param {object} item The planned-agenda item.
 * @return {boolean} True for realised and lapsed.
 *
 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-003-lifecycle-is-declarative-with-shift-and-settle-states
 */
export function isTerminal(item) {
	return TERMINAL_STATES.includes(item?.lifecycle)
}

/**
 * Whether an item is overdue: its period ended before today, it is not
 * final and nothing links it to a realised agenda item or decision.
 *
 * @param {object} item The planned-agenda item.
 * @param {Date|number} now The moment to judge at.
 * @return {boolean} True when overdue.
 *
 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-002-planned-period-at-month-or-quarter-granularity
 */
export function isOverdue(item, now) {
	if (!item || isTerminal(item)) return false
	if (item.realisedAgendaItem || item.realisedDecision) return false
	const lastDay = periodLastDay(item.plannedPeriod)
	return lastDay !== null && lastDay < localDay(now)
}

/**
 * The overdue items among a list.
 *
 * @param {Array<object>} items Planned-agenda items.
 * @param {Date|number} now The moment to judge at.
 * @return {Array<object>} The overdue ones, in input order.
 *
 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-010-dashboard-kpi-for-overdue-termijnagenda-items
 */
export function overdueItems(items, now) {
	return (Array.isArray(items) ? items : []).filter((item) => isOverdue(item, now))
}

/**
 * The period that follows one, at the same granularity.
 *
 * @param {string} period A `YYYY-Qn` or `YYYY-MM` string.
 * @return {string|null} The next period, or null when the text is no period.
 */
export function nextPeriod(period) {
	const parsed = parsePeriod(period)
	if (!parsed) return null
	if (parsed.quarter) {
		const quarter = parsed.month / 3
		return quarter === 4 ? `${parsed.year + 1}-Q1` : `${parsed.year}-Q${quarter + 1}`
	}
	return parsed.month === 12
		? `${parsed.year + 1}-01`
		: `${parsed.year}-${String(parsed.month + 1).padStart(2, '0')}`
}

/**
 * The board's columns: every distinct period among the items plus the next
 * `extra` empty quarters after the latest, in chronological order. Items
 * whose period is not valid get no column of their own.
 *
 * @param {Array<object>} items Planned-agenda items of one body.
 * @param {number} [extra] How many empty quarters to add after the last column.
 * @param {Date|number} [now] Used for the first quarter when there are no items.
 * @return {Array<string>} The column periods.
 *
 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-005-per-body-board-view-with-drag-to-reschedule
 */
export function boardColumns(items, extra = 2, now = Date.now()) {
	const periods = new Set()
	for (const item of Array.isArray(items) ? items : []) {
		if (isValidPeriod(item?.plannedPeriod)) periods.add(item.plannedPeriod)
	}
	const sorted = [...periods].sort(comparePeriods)
	const date = new Date(now)
	let cursor = sorted.length
		? sorted[sorted.length - 1]
		: `${date.getFullYear()}-Q${Math.floor(date.getMonth() / 3) + 1}`
	if (!sorted.length) sorted.push(cursor)
	const last = parsePeriod(cursor)
	if (!last.quarter) cursor = `${last.year}-Q${Math.ceil(last.month / 3)}`
	for (let i = 0; i < extra; i++) {
		cursor = nextPeriod(cursor)
		if (!periods.has(cursor)) sorted.push(cursor)
	}
	return sorted.sort(comparePeriods)
}

/**
 * Group items into the board's columns.
 *
 * @param {Array<object>} items Planned-agenda items of one body.
 * @param {Array<string>} columns The column periods.
 * @return {Object<string, Array<object>>} Items per column period.
 *
 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-005-per-body-board-view-with-drag-to-reschedule
 */
export function groupByPeriod(items, columns) {
	const groups = Object.fromEntries(columns.map((period) => [period, []]))
	for (const item of Array.isArray(items) ? items : []) {
		if (groups[item?.plannedPeriod]) groups[item.plannedPeriod].push(item)
	}
	return groups
}

/**
 * Build the rescheduled item: lifecycle `postponed`, the new period, and one
 * entry appended to the shift history. Existing entries are copied unchanged.
 *
 * @param {object} item The item as read.
 * @param {object} shift The shift.
 * @param {string} shift.toPeriod The new period.
 * @param {string} shift.reason Why it moves; required.
 * @param {string} shift.user The acting user's id.
 * @param {Date|number} shift.now The moment of the shift.
 * @return {object} The item to save.
 * @throws {Error} When the item is final, the period invalid or unchanged, or the reason empty.
 *
 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-004-every-reschedule-records-a-mandatory-reason-and-preserves-full-shift-history
 */
export function buildReschedule(item, { toPeriod, reason, user, now }) {
	if (isTerminal(item)) throw new Error('A realised or lapsed item cannot be rescheduled.')
	if (!isValidPeriod(toPeriod)) throw new Error('Choose a period such as 2027-Q1 or 2027-03.')
	if (toPeriod === item.plannedPeriod) throw new Error('Choose a different period.')
	const why = String(reason ?? '').trim()
	if (!why) throw new Error('A reason is required.')
	const history = Array.isArray(item.shiftHistory) ? item.shiftHistory : []
	return {
		...item,
		lifecycle: 'postponed',
		plannedPeriod: toPeriod,
		shiftHistory: [
			...history,
			{
				van: item.plannedPeriod,
				toPeriod,
				reason: why,
				door: user || '',
				op: new Date(now).toISOString(),
			},
		],
	}
}

/**
 * Build the lapsed item: lifecycle `lapsed` with its reason.
 *
 * @param {object} item The item as read.
 * @param {string} reason Why it lapses; required.
 * @return {object} The item to save.
 * @throws {Error} When the item is already final or the reason empty.
 *
 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-003-lifecycle-is-declarative-with-shift-and-settle-states
 */
export function buildLapse(item, reason) {
	if (isTerminal(item)) throw new Error('This item is already settled.')
	const why = String(reason ?? '').trim()
	if (!why) throw new Error('A reason is required.')
	return { ...item, lifecycle: 'lapsed', reasonExpired: why }
}

/**
 * Build the realised item: lifecycle `realised` linked to the agenda item
 * and/or decision that realised it. Nothing else is created or changed.
 *
 * @param {object} item The item as read.
 * @param {object} links The links.
 * @param {string} [links.agendaItem] The realising agenda item's id.
 * @param {string} [links.decision] The realising decision's id.
 * @return {object} The item to save.
 * @throws {Error} When the item is already final or neither link is given.
 *
 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-006-realisation-linkage-to-the-actual-agenda-item-or-decision
 */
export function buildRealise(item, { agendaItem, decision } = {}) {
	if (isTerminal(item)) throw new Error('This item is already settled.')
	if (!agendaItem && !decision) throw new Error('Link the agenda item or decision that realised it.')
	return {
		...item,
		lifecycle: 'realised',
		realisedAgendaItem: agendaItem || item.realisedAgendaItem || null,
		realisedDecision: decision || item.realisedDecision || null,
	}
}

/**
 * Check the owner rule: a portfolio holder as owner type needs a person.
 *
 * @param {object} item The item to save.
 * @return {string|null} The problem, or null when the item is fine.
 *
 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-001-termijnagendaitem-schema-on-openregister
 */
export function ownerProblem(item) {
	if (item?.ownerType === 'portfolio-holder' && !item.owner) {
		return 'A portfolio holder as owner needs the person who holds the portfolio.'
	}
	return null
}

/**
 * The agenda items worth suggesting as the realisation of an item: those of
 * the same governance body, best matches on the subject first. Only the
 * caller's list is read; nothing is created.
 *
 * @param {object} item The planned-agenda item.
 * @param {Array<object>} agendaItems Agenda items of the same body.
 * @param {number} [limit] How many to return.
 * @return {Array<object>} The suggestions.
 *
 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-006-realisation-linkage-to-the-actual-agenda-item-or-decision
 */
export function suggestRealisations(item, agendaItems, limit = 10) {
	const words = String(item?.subject ?? '')
		.toLowerCase()
		.split(/[^\p{L}\p{N}]+/u)
		.filter((word) => word.length > 3)
	const score = (candidate) => {
		const title = String(candidate?.title ?? candidate?.name ?? '').toLowerCase()
		return words.filter((word) => title.includes(word)).length
	}
	return (Array.isArray(agendaItems) ? agendaItems : [])
		.map((candidate, index) => ({ candidate, index, score: score(candidate) }))
		.sort((a, b) => b.score - a.score || a.index - b.index)
		.slice(0, limit)
		.map(({ candidate }) => candidate)
}

/**
 * Read the agenda items and decisions that can realise an item: those of the
 * meetings of its governance body. Agenda items and decisions name their
 * meeting, not the body, so the body's meetings are read first. Read-only.
 *
 * @param {Function} fetchCollection `(type, params) => Promise<Array<object>>`, as the object store has it.
 * @param {string} governanceBody The body's id.
 * @return {Promise<{agendaItems: Array<object>, decisions: Array<object>}>} The candidates; empty lists when the body has no meetings or a read fails.
 *
 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-006-realisation-linkage-to-the-actual-agenda-item-or-decision
 */
export async function realisationCandidates(fetchCollection, governanceBody) {
	const empty = { agendaItems: [], decisions: [] }
	if (!governanceBody) return empty
	const read = async (type, params) => {
		try {
			const result = await fetchCollection(type, params)
			return Array.isArray(result) ? result : []
		} catch {
			return []
		}
	}
	const meetings = await read('meeting', { governanceBody })
	const ids = meetings.map((meeting) => meeting?.id).filter(Boolean)
	if (!ids.length) return empty
	const [agendaItems, decisions] = await Promise.all([
		read('agenda-item', { meeting: ids }),
		read('decision', { meeting: ids }),
	])
	return { agendaItems, decisions }
}
