/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The decision type vocabulary as the admin settings page edits it.
 *
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-009-the-decisiontype-vocabulary-is-configuration-with-one-authority
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	saveDecisionTypes,
	textFromTypes,
	typesFromText,
} from '../../src/utils/decisionTypeSettings.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), put: vi.fn() },
}))

describe('typesFromText', () => {
	it('reads one type per line, trimmed, without blanks or repeats', () => {
		expect(typesFromText(' motion\n\nadvice\r\nmotion\n  ')).toEqual([
			'motion',
			'advice',
		])
	})

	it('reads nothing from an empty field', () => {
		expect(typesFromText('')).toEqual([])
		expect(typesFromText(undefined)).toEqual([])
	})

	it('round-trips through the text field', () => {
		const types = ['motion', 'subsidie-besluit']
		expect(typesFromText(textFromTypes(types))).toEqual(types)
	})
})

describe('saveDecisionTypes', () => {
	beforeEach(() => {
		axios.put.mockReset()
	})

	it('sends the list to the admin endpoint and answers what the server stored', async () => {
		axios.put.mockResolvedValue({ data: { types: ['motion'] } })
		expect(await saveDecisionTypes(['motion', 'motion'])).toEqual(['motion'])
		expect(axios.put.mock.calls[0][0]).toContain(
			'/apps/decidiq/api/settings/decision-types',
		)
		expect(axios.put.mock.calls[0][1]).toEqual({ types: ['motion', 'motion'] })
	})

	it('passes a refusal on, so the page can show the reason', async () => {
		axios.put.mockRejectedValue({
			response: { data: { message: 'Keep at least one decision type.' } },
		})
		await expect(saveDecisionTypes([])).rejects.toMatchObject({
			response: { data: { message: 'Keep at least one decision type.' } },
		})
	})
})
