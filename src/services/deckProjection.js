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
 * @spec openspec/specs/action-item-board-via-deck-leaf/spec.md
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
 * Words in a Deck stack title that place a card in a lane, in the order the
 * lanes are tried.
 *
 * @type {Array<[string, string[]]>}
 */
const LANE_TITLE_WORDS = [
	['done', ['done', 'complete', 'completed', 'klaar']],
	['in-progress', ['progress', 'doing', 'bezig', 'mee bezig']],
	['open', ['open', 'to do', 'todo', 'backlog', 'te doen']],
]

/**
 * Match a Deck stack title to a lane, so we can place a card without
 * needing to create stacks (the leaf cannot create stacks).
 *
 * @param {string} title The Deck stack title.
 * @return {string|null} The matched lane, or null when it matches none.
 */
function stackTitleToLane(title) {
	const t = String(title || '').toLowerCase()
	const match = LANE_TITLE_WORDS.find(([, words]) =>
		words.some((word) => t.includes(word)),
	)
	return match ? match[0] : null
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
 *
 * @spec openspec/specs/action-item-board-via-deck-leaf/spec.md#requirement-req-ai-deck-001-vtodo-is-authoritative-deck-leaf-is-the-board-projection
 */
export async function resolveTarget(schema) {
	const sticky = await stickyDefault(schema)
	const boardId = sticky.boardId || (await firstBoardId())
	const stacks = await boardStacks(boardId)
	const fallbackStackId = sticky.stackId || Number(stacks[0].id)
	return { boardId, laneStacks: laneStacksOf(stacks), fallbackStackId }
}

/**
 * The schema-level sticky default board and stack, zeros when there is none
 * or the leaf cannot answer.
 *
 * @param {string} schema The decision/meeting schema slug or id.
 * @return {Promise<{boardId: number, stackId: number}>}
 */
async function stickyDefault(schema) {
	try {
		const { data } = await axios.get(
			generateUrl(
				`${orBase}/integrations/deck/default/${encodeURIComponent(schema)}`,
			),
		)
		return {
			boardId: Number(data?.boardId || 0),
			stackId: Number(data?.stackId || 0),
		}
	} catch (e) {
		return { boardId: 0, stackId: 0 }
	}
}

/**
 * The id of the first available Deck board.
 *
 * @return {Promise<number>}
 * @throws {Error} When no Deck board is available to project onto.
 */
async function firstBoardId() {
	const { data } = await axios.get(
		generateUrl(`${orBase}/integrations/deck/boards`),
	)
	const boards = data?.results || []
	if (!boards.length) {
		throw new Error(
			'No Deck board available to project action items onto. Create a Deck board first.',
		)
	}
	return Number(boards[0].id)
}

/**
 * The stacks on a Deck board.
 *
 * @param {number} boardId The board id.
 * @return {Promise<object[]>} The stacks, never empty.
 * @throws {Error} When the board has no stacks.
 */
async function boardStacks(boardId) {
	const { data } = await axios.get(
		generateUrl(`${orBase}/integrations/deck/boards/${boardId}/stacks`),
	)
	const stacks = data?.results || []
	if (!stacks.length) {
		throw new Error(
			'The target Deck board has no stacks. Add columns to the board first.',
		)
	}
	return stacks
}

/**
 * The first stack whose title matches each lane.
 *
 * @param {object[]} stacks The board's stacks.
 * @return {object} Lane to stack id.
 */
function laneStacksOf(stacks) {
	const laneStacks = {}
	for (const stack of stacks) {
		const lane = stackTitleToLane(stack.title)
		if (lane && !laneStacks[lane]) laneStacks[lane] = Number(stack.id)
	}
	return laneStacks
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
 *
 * @spec openspec/specs/decision-management/spec.md#requirement-decision-list-and-search
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
	const objectPath = deckObjectPath(register, schema, objectId)
	const linkedCardIds = await fetchLinkedCardIds(objectPath)

	const result = { created: 0, skipped: 0, errors: [] }

	for (const item of items) {
		const uid = itemUid(item)
		if (!uid) continue

		const existing = Number(item.deckCardId || 0)
		if (existing && linkedCardIds.has(existing)) {
			result.skipped++
			continue
		}

		const error = await projectOneItem({
			item,
			uid,
			target,
			objectPath,
			linkedCardIds,
			writeBack,
		})
		if (error) {
			result.errors.push(error)
		} else {
			result.created++
		}
	}

	return result
}

/**
 * The OpenRegister path of an object's Deck leaf.
 *
 * @param {string} register Register slug or id.
 * @param {string} schema   Schema slug or id.
 * @param {string} objectId Object id (uuid).
 * @return {string} `${orBase}/objects/<register>/<schema>/<objectId>/deck`.
 */
function deckObjectPath(register, schema, objectId) {
	return `${orBase}/objects/${encodeURIComponent(register)}/${encodeURIComponent(schema)}/${encodeURIComponent(objectId)}/deck`
}

/**
 * Cards already linked to the object, used to confirm a stored deckCardId is
 * still valid (not unlinked/deleted out-of-band).
 *
 * @param {string} objectPath The object's Deck-leaf path.
 * @return {Promise<Set<number>>} The linked card ids; empty when listing fails.
 */
async function fetchLinkedCardIds(objectPath) {
	try {
		const { data } = await axios.get(generateUrl(objectPath))
		return new Set((data?.results || []).map((c) => Number(c.cardId)))
	} catch (e) {
		// No existing links (or list failed) — treat as none linked yet.
		return new Set()
	}
}

/**
 * The create-card payload for one action item.
 *
 * @param {object} item   The action-item row.
 * @param {string} uid    Its VTODO uid.
 * @param {object} target The resolved board/stack target.
 * @return {object} The request body.
 */
function buildCardBody(item, uid, target) {
	const lane = statusToLane(item.taskStatus)
	const body = {
		boardId: target.boardId,
		stackId: target.laneStacks[lane] || target.fallbackStackId,
		title: String(item.title || 'Action item'),
		description: `Decidiq action item (${uid})`,
	}
	if (item.dueDate) body.duedate = String(item.dueDate)
	return body
}

/**
 * Create the Deck card for one action item and write its id back.
 *
 * @param {object}      params               Parameters.
 * @param {object}      params.item          The action-item row.
 * @param {string}      params.uid           Its VTODO uid.
 * @param {object}      params.target        The resolved board/stack target.
 * @param {string}      params.objectPath    The object's Deck-leaf path.
 * @param {Set<number>} params.linkedCardIds Linked card ids; the new id is added.
 * @param {(uid: string, cardId: number) => Promise<unknown>} params.writeBack Persists the card id onto the VTODO.
 * @return {Promise<object|null>} An `{uid, error}` entry, or null on success.
 */
async function projectOneItem({ item, uid, target, objectPath, linkedCardIds, writeBack }) {
	try {
		const { data } = await axios.post(
			generateUrl(`${objectPath}/new`),
			buildCardBody(item, uid, target),
		)
		const cardId = Number(data?.cardId || 0)
		if (!cardId) {
			return { uid, error: 'No card id returned' }
		}
		await writeBack(uid, cardId)
		linkedCardIds.add(cardId)
		return null
	} catch (e) {
		return {
			uid,
			error: e?.response?.data?.error || e?.message || 'Projection failed',
		}
	}
}
