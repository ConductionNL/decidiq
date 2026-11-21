// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Impose, ratify and lift a confidentiality restriction from the app
 * (embargo-geheimhouding task 8, REQ-EMB-001 to REQ-EMB-003). The dialogs in
 * src/dialogs/Confidentiality*Dialog.vue post what this file builds to the
 * guarded endpoints of ConfidentialityRestrictionController; the server
 * decides who may and answers the refusal the dialog shows. Pure, so a unit
 * test runs it.
 */

import { generateUrl } from '@nextcloud/router'

const OBJECTS = '/apps/openregister/api/objects/decidiq'

/** The page schema an impose action stands on, mapped to the restriction's scope. */
export const SCOPE_OF_SCHEMA = {
	'digital-document': 'document',
	'agenda-item': 'item',
	decision: 'decision',
}

/** The organs that may impose a restriction, as the server accepts them. */
export const IMPOSING_ORGANS = ['body', 'chair', 'executive-board']

/**
 * The URL of one of the restriction endpoints.
 *
 * @param {'impose'|'ratify'|'lift'} action The action.
 * @param {string} [restrictionId] The restriction, for ratify and lift.
 * @return {string} The URL.
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
 */
export function restrictionUrl(action, restrictionId = '') {
	const base = '/apps/decidiq/api/confidentiality/restrictions'
	if (action === 'impose') {
		return generateUrl(`${base}/impose`)
	}

	return generateUrl(`${base}/${encodeURIComponent(String(restrictionId))}/${action}`)
}

/**
 * The OpenRegister list URL of the objects a dialog offers.
 *
 * @param {string} schema The schema slug (confidentiality-ground, governance-body, decision).
 * @return {string} The URL.
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
 */
export function optionsUrl(schema) {
	return generateUrl(`${OBJECTS}/${encodeURIComponent(schema)}?_limit=200`)
}

/**
 * The id of a row, whichever key carries it.
 *
 * @param {object} row The row.
 * @return {string} The id, or ''.
 */
function idOf(row) {
	for (const value of [row?.id, row?.uuid, row?.['@self']?.id]) {
		if (typeof value === 'string' && value !== '') return value
	}
	return ''
}

/**
 * The rows of an OpenRegister list answer, as select options.
 *
 * A ground is labelled with its name and citation, and an inactive ground is
 * left out: the server refuses it anyway. Other rows are labelled with their
 * name or title. Options are sorted by label.
 *
 * @param {object|Array} data The list answer (`{ results }` or a plain array).
 * @param {string} schema The schema slug the rows come from.
 * @return {Array<{id: string, label: string}>} The options.
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
 */
export function objectOptions(data, schema) {
	const rows = Array.isArray(data) ? data : (Array.isArray(data?.results) ? data.results : [])
	return rows
		.filter((row) => idOf(row) !== '' && !(schema === 'confidentiality-ground' && row.active === false))
		.map((row) => {
			const name = String(row.name || row.title || idOf(row))
			const label = schema === 'confidentiality-ground' && row.citation ? `${name} (${row.citation})` : name
			return { id: idOf(row), label }
		})
		.sort((one, two) => one.label.localeCompare(two.label))
}

/**
 * The body a new restriction defaults to: the one the page passes, else the
 * one the page's object names. A manifest token the page could not resolve
 * (it still starts with `@`) counts as none.
 *
 * @param {object} object The page's object.
 * @param {string} [preset] The body the page passes.
 * @return {string} The body id, or ''.
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
 */
export function defaultBodyOf(object, preset = '') {
	for (const value of [preset, object?.decidingBody, object?.governanceBody, object?.body, object?.imposedByBody]) {
		if (typeof value === 'string' && value !== '' && !value.startsWith('@')) return value
		if (value && typeof value === 'object' && idOf(value) !== '') return idOf(value)
	}
	return ''
}

/**
 * The selected option's id, from an option or a bare id.
 *
 * @param {object|string|null} choice The choice.
 * @return {string} The id, or ''.
 */
function chosen(choice) {
	if (typeof choice === 'string') return choice
	return choice && typeof choice.id === 'string' ? choice.id : ''
}

/**
 * The impose request body, or null while it is incomplete.
 *
 * @param {object} form The dialog's fields.
 * @param {string} form.schema The page's schema slug.
 * @param {string} form.target The page's object id.
 * @param {object|string|null} form.ground The ground.
 * @param {string} form.organ The imposing organ.
 * @param {object|string|null} form.body The imposing body.
 * @return {object|null} The body, or null.
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
 */
export function imposePayload({ schema, target, ground, organ, body }) {
	const scope = SCOPE_OF_SCHEMA[schema]
	const payload = {
		scope,
		target: String(target ?? ''),
		ground: chosen(ground),
		imposedBy: organ,
		imposedByBody: chosen(body),
	}

	if (!scope || !IMPOSING_ORGANS.includes(organ)) return null
	return Object.values(payload).every((value) => typeof value === 'string' && value !== '') ? payload : null
}

/**
 * The ratify request body, or null without a decision.
 *
 * @param {object|string|null} decision The ratifying decision.
 * @return {object|null} The body, or null.
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag
 */
export function ratifyPayload(decision) {
	const id = chosen(decision)
	return id === '' ? null : { ratificationDecision: id }
}

/**
 * The lift request body, or null without a decision. Empty conditions are
 * not sent.
 *
 * @param {object|string|null} decision The lifting decision.
 * @param {string} conditions The lifting conditions, free text.
 * @return {object|null} The body, or null.
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery
 */
export function liftPayload(decision, conditions = '') {
	const id = chosen(decision)
	if (id === '') return null
	const text = typeof conditions === 'string' ? conditions.trim() : ''
	return text === '' ? { dissolutionDecision: id } : { dissolutionDecision: id, liftingConditions: text }
}

/**
 * What to say when the server refuses.
 *
 * @param {object} error The axios error.
 * @param {string} fallback The text when the server said nothing.
 * @return {string} The message.
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
 */
export function refusalOf(error, fallback) {
	const message = error?.response?.data?.message
	return typeof message === 'string' && message !== '' ? message : fallback
}
