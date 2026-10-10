/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Ready-made reasons a host offers on the approval chain leaf.
 *
 * Dossiq's starter-content templates (decision 156) include approval reasons
 * ("Akkoord, conform advies"). The leaf runs its own bundle, so the host
 * cannot slot a picker into it; it passes `reasonTemplates` instead and the
 * leaf shows them above the reason field. The widget cannot be mounted here
 * (no SFC transform), so the mapping lives in approvalChainLink.js.
 *
 * @spec openspec/changes/approval-reason-templates/specs/approval-routes/spec.md
 */

import { describe, expect, it, vi } from 'vitest'
import { reasonTemplateOptions } from '../../src/integrations/approvalChainLink.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

describe('reasonTemplateOptions', () => {
	it('maps what the host gave into options', () => {
		expect(
			reasonTemplateOptions([
				{ id: 't1', name: 'Akkoord', body: 'Akkoord, conform advies.' },
				{ id: 't2', body: 'Terug: de motivering ontbreekt.' },
			]),
		).toEqual([
			{ id: 't1', name: 'Akkoord', body: 'Akkoord, conform advies.' },
			{ id: 't2', name: 't2', body: 'Terug: de motivering ontbreekt.' },
		])
	})

	it('drops an entry without an id or without a body', () => {
		expect(
			reasonTemplateOptions([
				{ name: 'No id', body: 'x' },
				{ id: 't3', name: 'Empty', body: '   ' },
				null,
				{ id: 't4', name: 'Ok', body: 'Akkoord.' },
			]).map((option) => option.id),
		).toEqual(['t4'])
	})

	it('is an empty offer for anything that is not a list', () => {
		expect(reasonTemplateOptions(undefined)).toEqual([])
		expect(reasonTemplateOptions({ items: [] })).toEqual([])
		expect(reasonTemplateOptions('Akkoord')).toEqual([])
	})
})
