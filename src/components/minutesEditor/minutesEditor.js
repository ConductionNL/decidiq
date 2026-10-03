// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// minutesEditor — pure logic for the real-time minute-taking editor and the
// approval-workflow surfaces (minutes-ui-v1). Kept free of Vue/DOM so the
// debounced-autosave scheduler, itemNotes merging, and corrections state can
// be unit-tested with vitest.
//
// @spec openspec/specs/resolution-minutes/spec.md

/**
 * Lifecycle states a Minutes record moves through (forward order).
 */
export const LIFECYCLE_STAGES = [
	'draft',
	'review',
	'approved',
	'signed',
	'published',
]

/**
 * Read the note entry for an agenda item from a Minutes `itemNotes` array.
 *
 * @param {Array|undefined} itemNotes The Minutes itemNotes array.
 * @param {string} agendaItemId Agenda item UUID.
 * @return {{agendaItem: string, notes: string, decisions: string}} The entry (empty defaults).
 * @spec openspec/specs/resolution-minutes/spec.md
 */
export function getItemNote(itemNotes, agendaItemId) {
	const list = Array.isArray(itemNotes) ? itemNotes : []
	const found = list.find((entry) => entry && entry.agendaItem === agendaItemId)
	return {
		agendaItem: agendaItemId,
		notes: found?.notes || '',
		decisions: found?.decisions || '',
	}
}

/**
 * Merge a patch into the itemNotes array for one agenda item (immutable).
 *
 * Entries for other agenda items are preserved untouched; an entry is
 * created when the agenda item had none yet. Entries whose notes AND
 * decisions are both empty after the merge are dropped to keep the
 * persisted object lean.
 *
 * @param {Array|undefined} itemNotes The current itemNotes array.
 * @param {string} agendaItemId Agenda item UUID.
 * @param {{notes?: string, decisions?: string}} patch Fields to update.
 * @return {Array} A new itemNotes array.
 * @spec openspec/specs/resolution-minutes/spec.md
 */
export function mergeItemNote(itemNotes, agendaItemId, patch) {
	const list = Array.isArray(itemNotes) ? itemNotes : []
	const current = getItemNote(list, agendaItemId)
	const merged = { ...current, ...patch, agendaItem: agendaItemId }
	const rest = list.filter((entry) => entry && entry.agendaItem !== agendaItemId)
	if ((merged.notes || '') === '' && (merged.decisions || '') === '') {
		return rest
	}
	return [...rest, merged]
}

/**
 * Create a debounced autosave scheduler.
 *
 * `schedule(payload)` stores the latest payload and (re)starts the debounce
 * timer; only the most recent payload is saved. While a save is in flight,
 * newly scheduled payloads are queued and saved once the in-flight save
 * settles (no concurrent writes to the same Minutes object). `flush()`
 * fires a pending save immediately; `cancel()` discards it.
 *
 * @param {object} options Options.
 * @param {Function} options.save async (payload) => void — performs the write.
 * @param {number} [options.delay] Debounce delay in ms (default 1500).
 * @param {Function} [options.onStateChange] (state: 'idle'|'pending'|'saving'|'saved'|'error') => void.
 * @return {{schedule: Function, flush: Function, cancel: Function, getState: Function}} The scheduler.
 * @spec openspec/specs/resolution-minutes/spec.md
 */
export function createAutosaver({ save, delay = 1500, onStateChange = () => {} }) {
	let timer = null
	let pendingPayload = null
	let saving = false
	let state = 'idle'

	const setState = (next) => {
		state = next
		onStateChange(next)
	}

	const run = async () => {
		if (saving) {
			// A save is in flight — it will pick the pending payload up on settle.
			return
		}
		while (pendingPayload !== null) {
			const payload = pendingPayload
			pendingPayload = null
			saving = true
			setState('saving')
			try {
				await save(payload)
				if (pendingPayload === null) {
					setState('saved')
				}
			} catch (e) {
				setState('error')
			} finally {
				saving = false
			}
		}
	}

	return {
		schedule(payload) {
			pendingPayload = payload
			setState('pending')
			if (timer) {
				clearTimeout(timer)
			}
			timer = setTimeout(() => {
				timer = null
				run()
			}, delay)
		},
		flush() {
			if (timer) {
				clearTimeout(timer)
				timer = null
			}
			return run()
		},
		cancel() {
			if (timer) {
				clearTimeout(timer)
				timer = null
			}
			pendingPayload = null
			setState('idle')
		},
		getState() {
			return state
		},
	}
}

/**
 * Count corrections by status.
 *
 * @param {Array|undefined} corrections The Minutes corrections array.
 * @return {{proposed: number, accepted: number, rejected: number}} Counts.
 * @spec openspec/specs/resolution-minutes/spec.md
 */
export function correctionCounts(corrections) {
	const counts = { proposed: 0, accepted: 0, rejected: 0 }
	for (const correction of Array.isArray(corrections) ? corrections : []) {
		const status = correction?.status
		if (status in counts) {
			counts[status]++
		}
	}
	return counts
}

/**
 * Whether correction suggestions are accepted in the given lifecycle state.
 *
 * @param {string} lifecycle The Minutes lifecycle state.
 * @return {boolean} True while draft or review.
 * @spec openspec/specs/resolution-minutes/spec.md
 */
export function canSuggestCorrections(lifecycle) {
	return ['draft', 'review'].includes(lifecycle)
}

/**
 * The chair/secretary workflow actions available in a lifecycle state.
 *
 * Mirrors the server's forward transition map plus the guarded
 * review → draft rejection; the server remains authoritative.
 *
 * @param {string} lifecycle The Minutes lifecycle state.
 * @return {Array<{action: string, target: string}>} Available actions.
 * @spec openspec/specs/resolution-minutes/spec.md
 */
export function availableWorkflowActions(lifecycle) {
	switch (lifecycle) {
		case 'draft':
			return [{ action: 'submit', target: 'review' }]
		case 'review':
			return [
				{ action: 'approve', target: 'approved' },
				{ action: 'reject', target: 'draft' },
			]
		case 'approved':
			return [{ action: 'sign', target: 'signed' }]
		case 'signed':
			return [{ action: 'publish', target: 'published' }]
		default:
			return []
	}
}

/**
 * The agenda items that get minutes: regular items in agenda order. Hamerstukken
 * (tag `hamerstuk`) are adopted without debate and carry no per-item notes, the
 * same filter the live screen applies.
 *
 * @param {Array<object>|null} items Agenda items of one meeting.
 *
 * @return {Array<object>} Regular items sorted by orderNumber.
 *
 * @spec openspec/specs/resolution-minutes/spec.md#requirement-req-amp-005-the-minutes-page-carries-the-per-item-minutes-editor
 */
export function regularAgendaItems(items) {
	return (items || [])
		.filter((item) => !(item.tags ?? []).includes('hamerstuk'))
		.sort((a, b) => (a.orderNumber ?? 0) - (b.orderNumber ?? 0))
}

/**
 * The participants of one meeting. Participant carries the meeting as an
 * OpenRegister relation, not as a queryable property, so the list is filtered
 * here exactly as the live screen does.
 *
 * @param {Array<object>|null} list Participant objects.
 * @param {string} meetingId Meeting UUID.
 *
 * @return {Array<object>} The meeting's participants.
 *
 * @spec openspec/specs/resolution-minutes/spec.md#requirement-req-amp-005-the-minutes-page-carries-the-per-item-minutes-editor
 */
export function meetingParticipants(list, meetingId) {
	return (list || []).filter(
		(p) =>
			p?.['@self']?.relations?.meeting === meetingId
			|| p?.relations?.meeting === meetingId,
	)
}

/**
 * Choose the minutes record the editor writes to. On the minutes page that is
 * the record the page shows (by id), whatever its lifecycle; on the live screen,
 * which passes no id, it is the meeting's draft, else the first record.
 *
 * @param {Array<object>|null} list Minutes records of one meeting.
 * @param {string} minutesId Id of the record the page is about, or ''.
 *
 * @return {?object} The minutes record, or null.
 *
 * @spec openspec/specs/resolution-minutes/spec.md#requirement-req-amp-005-the-minutes-page-carries-the-per-item-minutes-editor
 */
export function pickMinutesRecord(list, minutesId) {
	const records = list || []
	if (minutesId) {
		const exact = records.find((m) => String(m.id) === String(minutesId))
		if (exact) return exact
	}
	return records.find((m) => m.lifecycle === 'draft') || records[0] || null
}
