// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The competence routes (bodies-board-composition-skills-and-diversity).
 * The two competence schemas keep their writes closed on the object API, so
 * every write goes through MemberCompetenceController, which checks the
 * body's signatory scope.
 *
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
 */

import { generateUrl } from '@nextcloud/router'

/**
 * The path of a competence route.
 *
 * @param {'competences'|'member-competences'} kind Which schema.
 * @param {string} id The object, '' to create one.
 * @param {string} action A trailing action such as confirm, '' for none.
 * @return {string} The app-relative path.
 *
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
 */
export function competencePath(kind, id = '', action = '') {
	return ['/apps/decidiq/api', kind, id, action].filter(Boolean).join('/')
}

/**
 * One request to a competence route; a refusal throws its message.
 *
 * @param {string} method The HTTP method.
 * @param {string} path The app-relative path.
 * @param {object|null} body The JSON body.
 * @return {Promise<object>} The response body.
 *
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md#requirement-req-bcs-002-a-members-competences-are-recorded-and-confirmed
 */
export async function competenceRequest(method, path, body = null) {
	const response = await fetch(generateUrl(path), {
		method,
		headers: {
			requesttoken: window.OC?.requestToken,
			'Content-Type': 'application/json',
		},
		body: body === null ? undefined : JSON.stringify(body),
	})
	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw new Error(data.message || response.statusText)
	}
	return data
}
