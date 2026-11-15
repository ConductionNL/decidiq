/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden run of the client-side mandate filter: status, delegate and the
 * validity window, including its boundary days, pinned before the filter
 * moved into named predicates.
 *
 * @spec exclude golden test of the mandate filter, no behaviour of its own
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))

const { usableMandates } =
	await import('../../src/integrations/approvalChainLink.js')

const TODAY = new Date('2026-09-27T12:00:00Z')

describe('usableMandates boundaries', () => {
	it('keeps the boundary days, drops the day before and after, and reads blanks as open', () => {
		const rows = [
			{
				id: 'from-today',
				status: 'effective',
				validFrom: '2026-09-27T08:00:00',
			},
			{ id: 'to-today', status: 'effective', validTo: '2026-09-27' },
			{ id: 'from-tomorrow', status: 'effective', validFrom: '2026-09-28' },
			{ id: 'to-yesterday', status: 'effective', validTo: '2026-09-26' },
			{ id: 'no-status' },
			{ id: 'draft', status: 'draft' },
			{ id: 'nobody', status: 'effective', delegatePerson: '' },
			{ id: 'numeric', status: 'effective', delegatePerson: 42 },
			{
				id: 'null-window',
				status: 'effective',
				validFrom: null,
				validTo: null,
			},
		]
		expect(usableMandates(rows, 'bob', TODAY).map((m) => m.id)).toEqual([
			'from-today',
			'to-today',
			'nobody',
			'null-window',
		])
		expect(usableMandates(rows, 42, TODAY).map((m) => m.id)).toContain('numeric')
		expect(usableMandates(rows, null, TODAY).map((m) => m.id)).not.toContain(
			'numeric',
		)
		expect(usableMandates(null, 'bob', TODAY)).toEqual([])
	})
})
