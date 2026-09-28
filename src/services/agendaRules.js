// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// agendaRules — pure agenda-domain helpers shared by AgendaBuilder and
// MeetingAgendaTab:
//   • statutory ALV (general_assembly) agenda-item enforcement (BW 2:38)
//   • hierarchical sub-item tree build + parent→children flatten
//   • frontend mirror of the MeetingSeriesService recurrence expansion
//     (preview counts only — the server expansion is authoritative)
//
// Pure functions, no DOM, fully vitest-covered.
//
// @spec openspec/specs/agenda-management/spec.md

/**
 * Maximum number of series instances (mirror of MeetingSeriesService::MAX_INSTANCES).
 */
export const MAX_SERIES_INSTANCES = 52

/**
 * The legally required ALV agenda items (BW 2:38 — association general
 * assembly). Keys are stable ids; `label` is the English source string
 * (translated at render time); `synonyms` are lower-cased en+nl substrings
 * matched against agenda-item titles.
 *
 * @spec openspec/specs/agenda-management/spec.md
 */
export const STATUTORY_ALV_ITEMS = [
	{ id: 'opening', label: 'Opening', synonyms: ['opening'] },
	{
		id: 'previous-minutes',
		label: 'Approval of previous minutes',
		synonyms: ['previous minutes', 'minutes', 'notulen'],
	},
	{
		id: 'annual-report',
		label: 'Annual report',
		synonyms: ['annual report', 'jaarverslag'],
	},
	{
		id: 'financial-statements',
		label: 'Financial statements',
		// A SYNONYM list is deliberately Dutch — it exists to match Dutch agenda
		// titles. The value pass rewrote `jaarrekening` here and broke exactly the
		// test that checks nl matching.
		synonyms: ['financial statements', 'jaarrekening', 'financieel verslag'],
	},
	{
		id: 'audit-committee-report',
		label: 'Audit committee report',
		// The SYNONYMS stay Dutch on purpose: they match the words a Dutch
		// agenda actually uses. Only the label a reader sees is generic.
		synonyms: ['kascommissie', 'audit committee report', 'kascontrole'],
	},
	{
		id: 'board-elections',
		label: 'Board elections',
		synonyms: [
			'board election',
			'bestuursverkiezing',
			'verkiezing bestuur',
			'benoeming bestuur',
		],
	},
	{
		id: 'any-other-business',
		label: 'Any other business',
		synonyms: ['any other business', 'rondvraag', 'w.v.t.t.k', 'wvttk'],
	},
	{ id: 'closing', label: 'Closing', synonyms: ['closing', 'sluiting'] },
]

/**
 * Compute which statutory ALV items are missing from an agenda.
 *
 * Only active for `general_assembly` meetings; any other meeting type
 * returns an empty list (no enforcement). Matching is a case-insensitive
 * substring test of each synonym against the item titles.
 *
 * @param {string} meetingType Meeting type (e.g. 'general_assembly').
 * @param {Array<object>} items Agenda items (objects with `title`).
 *
 * @return {Array<object>} The missing STATUTORY_ALV_ITEMS entries.
 *
 * @spec openspec/specs/agenda-management/spec.md
 */
export function missingStatutoryItems(meetingType, items) {
	if (meetingType !== 'general_assembly') {
		return []
	}
	const titles = (items || []).map((i) => String(i?.title || '').toLowerCase())
	return STATUTORY_ALV_ITEMS.filter(
		(required) =>
			!titles.some((title) =>
				required.synonyms.some((synonym) => title.includes(synonym)),
			),
	)
}

/**
 * Group agenda items into a parent → children tree.
 *
 * Items with a `parentItem` pointing at a known item nest under it; items
 * with an unknown parent degrade to top-level (no orphan loss). Both levels
 * are sorted by `orderNumber`.
 *
 * @param {Array<object>} items Flat agenda items.
 *
 * @return {Array<object>} Top-level nodes `{ item, children: [item, ...] }`.
 *
 * @spec openspec/specs/agenda-management/spec.md
 */
export function buildAgendaTree(items) {
	const list = (items || [])
		.slice()
		.sort((a, b) => (a.orderNumber ?? 0) - (b.orderNumber ?? 0))
	const byId = new Map(list.map((item) => [String(item.id), item]))

	const topLevel = []
	const childrenByParent = new Map()

	for (const item of list) {
		const parentId = item.parentItem ? String(item.parentItem) : null
		if (parentId && byId.has(parentId) && parentId !== String(item.id)) {
			if (!childrenByParent.has(parentId)) {
				childrenByParent.set(parentId, [])
			}
			childrenByParent.get(parentId).push(item)
		} else {
			topLevel.push(item)
		}
	}

	return topLevel.map((item) => ({
		item,
		children: childrenByParent.get(String(item.id)) || [],
	}))
}

/**
 * Flatten a tree back to parent→children order (the order persisted via
 * the reorder endpoint, which assigns global sequential orderNumbers —
 * children keep sorting within their parent group on reload).
 *
 * @param {Array<object>} tree Tree from buildAgendaTree().
 *
 * @return {Array<object>} Flat items in parent→children order.
 *
 * @spec openspec/specs/agenda-management/spec.md
 */
export function flattenTree(tree) {
	const flat = []
	for (const node of tree || []) {
		flat.push(node.item)
		for (const child of node.children) {
			flat.push(child)
		}
	}
	return flat
}

/**
 * Find the sibling list that holds an agenda item, top level or sub-items.
 *
 * @param {Array<object>} tree Tree from buildAgendaTree().
 * @param {string} id Agenda item id.
 *
 * @return {?{list: Array<object>, index: number}} The list of nodes or items and the index, or null.
 *
 * @spec openspec/changes/agenda-meeting-page-item-tools/specs/agenda-management/spec.md#requirement-req-amp-002-a-chair-or-secretary-reorders-the-agenda-on-the-meeting-page
 */
function locateAgendaItem(tree, id) {
	const key = String(id)
	const top = tree.findIndex((node) => String(node.item.id) === key)
	if (top !== -1) return { list: tree, index: top }
	for (const node of tree) {
		const child = node.children.findIndex((item) => String(item.id) === key)
		if (child !== -1) return { list: node.children, index: child }
	}
	return null
}

/**
 * Copy a tree so a move never mutates the rendered rows.
 *
 * @param {Array<object>} tree Tree from buildAgendaTree().
 *
 * @return {Array<object>} A copy with fresh sibling arrays.
 *
 * @spec openspec/changes/agenda-meeting-page-item-tools/specs/agenda-management/spec.md#requirement-req-amp-002-a-chair-or-secretary-reorders-the-agenda-on-the-meeting-page
 */
function copyTree(tree) {
	return (tree || []).map((node) => ({
		item: node.item,
		children: node.children.slice(),
	}))
}

/**
 * Move one agenda item a step up or down among its siblings. A parent takes
 * its sub-items along; a sub-item stays under its parent.
 *
 * @param {Array<object>} tree Tree from buildAgendaTree().
 * @param {string} id Agenda item id to move.
 * @param {number} delta -1 for up, 1 for down.
 *
 * @return {?Array<string>} The full id order for the reorder endpoint, or null when nothing moves.
 *
 * @spec openspec/changes/agenda-meeting-page-item-tools/specs/agenda-management/spec.md#requirement-req-amp-002-a-chair-or-secretary-reorders-the-agenda-on-the-meeting-page
 */
export function moveAgendaItem(tree, id, delta) {
	const copy = copyTree(tree)
	const found = locateAgendaItem(copy, id)
	if (!found) return null
	const target = found.index + delta
	if (target < 0 || target >= found.list.length) return null
	const [moved] = found.list.splice(found.index, 1)
	found.list.splice(target, 0, moved)
	return flattenTree(copy).map((item) => item.id)
}

/**
 * Put a dragged agenda item in the place of the item it was dropped on. Only
 * a drop on a sibling (same level, same parent) moves anything.
 *
 * @param {Array<object>} tree Tree from buildAgendaTree().
 * @param {string} dragId Id of the dragged item.
 * @param {string} dropId Id of the item it was dropped on.
 *
 * @return {?Array<string>} The full id order for the reorder endpoint, or null when nothing moves.
 *
 * @spec openspec/changes/agenda-meeting-page-item-tools/specs/agenda-management/spec.md#requirement-req-amp-002-a-chair-or-secretary-reorders-the-agenda-on-the-meeting-page
 */
export function dropAgendaItem(tree, dragId, dropId) {
	if (String(dragId) === String(dropId)) return null
	const copy = copyTree(tree)
	const from = locateAgendaItem(copy, dragId)
	const to = locateAgendaItem(copy, dropId)
	if (!from || !to || from.list !== to.list) return null
	const [moved] = from.list.splice(from.index, 1)
	from.list.splice(to.index, 0, moved)
	return flattenTree(copy).map((item) => item.id)
}

/**
 * Whether the caller may reorder the agenda and open the live screen: chair,
 * secretary or admin, as answered by GET /api/meetings/{id}/my-roles. This is
 * the same set AgendaAuthorizationGuard::requireChairOrAdmin() accepts.
 *
 * @param {?{chair: boolean, secretary: boolean, admin: boolean}} roles The server's answer.
 *
 * @return {boolean} True when the agenda tools should show.
 *
 * @spec openspec/changes/agenda-meeting-page-item-tools/specs/agenda-management/spec.md#requirement-req-amp-001-the-meeting-page-asks-the-server-for-the-callers-meeting-roles
 */
export function canManageAgenda(roles) {
	return Boolean(roles && (roles.chair || roles.secretary || roles.admin))
}

/**
 * Frontend mirror of MeetingSeriesService::expandPattern() — used for the
 * live preview count in the Series tab. Returns ISO dates (date part only;
 * the server preserves the template time).
 *
 * @param {string} startDate Template start datetime (ISO-8601).
 * @param {object} pattern Recurrence pattern `{frequency, interval, until, exceptions}`.
 *
 * @return {{dates: Array<string>, truncated: boolean, error: string|null}} Expansion result.
 *
 * @spec openspec/specs/meeting-management/spec.md
 */
export function expandRecurrence(startDate, pattern) {
	const frequency = pattern?.frequency
	if (!['daily', 'weekly', 'monthly'].includes(frequency)) {
		return { dates: [], truncated: false, error: 'frequency' }
	}
	const interval = Number(pattern?.interval ?? 1)
	if (!Number.isFinite(interval) || interval < 1) {
		return { dates: [], truncated: false, error: 'interval' }
	}
	if (!pattern?.until) {
		return { dates: [], truncated: false, error: 'until' }
	}

	const start = new Date(`${String(startDate).slice(0, 10)}T00:00:00Z`)
	const until = new Date(`${String(pattern.until).slice(0, 10)}T23:59:59Z`)
	if (Number.isNaN(start.getTime()) || Number.isNaN(until.getTime())) {
		return { dates: [], truncated: false, error: 'date' }
	}

	const exceptions = new Set(
		(pattern.exceptions || []).map((e) => String(e).slice(0, 10)),
	)
	const dates = []
	let truncated = false

	const isoDay = (d) => d.toISOString().slice(0, 10)

	if (frequency === 'monthly') {
		const dayOfMonth = start.getUTCDate()
		for (let offset = 0; ; offset += interval) {
			const firstOfMonth = new Date(
				Date.UTC(start.getUTCFullYear(), start.getUTCMonth() + offset, 1),
			)
			if (firstOfMonth > until) break
			const daysInMonth = new Date(
				Date.UTC(
					firstOfMonth.getUTCFullYear(),
					firstOfMonth.getUTCMonth() + 1,
					0,
				),
			).getUTCDate()
			if (dayOfMonth > daysInMonth) continue
			const occurrence = new Date(
				Date.UTC(
					firstOfMonth.getUTCFullYear(),
					firstOfMonth.getUTCMonth(),
					dayOfMonth,
				),
			)
			if (occurrence > until) break
			if (exceptions.has(isoDay(occurrence))) continue
			if (dates.length >= MAX_SERIES_INSTANCES) {
				truncated = true
				break
			}
			dates.push(isoDay(occurrence))
		}
	} else {
		const stepDays = frequency === 'daily' ? interval : interval * 7
		for (let step = 0; ; step++) {
			const occurrence = new Date(start.getTime() + step * stepDays * 86400000)
			if (occurrence > until) break
			if (exceptions.has(isoDay(occurrence))) continue
			if (dates.length >= MAX_SERIES_INSTANCES) {
				truncated = true
				break
			}
			dates.push(isoDay(occurrence))
		}
	}

	return { dates, truncated, error: null }
}
