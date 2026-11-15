// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// jsonRequests — the JSON POST and PUT calls the relation tabs send to
// decidiq's own controllers, and the one way a refusal is read: the server's
// message, thrown, so the caller's catch shows it or its own fallback.

import { generateUrl } from '@nextcloud/router'

/**
 * The headers of a JSON request carrying the session's request token.
 *
 * @return {object} The headers.
 */
function jsonHeaders() {
	return {
		'Content-Type': 'application/json',
		Accept: 'application/json',
		requesttoken: OC.requestToken,
	}
}

/**
 * POST to an app path, without a body.
 *
 * @param {string} path The app path, before generateUrl.
 * @return {Promise<Response>} The response.
 */
export function postJson(path) {
	return fetch(generateUrl(path), { method: 'POST', headers: jsonHeaders() })
}

/**
 * PUT a JSON body to an app path.
 *
 * @param {string} path The app path, before generateUrl.
 * @param {object} body The body, serialised as JSON.
 * @return {Promise<Response>} The response.
 */
export function putJson(path, body) {
	return fetch(generateUrl(path), {
		method: 'PUT',
		headers: jsonHeaders(),
		body: JSON.stringify(body),
	})
}

/**
 * Pass an accepted response through; throw a refused one with the server's
 * message, or an empty message when it gave none or its body is unreadable.
 *
 * @param {Response} response The response.
 * @return {Promise<Response>} The accepted response.
 * @throws {Error} When the server refused.
 */
export async function acceptedOrThrow(response) {
	if (response.ok) return response
	const payload = await response.json().catch(() => ({}))
	throw new Error(payload?.message || '')
}
