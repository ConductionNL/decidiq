/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A closed agenda item the reader may not read keeps its place on the
 * meeting's agenda as "Besloten punt" with its number only
 * (agenda-item-confidential-papers task 2). This repo's vitest cannot mount
 * a `.vue` file, so the tab's wiring is asserted against its source.
 *
 * @spec openspec/changes/archive/2026-11-26-agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-005-a-non-public-item-stays-out-of-the-pack-and-the-public-site
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { buildAgendaTree, flattenTree } from '../../src/services/agendaRules.js'
import {
	isClosedPlaceholder,
	withClosedPlaceholders,
} from '../../src/utils/closedAgendaPlaceholders.js'

function read(path) {
	return readFileSync(fileURLToPath(new URL(path, import.meta.url)), 'utf8')
}

const items = [
	{ id: 'a', orderNumber: 1, title: 'Opening' },
	{ id: 'b', orderNumber: 3, title: 'Begroting' },
	{ id: 'c', orderNumber: 5, title: 'Rondvraag' },
]

describe('closed item placeholders', () => {
	it('fill each gap with "Besloten punt" and the number only', () => {
		const rows = withClosedPlaceholders(
			items,
			[{ orderNumber: 2, parentItem: null }, { orderNumber: 4 }],
			'Besloten punt',
		)
		const ordered = flattenTree(buildAgendaTree(rows))
		expect(ordered.map((r) => [r.orderNumber, r.title])).toEqual([
			[1, 'Opening'],
			[2, 'Besloten punt'],
			[3, 'Begroting'],
			[4, 'Besloten punt'],
			[5, 'Rondvraag'],
		])
		const placeholder = ordered[1]
		expect(isClosedPlaceholder(placeholder)).toBe(true)
		expect(placeholder.public).toBe(false)
		expect(Object.keys(placeholder).sort()).toEqual([
			'closedPlaceholder',
			'id',
			'orderNumber',
			'parentItem',
			'public',
			'title',
		])
	})

	it('nest a closed sub-item under the readable parent the server named', () => {
		const rows = withClosedPlaceholders(
			items,
			[{ orderNumber: 4, parentItem: 'b' }],
			'Besloten punt',
		)
		const tree = buildAgendaTree(rows)
		expect(tree.map((n) => n.item.id)).toEqual(['a', 'b', 'c'])
		expect(tree[1].children.map((c) => c.orderNumber)).toEqual([4])
	})

	it('never double a number the reader already holds, nor take junk', () => {
		const rows = withClosedPlaceholders(
			items,
			[
				{ orderNumber: 3 },
				{ orderNumber: 'x' },
				null,
				{ orderNumber: 6 },
				{ orderNumber: 6 },
			],
			'Besloten punt',
		)
		expect(rows.filter(isClosedPlaceholder).map((r) => r.orderNumber)).toEqual([
			6,
		])
	})

	it('leave the agenda as it is without an answer', () => {
		expect(withClosedPlaceholders(items, null, 'Besloten punt')).toEqual(items)
		expect(withClosedPlaceholders(null, [], 'Besloten punt')).toEqual([])
		expect(isClosedPlaceholder(items[0])).toBe(false)
		expect(isClosedPlaceholder(null)).toBe(false)
	})
})

describe('the meeting agenda tab', () => {
	const tab = read('../../src/components/tabs/MeetingAgendaTab.vue')

	it('reads the closed items of the meeting and labels them in the catalogue', () => {
		expect(tab).toContain(
			'/apps/decidiq/api/meetings/${this.objectId}/closed-items',
		)
		expect(tab).toContain("this.t('decidiq', 'Closed item')")
		expect(tab).toContain('withClosedPlaceholders(')
	})

	it('offers no edit, actions or reordering on a placeholder', () => {
		expect(tab).toMatch(
			/async openEdit\(row\) \{\s*if \(isClosedPlaceholder\(row\)\) return/,
		)
		expect(tab).toContain('v-if="!isClosedPlaceholder(row)"')
		expect(tab).toContain(':draggable="canManage && !isClosedPlaceholder(row)"')
		expect(tab).toContain('buildAgendaTree(this.orderableRows())')
	})

	it('has Dutch for the label', () => {
		const nl = JSON.parse(read('../../l10n/nl.json')).translations
		expect(nl['Closed item']).toBe('Besloten punt')
	})
})
