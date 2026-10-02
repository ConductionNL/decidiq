// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Pure helpers for a body's composition (bodies-board-composition-skills-
 * and-diversity, bod-11 and bod-12): the skills matrix with its gaps, and
 * the make-up of the current members against the targets the body set
 * itself. Computed in the widget from what it loads, bounded by the body's
 * size; age bands are worked out on the day and never stored.
 *
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-003-the-skills-matrix-shows-where-the-body-falls-short
 */

import { isActiveMembership } from '../components/tabs/useRelationStore.js'

/** The levels that count towards a competence's required holders. */
const COUNTING_LEVELS = ['experienced', 'expert']

/** The figure dimensions a target can name, by the figures' key. */
const TARGET_DIMENSIONS = {
	gender: 'gender',
	'age-band': 'ageBand',
	nationality: 'nationality',
	independence: 'independence',
}

/**
 * The seats that are current: a membership with an end date is a past one,
 * as on the Members widget.
 *
 * @param {Array<object>} memberships The body's memberships.
 * @return {Array<object>} The current ones.
 *
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-003-the-skills-matrix-shows-where-the-body-falls-short
 */
export function currentSeats(memberships) {
	return (memberships || []).filter(isActiveMembership)
}

/**
 * Whether a member competence is confirmed.
 *
 * @param {object} held A member competence.
 * @return {boolean} True when a signatory confirmed it.
 *
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
 */
export function isConfirmed(held) {
	return Boolean(held?.confirmedBy && held?.confirmedAt)
}

/**
 * The body's active competences in their order.
 *
 * @param {Array<object>} competences The body's competences.
 * @return {Array<object>} The active ones, ordered.
 *
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-001-a-body-lists-the-competences-it-needs
 */
export function activeCompetences(competences) {
	return (competences || [])
		.filter((competence) => competence.active !== false)
		.sort((a, b) => (a.order ?? 0) - (b.order ?? 0) || String(a.name).localeCompare(String(b.name)))
}

/**
 * Per active competence: how many seats hold it confirmed at experienced
 * or expert, against how many the body requires, and whether that is a gap.
 *
 * @param {Array<object>} competences The body's competences.
 * @param {Array<object>} held The member competences.
 * @param {Array<string>|null} seatIds The current seats; null counts every seat.
 * @return {Array<{id: string, name: string, holders: number, required: number, gap: boolean}>} The columns' footers.
 *
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-003-the-skills-matrix-shows-where-the-body-falls-short
 */
export function competenceGaps(competences, held, seatIds = null) {
	return activeCompetences(competences).map((competence) => {
		const seats = new Set(
			(held || [])
				.filter((h) => h.competence === competence.id)
				.filter((h) => COUNTING_LEVELS.includes(h.level) && isConfirmed(h))
				.filter((h) => seatIds === null || seatIds.includes(h.membership))
				.map((h) => h.membership),
		)
		const required = Number(competence.requiredHolders) || 1
		return {
			id: competence.id,
			name: competence.name,
			holders: seats.size,
			required,
			gap: seats.size < required,
		}
	})
}

/**
 * The skills matrix: current members as rows, active competences as
 * columns, each cell the level held with whether it is confirmed.
 *
 * @param {Array<object>} memberships The body's memberships.
 * @param {Object<string, object>} personsById The people, by id.
 * @param {Array<object>} competences The body's competences.
 * @param {Array<object>} held The member competences.
 * @return {{columns: Array<object>, rows: Array<object>, gaps: Array<object>}} The matrix.
 *
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-003-the-skills-matrix-shows-where-the-body-falls-short
 */
export function skillsMatrix(memberships, personsById, competences, held) {
	const columns = activeCompetences(competences)
	const seats = currentSeats(memberships)
	const rows = seats
		.map((seat) => {
			const cells = {}
			for (const column of columns) {
				const found = (held || []).find(
					(h) => h.membership === seat.id && h.competence === column.id,
				)
				cells[column.id] = found
					? {
						id: found.id,
						level: found.level,
						confirmed: isConfirmed(found),
						confirmedBy: found.confirmedBy || '',
					}
					: null
			}
			return {
				seat: seat.id,
				person: seat.person,
				name: personsById?.[seat.person]?.name || seat.person || '',
				cells,
			}
		})
		.sort((a, b) => a.name.localeCompare(b.name))
	return {
		columns,
		rows,
		gaps: competenceGaps(competences, held, seats.map((seat) => seat.id)),
	}
}

/**
 * The age band of a birth date on a day: under 40, 40 to 54, 55 to 69, or
 * 70 and over. Null when no valid date is recorded.
 *
 * @param {string} birthDate The birth date (YYYY-MM-DD, optionally with a time).
 * @param {Date} today The day to count on.
 * @return {string|null} under-40, 40-54, 55-69, 70-plus or null.
 *
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-004-the-body-sees-its-composition-figures-against-its-own-targets
 */
export function ageBand(birthDate, today) {
	const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(birthDate || ''))
	if (!match) return null
	const [year, month, day] = match.slice(1).map(Number)
	let age = today.getFullYear() - year
	if (today.getMonth() + 1 < month || (today.getMonth() + 1 === month && today.getDate() < day)) {
		age -= 1
	}
	if (age < 40) return 'under-40'
	if (age < 55) return '40-54'
	if (age < 70) return '55-69'
	return '70-plus'
}

/**
 * Counts and shares of one dimension, most frequent first, with how many
 * members have nothing recorded.
 *
 * @param {Array<string|null>} values One value per current member, null when not recorded.
 * @return {{total: number, values: Array<{value: string, count: number, share: number}>, notRecorded: number}} The figure.
 *
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-004-the-body-sees-its-composition-figures-against-its-own-targets
 */
function figure(values) {
	const counts = new Map()
	let notRecorded = 0
	for (const value of values) {
		if (value === null || value === undefined || value === '') {
			notRecorded += 1
			continue
		}
		counts.set(value, (counts.get(value) || 0) + 1)
	}
	const total = values.length
	return {
		total,
		values: [...counts.entries()]
			.map(([value, count]) => ({ value, count, share: count / total }))
			.sort((a, b) => b.count - a.count || a.value.localeCompare(b.value)),
		notRecorded,
	}
}

/**
 * Whether a seat is held from outside, as a figure value.
 *
 * @param {object} seat A membership.
 * @return {string|null} yes, no, or null when not recorded.
 *
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-004-the-body-sees-its-composition-figures-against-its-own-targets
 */
function externalValue(seat) {
	if (seat.external === true) return 'yes'
	if (seat.external === false) return 'no'
	return null
}

/**
 * The current members by gender, age band, nationality, independence and
 * whether they sit from outside. Counts only: no names per value.
 *
 * @param {Array<object>} memberships The body's memberships.
 * @param {Object<string, object>} personsById The people, by id.
 * @param {Date} today The day to band ages on.
 * @return {Object<string, object>} The figures by dimension.
 *
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-004-the-body-sees-its-composition-figures-against-its-own-targets
 */
export function compositionFigures(memberships, personsById, today) {
	const seats = currentSeats(memberships)
	const personOf = (seat) => personsById?.[seat.person] || {}
	return {
		gender: figure(seats.map((seat) => personOf(seat).gender || null)),
		ageBand: figure(seats.map((seat) => ageBand(personOf(seat).birthDate, today))),
		nationality: figure(seats.map((seat) => personOf(seat).nationality || null)),
		independence: figure(seats.map((seat) => seat.independenceStatus || null)),
		external: figure(seats.map(externalValue)),
	}
}

/**
 * Each of the body's targets with the current share and whether it is met.
 * A dimension without members never meets a target.
 *
 * @param {Object<string, object>} figures The figures from compositionFigures().
 * @param {Array<{dimension: string, value: string, minimumShare: number}>} targets The body's targets.
 * @return {Array<object>} The targets with share and met.
 *
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-004-the-body-sees-its-composition-figures-against-its-own-targets
 */
export function targetResults(figures, targets) {
	return (targets || []).map((target) => {
		const dimension = figures?.[TARGET_DIMENSIONS[target.dimension]]
		const total = dimension?.total || 0
		const count = dimension?.values.find((v) => v.value === target.value)?.count || 0
		const share = total > 0 ? count / total : 0
		return {
			...target,
			share,
			met: total > 0 && share >= Number(target.minimumShare),
		}
	})
}
