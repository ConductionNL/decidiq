/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden run of copying agenda items from an earlier meeting and from a
 * template: which items are kept, their order, their fields and their order
 * numbers, pinned before the copy took one request object.
 *
 * @spec exclude golden test of the agenda copy, no behaviour of its own
 */
import { describe, expect, it } from 'vitest'
import { itemsFromMeeting, itemsFromTemplate } from '../../src/utils/agendaCopy.js'

const EARLIER = [
	{
		id: 'b',
		meeting: { id: 'old' },
		title: 'Second',
		orderNumber: '2',
		typeFields: { a: 1 },
	},
	{ id: 'a', meeting: 'old', title: 'First', orderNumber: 1, description: '' },
	{ id: 'x', meeting: { uuid: 'old' }, title: 'Unnumbered', orderNumber: 'n/a' },
	{ id: 'blank', meeting: 'old', title: '   ', orderNumber: 3 },
	{ id: 'other', meeting: 'elsewhere', title: 'Not ours', orderNumber: 0 },
]

describe('itemsFromMeeting', () => {
	it('copies every item of the earlier meeting by order number, after the given start', () => {
		expect(itemsFromMeeting(EARLIER, 'old', 'new', 7, null)).toMatchSnapshot()
	})

	it('starts at 1 and copies everything by default', () => {
		const items = itemsFromMeeting(EARLIER, 'old', 'new')
		expect(items.map((i) => [i.title, i.orderNumber])).toEqual([
			['Unnumbered', 1],
			['First', 2],
			['Second', 3],
		])
	})

	it('copies only the picked items, by object or plain id', () => {
		const items = itemsFromMeeting(EARLIER, 'old', 'new', 4, ['b', 'other'])
		expect(items).toEqual([
			{
				itemType: 'informational',
				title: 'Second',
				typeFields: { a: 1 },
				meeting: 'new',
				orderNumber: 4,
			},
		])
		expect(itemsFromMeeting(null, 'old', 'new', 1, ['b'])).toEqual([])
	})
})

describe('itemsFromTemplate', () => {
	it('numbers the template items with titles, without type fields', () => {
		expect(
			itemsFromTemplate(
				{
					items: [
						{ title: 'T', itemType: 'decision', typeFields: { b: 2 } },
						{ title: '' },
					],
				},
				'new',
				3,
			),
		).toEqual([
			{ itemType: 'decision', title: 'T', meeting: 'new', orderNumber: 3 },
		])
		expect(itemsFromTemplate(null, 'new')).toEqual([])
	})
})
