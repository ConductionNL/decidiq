/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for formalities (hamerstukken, age-07). An agenda item is
 * marked `isFormality` by the chair or secretary on the meeting's Agenda
 * widget; the live screen lists the formalities not yet adopted and adopts
 * them together. It used to look for a `hamerstuk` tag nothing could set.
 * This repo's vitest cannot mount a `.vue` file, so the wiring is asserted
 * against the sources.
 *
 * @spec openspec/changes/agenda-formalities-hamerstukken/specs/agenda-live-management/spec.md#requirement-req-afh-001-formalities-are-marked-and-adopted-together
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	formalityUrl,
	isPendingFormality,
	pendingFormalities,
} from '../../src/utils/formalities.js'

function read(path) {
	return readFileSync(fileURLToPath(new URL(path, import.meta.url)), 'utf8')
}

const items = [
	{ id: 'i3', orderNumber: 3, isFormality: true },
	{ id: 'i1', orderNumber: 1 },
	{ id: 'i4', orderNumber: 4, tags: ['hamerstuk'] },
	{ id: 'i7', orderNumber: 7, isFormality: true },
	{
		id: 'i8',
		orderNumber: 8,
		isFormality: true,
		formalityOutcome: 'adopted-without-debate',
	},
]

describe('formalities', () => {
	it('are the marked items not yet adopted, in agenda order', () => {
		expect(pendingFormalities(items).map((i) => i.id)).toEqual([
			'i3',
			'i4',
			'i7',
		])
	})

	it('stop being pending once adopted', () => {
		expect(isPendingFormality(items[4])).toBe(false)
		expect(isPendingFormality(items[1])).toBe(false)
	})

	it('are marked through the chair-only endpoint of the meeting', () => {
		expect(formalityUrl('meet-1', 'i4')).toBe(
			'/apps/decidiq/api/agendas/meet-1/items/i4/formality',
		)
	})
})

describe('the screens', () => {
	it('let the chair or secretary mark an item on the meeting agenda', () => {
		const tab = read('../../src/components/tabs/MeetingAgendaTab.vue')
		expect(tab).toContain("t('decidiq', 'Mark as formality')")
		expect(tab).toContain('formalityUrl(')
		expect(tab).toMatch(/visible: \(row\) =>\s*this\.canManage/)
	})

	it('adopt the pending formalities on the live screen, not a tag', () => {
		const live = read('../../src/views/LiveMeeting.vue')
		expect(live).toContain('pendingFormalities(this.allItems)')
		expect(live).not.toContain("includes('hamerstuk')")
		expect(live).toContain('formalityUrl(')
	})
})
