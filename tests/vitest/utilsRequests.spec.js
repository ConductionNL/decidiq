/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the request and URL helpers in src/utils that no spec
 * reached: the competence and seats fetch wrappers (token, JSON body,
 * refusal messages), the case-system and subscription URLs, and the
 * decision-type vocabulary read and write.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

import { apiUrl, recordsUrl } from '../../src/utils/caseSystem.js'
import { competencePath, competenceRequest } from '../../src/utils/competenceApi.js'
import { loadDecisionTypes, saveDecisionTypes } from '../../src/utils/decisionTypeSettings.js'
import { seatsRequest } from '../../src/utils/meetingSeats.js'
import { bodiesUrl, listUrl, subscriptionUrl } from '../../src/utils/publicationSubscriptions.js'

/**
 * A fetch double answering one response.
 *
 * @param {object} response The ok flag, status text and JSON (or a JSON failure).
 * @return {Function} The double.
 */
function answer({ ok = true, statusText = 'OK', json = {}, badJson = false }) {
	return vi.fn(async () => ({
		ok,
		statusText,
		json: badJson ? async () => { throw new SyntaxError('no json') } : async () => json,
	}))
}

beforeEach(() => {
	globalThis.window = { OC: { requestToken: 'tok' } }
})

afterEach(() => {
	vi.unstubAllGlobals()
	delete globalThis.window
})

describe('competenceApi', () => {
	it('builds paths from the non-empty parts', () => {
		expect(competencePath('mandates')).toBe('/apps/decidiq/api/mandates')
		expect(competencePath('mandates', 'm1', 'revoke')).toBe('/apps/decidiq/api/mandates/m1/revoke')
		expect(competencePath('mandates', '', 'check')).toBe('/apps/decidiq/api/mandates/check')
	})

	it('sends the token and a JSON body, and returns the data', async () => {
		const fetchDouble = answer({ json: { id: 1 } })
		vi.stubGlobal('fetch', fetchDouble)

		expect(await competenceRequest('POST', '/apps/decidiq/api/mandates', { a: 1 })).toEqual({ id: 1 })
		expect(fetchDouble).toHaveBeenCalledWith('/index.php/apps/decidiq/api/mandates', {
			method: 'POST',
			headers: { requesttoken: 'tok', 'Content-Type': 'application/json' },
			body: '{"a":1}',
		})

		await competenceRequest('GET', '/p')
		expect(fetchDouble.mock.calls[1][1].body).toBeUndefined()
	})

	it('throws the server message, or the status text without one', async () => {
		vi.stubGlobal('fetch', answer({ ok: false, statusText: 'Forbidden', json: { message: 'Not your body' } }))
		await expect(competenceRequest('DELETE', '/p')).rejects.toThrow('Not your body')

		vi.stubGlobal('fetch', answer({ ok: false, statusText: 'Bad Gateway', badJson: true }))
		await expect(competenceRequest('DELETE', '/p')).rejects.toThrow('Bad Gateway')

		vi.stubGlobal('fetch', answer({ badJson: true }))
		expect(await competenceRequest('GET', '/p')).toEqual({})
	})
})

describe('seatsRequest', () => {
	it('targets the encoded meeting route and reports refusals', async () => {
		const fetchDouble = answer({ json: { seats: [] } })
		vi.stubGlobal('fetch', fetchDouble)

		expect(await seatsRequest('PUT', 'm/1', 'substitutions/s1/end', { at: 'now' })).toEqual({ seats: [] })
		expect(fetchDouble.mock.calls[0][0]).toBe('/index.php/apps/decidiq/api/meetings/m%2F1/substitutions/s1/end')
		expect(fetchDouble.mock.calls[0][1]).toMatchObject({ method: 'PUT', body: '{"at":"now"}' })

		vi.stubGlobal('fetch', answer({ ok: false, statusText: 'Conflict', json: { message: 'Seat taken' } }))
		await expect(seatsRequest('POST', 'm1', 'seats')).rejects.toThrow('Seat taken')
	})
})

describe('URLs', () => {
	it('builds the case-system and subscription URLs with encoding', () => {
		expect(apiUrl('/case-exchange')).toBe('/index.php/apps/decidiq/api/case-exchange')
		expect(recordsUrl('m 1')).toBe('/index.php/apps/openregister/api/objects/decidiq/case-exchange-record?meeting=m%201&_limit=100')
		expect(listUrl('ann@x')).toBe('/index.php/apps/openregister/api/objects/decidiq/publication-subscription?subscriberUserId=ann%40x&_limit=100')
		expect(subscriptionUrl()).toBe('/index.php/apps/openregister/api/objects/decidiq/publication-subscription')
		expect(subscriptionUrl('s/1')).toBe('/index.php/apps/openregister/api/objects/decidiq/publication-subscription/s%2F1')
		expect(bodiesUrl()).toBe('/index.php/apps/openregister/api/objects/decidiq/governance-body?_limit=200')
	})
})

describe('decision type vocabulary', () => {
	it('reads the types in force, empty when the answer has none', async () => {
		http.get.mockResolvedValueOnce({ data: { types: ['besluit', 'advies'] } })
		expect(await loadDecisionTypes()).toEqual(['besluit', 'advies'])
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/decidiq/api/v1/decision-types')

		http.get.mockResolvedValueOnce({ data: {} })
		expect(await loadDecisionTypes()).toEqual([])
	})

	it('saves the types and returns what the server kept, or what was sent', async () => {
		http.put.mockResolvedValueOnce({ data: { types: ['besluit'] } })
		expect(await saveDecisionTypes(['besluit', ''])).toEqual(['besluit'])
		expect(http.put).toHaveBeenCalledWith('/index.php/apps/decidiq/api/settings/decision-types', { types: ['besluit', ''] })

		http.put.mockResolvedValueOnce({ data: null })
		expect(await saveDecisionTypes(['x'])).toEqual(['x'])
	})
})
