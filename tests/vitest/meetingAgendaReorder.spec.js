// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Reordering the agenda from the meeting page (agenda-meeting-page-item-tools,
// REQ-AMP-002): a move keeps sub-items with their parent, the new order is the
// id list the reorder endpoint takes, and only chair, secretary or admin may
// reorder.
//
// @spec openspec/specs/agenda-management/spec.md#requirement-req-amp-002-a-chair-or-secretary-reorders-the-agenda-on-the-meeting-page

import { describe, expect, it } from 'vitest'
import {
	buildAgendaTree,
	canManageAgenda,
	dropAgendaItem,
	moveAgendaItem,
} from '../../src/services/agendaRules.js'

const items = [
	{ id: 'a', title: 'Opening', orderNumber: 1 },
	{ id: 'b', title: 'Minutes', orderNumber: 2 },
	{ id: 'b1', title: 'Minutes of 1 Sep', orderNumber: 3, parentItem: 'b' },
	{ id: 'b2', title: 'Minutes of 8 Sep', orderNumber: 4, parentItem: 'b' },
	{ id: 'c', title: 'Budget', orderNumber: 5 },
]

describe('moveAgendaItem', () => {
	it('moves a top-level item up and carries its sub-items along', () => {
		expect(moveAgendaItem(buildAgendaTree(items), 'b', -1)).toEqual([
			'b',
			'b1',
			'b2',
			'a',
			'c',
		])
	})

	it('moves a top-level item down past a parent with sub-items', () => {
		expect(moveAgendaItem(buildAgendaTree(items), 'a', 1)).toEqual([
			'b',
			'b1',
			'b2',
			'a',
			'c',
		])
	})

	it('moves a sub-item within its parent only', () => {
		expect(moveAgendaItem(buildAgendaTree(items), 'b2', -1)).toEqual([
			'a',
			'b',
			'b2',
			'b1',
			'c',
		])
	})

	it('returns null at the edge, so nothing is saved', () => {
		expect(moveAgendaItem(buildAgendaTree(items), 'a', -1)).toBeNull()
		expect(moveAgendaItem(buildAgendaTree(items), 'c', 1)).toBeNull()
		expect(moveAgendaItem(buildAgendaTree(items), 'b1', -1)).toBeNull()
		expect(moveAgendaItem(buildAgendaTree(items), 'missing', 1)).toBeNull()
	})
})

describe('dropAgendaItem', () => {
	it('puts a dragged item in the place of the item it is dropped on', () => {
		expect(dropAgendaItem(buildAgendaTree(items), 'c', 'a')).toEqual([
			'c',
			'a',
			'b',
			'b1',
			'b2',
		])
	})

	it('drops downwards after the target', () => {
		expect(dropAgendaItem(buildAgendaTree(items), 'a', 'c')).toEqual([
			'b',
			'b1',
			'b2',
			'c',
			'a',
		])
	})

	it('ignores a drop across levels or on itself', () => {
		expect(dropAgendaItem(buildAgendaTree(items), 'b1', 'a')).toBeNull()
		expect(dropAgendaItem(buildAgendaTree(items), 'a', 'a')).toBeNull()
	})
})

describe('canManageAgenda', () => {
	it('allows chair, secretary and admin only', () => {
		expect(
			canManageAgenda({ chair: true, secretary: false, admin: false }),
		).toBe(true)
		expect(
			canManageAgenda({ chair: false, secretary: true, admin: false }),
		).toBe(true)
		expect(
			canManageAgenda({ chair: false, secretary: false, admin: true }),
		).toBe(true)
		expect(
			canManageAgenda({ chair: false, secretary: false, admin: false }),
		).toBe(false)
		expect(canManageAgenda(null)).toBe(false)
	})
})
