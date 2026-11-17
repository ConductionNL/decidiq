/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A refused save reaches the widget as an error. The object store's
 * `saveObject` returns null on a refusal rather than throwing, so the sessions
 * widget's "the evening runs 18:00 to 23:00" refusal used to disappear.
 *
 * @spec openspec/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { saveOrThrow } from '../../src/utils/saveOrThrow.js'

/**
 * A store whose save answers with the given result and records the given error.
 *
 * @param {object|null} result What saveObject resolves to.
 * @param {object|null} error What getError returns.
 * @return {object} The store.
 */
function store(result, error = null) {
	return {
		saveObject: async () => result,
		getError: () => error,
	}
}

describe('saveOrThrow', () => {
	it('returns the saved object', async () => {
		await expect(saveOrThrow(store({ id: 'm1' }), 'meeting', {})).resolves.toEqual({ id: 'm1' })
	})

	it('throws the server message when the save is refused', async () => {
		const refused = store(null, { message: 'A session must fall within the evening, 18:00 to 23:00.' })
		await expect(saveOrThrow(refused, 'meeting', {})).rejects.toThrow('18:00 to 23:00')
	})

	it('throws an empty message when the refusal names no reason', async () => {
		await expect(saveOrThrow(store(null), 'meeting', {})).rejects.toThrow(Error)
	})

	it('is what the sessions widget saves a new session with', () => {
		const source = readFileSync(
			fileURLToPath(new URL('../../src/components/tabs/MeetingSessionsTab.vue', import.meta.url)),
			'utf8',
		)
		expect(source).toMatch(/await saveOrThrow\(\s*ensureRelationType\('meeting'\),\s*'meeting',/)
	})
})
