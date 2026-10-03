// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Shared helpers for the "Parafering" (approval chain) integration leaf.
//
// The leaf shows the sign-off route travelling a host object, and lets the
// person whose turn it is approve or reject it, on ANY consuming object's
// detail page or sidebar (ADR-019 / ADR-022). The host can be a dossiq
// document, an opencatalogi publication, or any OpenRegister object; nothing
// here names a consuming app.
//
// EVERY WRITE GOES THROUGH DECIDIQ'S OWN CONTROLLER.
// Start, approve and reject post to `/apps/decidiq/api/approval-routes/…`,
// never to the host app and never straight at OpenRegister. The engine's
// refusals ARE the sign-off route: an action by somebody the live step does
// not name is refused server-side, and a leaf that wrote the stage itself
// would be a second engine with none of those rules (ADR-066 decision 2,
// REQ-ARE-004).
//
// The stages are read straight off the OpenRegister objects API rather than
// through decidiq's Pinia store: the leaf renders inside a foreign app's
// bundle, where `getActivePinia` is not decidiq's, and `useObjectStore()`
// threw `reading '_s' of undefined` the last time a leaf tried it.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Stage statuses that mean the route is still travelling.
 *
 * @type {string[]}
 */
export const UNFINISHED = ['active', 'pending']

/**
 * OpenRegister objects endpoint for decidiq `decision-stage` objects.
 *
 * @return {string} The resolved objects URL.
 */
function stagesUrl() {
	return generateUrl('/apps/openregister/api/objects/{register}/{schema}', {
		register: 'decidiq',
		schema: 'decision-stage',
	})
}

/**
 * Read an object's id whatever shape OpenRegister returned it in.
 *
 * @param {object} row An object row.
 * @return {string} The id, or ''.
 */
export function objId(row) {
	if (!row) return ''
	return String(row.id || (row['@self'] && row['@self'].id) || row.uuid || '')
}

/**
 * The stages of the route travelling a host object, in step order.
 *
 * @param {string} subjectId The host object's UUID.
 * @param {number} [limit] Max rows.
 * @return {Promise<object[]>} The stages, or `[]`.
 */
export async function listStages(subjectId, limit = 100) {
	if (!subjectId) return []
	// Filtered on `decision`, the stage's own back-reference to its subject.
	const res = await axios.get(stagesUrl(), {
		params: { decision: subjectId, _limit: limit },
	})
	const data = res && res.data
	const rows = Array.isArray(data)
		? data
		: data && Array.isArray(data.results)
			? data.results
			: []
	return rows
		.slice()
		.sort((a, b) => Number(a.sequence || 0) - Number(b.sequence || 0))
}

/**
 * OpenRegister objects endpoint for decidiq `approval-action` objects.
 *
 * @return {string} The resolved objects URL.
 */
function actionsUrl() {
	return generateUrl('/apps/openregister/api/objects/{register}/{schema}', {
		register: 'decidiq',
		schema: 'approval-action',
	})
}

/**
 * Every action recorded against a host object, oldest first.
 *
 * The REASON somebody gave lives here and not on the stage: a stage holds one
 * status, and a step that was returned, revised and approved has three things
 * said about it. Reading the reason off the stage would show the last one as if
 * it were the only one, and `note` on a stage is the subject's schema slug, not
 * anybody's words.
 *
 * @param {string} subjectId The host object's UUID.
 * @param {number} [limit] Max rows.
 * @return {Promise<object[]>} The actions, or `[]`.
 */
export async function listActions(subjectId, limit = 200) {
	if (!subjectId) return []
	const res = await axios.get(actionsUrl(), {
		params: { subject: subjectId, _limit: limit },
	})
	const data = res && res.data
	const rows = Array.isArray(data)
		? data
		: data && Array.isArray(data.results)
			? data.results
			: []
	return rows
		.slice()
		.sort((a, b) =>
			String(a.recordedAt || '').localeCompare(String(b.recordedAt || '')),
		)
}

/**
 * The actions recorded against each step, keyed by step number.
 *
 * @param {object[]} actions The actions.
 * @return {object} A map of step number to its actions, in order.
 */
export function actionsByStep(actions) {
	const byStep = {}
	for (const action of actions || []) {
		const step = String(Number(action.step || 0))
		if (byStep[step] === undefined) byStep[step] = []
		byStep[step].push(action)
	}
	return byStep
}

/**
 * The step the route is on, or null when it has finished.
 *
 * @param {object[]} stages The stages, in order.
 * @return {object|null} The live stage.
 */
export function liveStage(stages) {
	if (!Array.isArray(stages) || stages.length === 0) return null
	return (
		stages.find((stage) => String(stage.status || '') === 'active')
		|| stages.find((stage) => String(stage.status || '') === 'pending')
		|| null
	)
}

/**
 * Whether a stage is past its due date with nothing recorded on it.
 *
 * COMPUTED, never stored. A stored "overdue" flag needs somebody to unset it
 * the moment the step is signed, and the moment it is not unset the timeline
 * lies about a step that is already done.
 *
 * @param {object} stage The stage.
 * @param {Date} [now] The clock.
 * @return {boolean} True when it renders overdue.
 */
export function isOverdue(stage, now) {
	if (!stage || String(stage.status || '') !== 'active') return false
	const dueAt = String(stage.dueAt || '')
	if (!dueAt) return false
	const due = new Date(dueAt)
	if (Number.isNaN(due.getTime())) return false
	return due < (now || new Date())
}

/**
 * Whether this user is the person the live step is waiting on.
 *
 * Decides only whether the BUTTONS are drawn. It is not the authorisation:
 * decidiq's controller refuses an action by anybody the live stage does not
 * name, whatever the browser sends. A client-side check that were the only
 * check would be no check at all.
 *
 * @param {object} stage The live stage.
 * @param {string} uid The current user's uid.
 * @return {boolean} True when it is their turn.
 */
export function isCurrentActor(stage, uid) {
	if (!stage || !uid) return false
	return (
		String(stage.assignedPerson || '') === String(uid)
		|| String(stage.substituteActor || '') === String(uid)
	)
}

/**
 * Record an action on the host object's live step, through decidiq's own
 * controller.
 *
 * The actor is NOT sent: the controller reads it off the session. Sending it
 * would let any caller sign off as anyone, which is the one thing a sign-off
 * route exists to prevent. Acting for the assignee is a different thing: the
 * actor stays the session user, and `onBehalfOf` plus a `mandate` ask
 * ApprovalStageGuard to accept them for the assignee, which it only does
 * when the mandate authorises this actor (#1397).
 *
 * @param {object} params The action.
 * @param {string} params.subject The host object's UUID.
 * @param {string} params.subjectSchema The host object's schema slug.
 * @param {number} params.step The step number.
 * @param {string} params.action The verb, e.g. `approved` or `rejected`.
 * @param {string} [params.comment] The reason, required on a rejection.
 * @param {string} [params.onBehalfOf] The assignee acted for.
 * @param {string} [params.mandate] The mandate that authorises it.
 * @return {Promise<object>} The recorded action.
 */
export async function recordAction({
	subject,
	subjectSchema,
	step,
	action,
	comment,
	onBehalfOf,
	mandate,
}) {
	const body = { subject, subjectSchema, step, action, comment: comment || '' }
	if (onBehalfOf && mandate) {
		body.onBehalfOf = onBehalfOf
		body.mandate = mandate
	}
	const res = await axios.post(
		generateUrl('/apps/decidiq/api/approval-routes/actions'),
		body,
	)
	return (res && res.data) || {}
}

/**
 * The mandates in `rows` that let `uid` act today.
 *
 * Mirrors what MandateDirectory checks on the server: status `effective`,
 * the delegate is this person (or the mandate names nobody), and today
 * falls inside the validity window. The server still decides; this only
 * keeps the leaf from offering a mandate it would refuse.
 *
 * @param {object[]} rows The mandate rows.
 * @param {string} uid The acting user.
 * @param {Date} [today] Clock override for tests.
 * @return {object[]} The usable mandates.
 */
export function usableMandates(rows, uid, today = new Date()) {
	const day = today.toISOString().slice(0, 10)
	return (rows || []).filter((row) => {
		if (String(row.status || '') !== 'effective') return false
		const delegate = String(row.delegatePerson || '')
		if (delegate !== '' && delegate !== String(uid || '')) return false
		const from = String(row.validFrom || '').slice(0, 10)
		const to = String(row.validTo || '').slice(0, 10)
		if (from !== '' && from > day) return false
		if (to !== '' && to < day) return false
		return true
	})
}

/**
 * Read the mandates `uid` may sign under, from the register the server
 * checks them against (MandateDirectory reads `bevoegdheidstoedeling`).
 *
 * @param {string} uid The acting user.
 * @param {Date} [today] Clock override for tests.
 * @return {Promise<object[]>} The usable mandates.
 */
export async function listMandates(uid, today = new Date()) {
	if (!uid) return []
	const res = await axios.get(
		generateUrl('/apps/openregister/api/objects/decidiq/bevoegdheidstoedeling'),
		{ params: { delegatePerson: uid, status: 'effective', _limit: 50 } },
	)
	const data = res && res.data
	const rows = Array.isArray(data)
		? data
		: data && Array.isArray(data.results)
			? data.results
			: []
	return usableMandates(rows, uid, today)
}

/**
 * Whether to offer "sign on behalf of" the step's assignee.
 *
 * Not the authorisation: ApprovalStageGuard decides that.
 *
 * @param {object|null} stage The live stage.
 * @param {string} uid The current user.
 * @param {object[]} mandates The user's usable mandates.
 * @return {boolean} True when someone else holds the step and a mandate exists.
 */
export function mayActOnBehalf(stage, uid, mandates) {
	if (!stage || String(stage.status || '') !== 'active') return false
	const assigned = String(stage.assignedPerson || '')
	if (assigned === '' || assigned === String(uid || '')) return false
	return Array.isArray(mandates) && mandates.length > 0
}

/**
 * Start a sign-off route on the host object, from named people.
 *
 * With `askSubstituteAfter` (a fraction of each step's term, 0 to 1) every
 * step also asks the approver's substitute once that share of its term has
 * passed; the substitute is read from the organisation record (#1397). It
 * needs a deadline, because a step without a term has no point part way
 * through it.
 *
 * @param {object} params The route.
 * @param {string} params.subject The host object's UUID.
 * @param {string} params.subjectSchema The host object's schema slug.
 * @param {string[]} params.actors The people to ask, in order.
 * @param {string} [params.deadline] One deadline for the whole route.
 * @param {string} [params.name] What to call the route.
 * @param {number} [params.askSubstituteAfter] When to ask the substitute too.
 * @return {Promise<object[]>} The stages.
 */
export async function holdRoute({
	subject,
	subjectSchema,
	actors,
	deadline,
	name,
	askSubstituteAfter,
}) {
	const substitute =
		typeof askSubstituteAfter === 'number'
		&& askSubstituteAfter > 0
		&& askSubstituteAfter <= 1
	const body = {
		subject,
		subjectSchema,
		route: {
			name: name || 'Review',
			origin: 'adhoc',
			steps: (actors || []).map((actor, index) => ({
				order: index + 1,
				stageType: 'endorsement',
				actorType: 'person',
				actor,
				...(substitute ? { askSubstituteAfter } : {}),
			})),
		},
		deadline: deadline || '',
	}
	if (substitute) {
		body.askSubstituteAfter = askSubstituteAfter
	}
	const res = await axios.post(
		generateUrl('/apps/decidiq/api/approval-routes/instantiate'),
		body,
	)
	const data = (res && res.data) || {}
	return Array.isArray(data.stages) ? data.stages : []
}

/**
 * The people named in a free-text "people to ask" field, in order.
 *
 * Commas, semicolons and line breaks separate names; blanks and repeats are
 * dropped, because holdFor() asks a person once.
 *
 * @param {string} text What was typed.
 * @return {string[]} The user names.
 */
export function parseActors(text) {
	const seen = []
	for (const part of String(text || '').split(/[,;\n]/)) {
		const name = part.trim()
		if (name !== '' && !seen.includes(name)) seen.push(name)
	}
	return seen
}
