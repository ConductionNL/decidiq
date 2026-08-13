/**
 * Action-item → real Nextcloud Deck card projection.
 *
 * Action items are read-only CalDAV-VTODO projections (the `action-item`
 * OpenRegister schema). This service projects each action-item VTODO of a
 * meeting/decision onto a *real* Nextcloud Deck card via the existing
 * OpenRegister Deck leaf HTTP API (ADR-019), so they surface on an actual
 * Deck board. The VTODO stays authoritative; the Deck card is a forward
 * projection.
 *
 * Idempotency: the created card's id is written back onto the VTODO
 * (`deckCardId`, which rides the X-OPENREGISTER-DATA field blob through
 * ActionItemWriter::update). A VTODO that already carries a still-linked
 * `deckCardId` is skipped on re-run, so no duplicate cards are created.
 *
 * v1 is forward-only — editing a card in the native Deck app does NOT sync
 * back to the VTODO.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/action-item-deck-board/specs/action-item-board-via-deck-leaf/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { updateActionItem } from './actionItemApi.js'

const orBase = '/apps/openregister/api'

/**
 * Canonical board lanes, in display order.
 *
 * @type {string[]}
 */
export const LANES = ['open', 'in-progress', 'done']

/**
 * Normalise any action-item `taskStatus` onto a canonical lane.
 * Unknown / legacy statuses fold onto `open` (REQ-AI-DECK-007).
 *
 * @param {string} status The raw action-item status.
 * @return {string} One of LANES.
 */
export function statusToLane(status) {
	const s = String(status || '').toLowerCase()
	if (s === 'done' || s === 'completed' || s === 'complete') return 'done'
	if (
		s === 'in-progress'
		|| s === 'in_progress'
		|| s === 'inprogress'
		|| s === 'doing'
	)
		return 'in-progress'
	return 'open'
}

/**
 * Match a Deck stack title to a lane, so we can place a card without
 * needing to create stacks (the leaf cannot create stacks).
 *
 * @param {string} title The Deck stack title.
 * @return {string|null} The matched lane, or null when it matches none.
 */
function stackTitleToLane(title) {
	const t = String(title || '').toLowerCase()
	if (
		t.includes('done')
		|| t.includes('complete')
		|| t.includes('afgerond')
		|| t.includes('klaar')
	)
		return 'done'
	if (
		t.includes('progress')
		|| t.includes('doing')
		|| t.includes('bezig')
		|| t.includes('mee bezig')
	)
		return 'in-progress'
	if (
		t.includes('open')
		|| t.includes('to do')
		|| t.includes('todo')
		|| t.includes('backlog')
		|| t.includes('te doen')
	)
		return 'open'
	return null
}

/**
 * Is the Nextcloud Deck app available (installed + enabled)?
 *
 * Probes the leaf's boards endpoint: a 501 (APP_NOT_AVAILABLE) means Deck
 * is absent; anything else (incl. an empty board list) means it is present.
 *
 * @return {Promise<boolean>}
 */
export async function isDeckAvailable() {
	try {
		await axios.get(generateUrl(`${orBase}/integrations/deck/boards`))
		return true
	} catch (e) {
		// 501 = leaf reports Deck not installed; treat any error as unavailable.
		return false
	}
}

/**
 * Resolve the board + per-lane stacks the projection should target.
 *
 * Prefers the schema-level sticky default board; falls back to the first
 * available board. Stacks are matched to lanes by title; unmatched lanes
 * fall back to the default stack (the sticky default's stack, else the
 * first stack on the board).
 *
 * @param {string} schema The decision/meeting schema slug or id.
 * @return {Promise<{boardId: number, laneStacks: object, fallbackStackId: number}>}
 * @throws {Error} When no Deck board is available to project onto.
 */
export async function resolveTarget(schema) {
	let boardId = 0
	let defaultStackId = 0
	try {
		const { data } = await axios.get(
			generateUrl(
				`${orBase}/integrations/deck/default/${encodeURIComponent(schema)}`,
			),
		)
		boardId = Number(data?.boardId || 0)
		defaultStackId = Number(data?.stackId || 0)
	} catch (e) {
		// No sticky default — fall through to first board.
	}

	if (!boardId) {
		const { data } = await axios.get(
			generateUrl(`${orBase}/integrations/deck/boards`),
		)
		const boards = data?.results || []
		if (!boards.length) {
			throw new Error(
				'No Deck board available to project action items onto. Create a Deck board first.',
			)
		}
		boardId = Number(boards[0].id)
	}

	const { data: stacksData } = await axios.get(
		generateUrl(`${orBase}/integrations/deck/boards/${boardId}/stacks`),
	)
	const stacks = stacksData?.results || []
	if (!stacks.length) {
		throw new Error(
			'The target Deck board has no stacks. Add columns to the board first.',
		)
	}

	const laneStacks = {}
	for (const stack of stacks) {
		const lane = stackTitleToLane(stack.title)
		if (lane && !laneStacks[lane]) laneStacks[lane] = Number(stack.id)
	}

	const fallbackStackId = defaultStackId || Number(stacks[0].id)
	return { boardId, laneStacks, fallbackStackId }
}

/**
 * The VTODO uid carried by an action-item row (uuid / id / @self.uuid).
 *
 * @param {object} item An action-item row from the projection.
 * @return {string} The uid, or ''.
 */
export function itemUid(item) {
	return String(
		(item && (item.uuid || item.id || (item['@self'] && item['@self'].uuid)))
			|| '',
	)
}

/**
 * Project a meeting/decision's action items onto real Deck cards.
 *
 * For each item without a still-linked `deckCardId`, creates a Deck card on
 * the resolved board in the lane matching its status, then writes the new
 * card id back onto the VTODO so re-runs are idempotent.
 *
 * @param {object}   params              Projection parameters.
 * @param {string}   params.register     Decision/meeting register slug or id.
 * @param {string}   params.schema       Decision/meeting schema slug or id.
 * @param {string}   params.objectId     The decision/meeting object id (uuid).
 * @param {object[]} params.items        Action-item rows (need title/dueDate/taskStatus + uid).
 * @param {Function} [params.persistCardId] Override for the write-back (testing).
 * @return {Promise<{created: number, skipped: number, errors: object[]}>}
 */
export async function projectActionItems({
	register,
	schema,
	objectId,
	items,
	persistCardId,
}) {
	const writeBack =
		persistCardId
		|| ((uid, cardId) => updateActionItem(uid, { deckCardId: cardId }))
	const target = await resolveTarget(schema)

	// Cards already linked to this object — used to confirm a stored
	// deckCardId is still valid (not unlinked/deleted out-of-band).
	let linkedCardIds = new Set()
	try {
		const { data } = await axios.get(
			generateUrl(
				`${orBase}/objects/${encodeURIComponent(register)}/${encodeURIComponent(schema)}/${encodeURIComponent(objectId)}/deck`,
			),
		)
		linkedCardIds = new Set((data?.results || []).map((c) => Number(c.cardId)))
	} catch (e) {
		// No existing links (or list failed) — treat as none linked yet.
	}

	let created = 0
	let skipped = 0
	const errors = []

	for (const item of items) {
		const uid = itemUid(item)
		if (!uid) continue

		const existing = Number(item.deckCardId || 0)
		if (existing && linkedCardIds.has(existing)) {
			skipped++
			continue
		}

		const lane = statusToLane(item.taskStatus)
		const stackId = target.laneStacks[lane] || target.fallbackStackId

		try {
			const body = {
				boardId: target.boardId,
				stackId,
				title: String(item.title || 'Action item'),
				description: `Decidesk action item (${uid})`,
			}
			if (item.dueDate) body.duedate = String(item.dueDate)

			const { data } = await axios.post(
				generateUrl(
					`${orBase}/objects/${encodeURIComponent(register)}/${encodeURIComponent(schema)}/${encodeURIComponent(objectId)}/deck/new`,
				),
				body,
			)
			const cardId = Number(data?.cardId || 0)
			if (cardId) {
				await writeBack(uid, cardId)
				linkedCardIds.add(cardId)
				created++
			} else {
				errors.push({ uid, error: 'No card id returned' })
			}
		} catch (e) {
			errors.push({
				uid,
				error: e?.response?.data?.error || e?.message || 'Projection failed',
			})
		}
	}

	return { created, skipped, errors }
}
