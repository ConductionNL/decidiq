// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The per-item minutes editor on the minutes page (agenda-meeting-page-item-tools,
// REQ-AMP-005): the widget shows the same items the live screen shows (regular
// items, not hamerstukken, in agenda order), the meeting's own participants, and
// edits the minutes record the page is about rather than any draft of the meeting.
//
// @spec openspec/specs/resolution-minutes/spec.md#requirement-req-amp-005-the-minutes-page-carries-the-per-item-minutes-editor

import { describe, expect, it } from 'vitest'
import {
	meetingParticipants,
	pickMinutesRecord,
	regularAgendaItems,
} from '../../src/components/minutesEditor/minutesEditor.js'

describe('regularAgendaItems', () => {
	it('drops hamerstukken and sorts by order number', () => {
		const items = [
			{ id: 'c', title: 'Any other business', orderNumber: 3 },
			{ id: 'h', title: 'Lease renewal', orderNumber: 2, tags: ['hamerstuk'] },
			{ id: 'a', title: 'Opening', orderNumber: 1 },
			{ id: 'b', title: 'Budget', orderNumber: 2 },
		]
		expect(regularAgendaItems(items).map((i) => i.id)).toEqual(['a', 'b', 'c'])
		expect(regularAgendaItems(null)).toEqual([])
	})
})

describe('meetingParticipants', () => {
	it('keeps only the participants related to the meeting', () => {
		const list = [
			{ id: 'p1', '@self': { relations: { meeting: 'm1' } } },
			{ id: 'p2', relations: { meeting: 'm1' } },
			{ id: 'p3', '@self': { relations: { meeting: 'm2' } } },
			{ id: 'p4' },
		]
		expect(meetingParticipants(list, 'm1').map((p) => p.id)).toEqual(['p1', 'p2'])
	})
})

describe('pickMinutesRecord', () => {
	const list = [
		{ id: 'draft-1', lifecycle: 'draft' },
		{ id: 'approved-1', lifecycle: 'approved' },
	]

	it('picks the record the page is about when an id is given', () => {
		expect(pickMinutesRecord(list, 'approved-1')?.id).toBe('approved-1')
	})

	it('falls back to the draft, as the live screen always did', () => {
		expect(pickMinutesRecord(list, '')?.id).toBe('draft-1')
		expect(pickMinutesRecord([], '')).toBeNull()
	})
})
