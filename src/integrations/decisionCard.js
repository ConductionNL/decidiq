// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The decisions leaf as a card on a host's page: each decision as a row with
// its route drawn as a step bar and, when the register says so, a notice that
// the person who drafted it may take it. Pure functions here; the reads are
// at the bottom and go through the shared OpenRegister and decidiq APIs.

import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'

/**
 * Up to two initials of a name, for the row's avatar.
 *
 * @param {string} name The name.
 * @return {string} The initials, or '' for no name.
 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
 */
export function initialsOf(name) {
	const parts = String(name || '').trim().split(/\s+/).filter(Boolean)
	if (parts.length === 0) {
		return ''
	}
	const last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : ''
	return (parts[0].charAt(0) + last).toUpperCase()
}

/**
 * Who drafted a decision, by name: its proposer, else its owner's display
 * name, else the owner's account.
 *
 * @param {object} decision The decision.
 * @param {Object<string, string>} names Display names by account.
 * @return {string} The name, or ''.
 * @spec openspec/changes/decisions-leaf-drafter-name/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-names-the-drafter-by-display-name
 */
export function drafterName(decision, names) {
	const proposer = String(decision?.proposer || '').trim()
	if (proposer !== '') {
		return proposer
	}
	const owner = String(decision?.['@self']?.owner || '').trim()
	return (names && names[owner]) || owner
}

/**
 * The line under a decision's title: its state, its outcome when it has one,
 * and who drafted it when.
 *
 * @param {{state: string, outcome?: string, drafter?: string, date?: string}} parts The parts, already in words.
 * @param {(drafter: string, date: string) => string} [drafted] Words "drafted by {drafter} on {date}".
 * @return {string} The line.
 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
 */
export function decisionMetaLine(parts, drafted = (drafter, date) => `drafted by ${drafter} on ${date}`) {
	const line = [parts.state]
	if (parts.outcome) {
		line.push(parts.outcome)
	}
	if (parts.drafter && parts.date) {
		line.push(drafted(parts.drafter, parts.date))
	}
	return line.filter(Boolean).join(' · ')
}

/**
 * A decision's route as steps, in route order: a decided or skipped stage is
 * done, the active one is current, the rest are still to come. A done step
 * says when and by whom, the current one who it waits on, one to come its note.
 *
 * @param {Array<object>|null} stages The decision's DecisionStage records.
 * @param {Object<string, string>} people Person names by id.
 * @param {(iso: string) => string} formatDate Words for a date.
 * @param {string} notYet Words for "not yet".
 * @return {Array<{key: string, label: string, state: 'done'|'current'|'todo', line: string}>} The steps.
 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-draws-each-decision-with-its-route
 */
export function stageSteps(stages, people, formatDate, notYet) {
	if (!Array.isArray(stages)) {
		return []
	}
	return [...stages]
		.filter((stage) => stage && typeof stage === 'object')
		.sort((a, b) => (Number(a.sequence) || 0) - (Number(b.sequence) || 0))
		.map((stage, index) => {
			const status = String(stage.status || 'pending')
			const person = (people && people[stage.assignedPerson]) || ''
			let state = 'todo'
			let line = typeof stage.note === 'string' ? stage.note : ''
			if (status === 'decided' || status === 'skipped') {
				state = 'done'
				line = [stage.decidedAt ? formatDate(String(stage.decidedAt)) : '', person].filter(Boolean).join(', ')
			} else if (status === 'active') {
				state = 'current'
				line = [person, notYet].filter(Boolean).join(', ')
			}
			return {
				key: String(stage.id ?? `stage-${index}`),
				label: String(stage.label || ''),
				state,
				line,
			}
		})
}

/**
 * The mandate notice for a decision, from the authority answer: only when the
 * register says the drafter may take it.
 *
 * @param {object|null} answer The `/api/decisions/{id}/authority` answer.
 * @param {string} drafter The drafter's name.
 * @return {{tone: string, drafter: string, allocation: string, restrictions: string}|null} The notice, or null.
 * @spec openspec/changes/decisions-leaf-board-card/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-says-whether-the-drafter-may-take-the-decision
 */
export function mandateNotice(answer, drafter) {
	if (!answer || answer.authorised !== true) {
		return null
	}
	return { tone: 'success', drafter, allocation: String(answer.subject ?? ''), restrictions: String(answer.restrictions ?? '') }
}

/**
 * The route stages of a decision, in any order.
 *
 * @param {string} decisionId The decision's id.
 * @return {Promise<Array<object>>} The stages, or [] when they cannot be read.
 */
export async function listDecisionStages(decisionId) {
	if (!decisionId) {
		return []
	}
	try {
		const res = await axios.get(generateUrl('/apps/openregister/api/objects/decidiq/decision-stage'), {
			params: { decision: decisionId, _limit: 50 },
		})
		const data = res && res.data
		return Array.isArray(data?.results) ? data.results : (Array.isArray(data) ? data : [])
	} catch {
		return []
	}
}

/**
 * Person names by id, for the people a set of stages names.
 *
 * @param {Array<string>} ids Person ids.
 * @return {Promise<Object<string, string>>} The names; an unreadable person is left out.
 */
export async function personNames(ids) {
	const unique = [...new Set((ids || []).filter(Boolean).map(String))]
	const entries = await Promise.all(unique.map(async (id) => {
		try {
			const res = await axios.get(generateUrl(`/apps/openregister/api/objects/decidiq/person/${encodeURIComponent(id)}`))
			const name = res?.data?.name
			return typeof name === 'string' && name !== '' ? [id, name] : null
		} catch {
			return null
		}
	}))
	return Object.fromEntries(entries.filter(Boolean))
}

/**
 * Whether a decision's drafter may take it, from decidiq's register.
 *
 * @param {string} decisionId The decision's id.
 * @return {Promise<object|null>} The answer, or null when it cannot be read.
 */
export async function decisionAuthority(decisionId) {
	if (!decisionId) {
		return null
	}
	try {
		const res = await axios.get(generateUrl(`/apps/decidiq/api/decisions/${encodeURIComponent(decisionId)}/authority`))
		return res?.data && typeof res.data === 'object' ? res.data : null
	} catch {
		return null
	}
}

/**
 * Display names by account, from Nextcloud's user directory; an account that
 * cannot be read is left out, so the caller shows the account itself.
 *
 * @param {Array<string>} uids Account ids.
 * @return {Promise<Object<string, string>>} The names.
 * @spec openspec/changes/decisions-leaf-drafter-name/specs/decidesk-contract-decision-hub/spec.md#requirement-the-decisions-leaf-names-the-drafter-by-display-name
 */
export async function userDisplayNames(uids) {
	const unique = [...new Set((uids || []).filter(Boolean).map(String))]
	const entries = await Promise.all(unique.map(async (uid) => {
		try {
			const res = await axios.get(generateOcsUrl('cloud/users/{userId}', { userId: uid }), {
				headers: { 'OCS-APIRequest': 'true' },
				params: { format: 'json' },
			})
			const name = res?.data?.ocs?.data?.displayname
			return typeof name === 'string' && name !== '' ? [uid, name] : null
		} catch {
			return null
		}
	}))
	return Object.fromEntries(entries.filter(Boolean))
}
