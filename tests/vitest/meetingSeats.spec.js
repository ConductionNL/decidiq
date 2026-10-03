/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the seats panel of the live meeting (bod-18): the seat rows
 * in seat order, a substituted seat naming who it substitutes for, and who may
 * take a seat. This repo's vitest cannot mount a `.vue` file, so the panel's
 * wiring is asserted against the sources.
 *
 * @spec openspec/changes/bodies-substitute-mandate-swap/specs/meeting-attendees/spec.md#requirement-req-msw-001-the-chair-or-secretary-swaps-a-member-for-a-substitute-during-a-meeting
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { seatRows, substituteOptions } from '../../src/utils/meetingSeats.js'

const source = (path) => readFileSync(fileURLToPath(new URL(`../../src/${path}`, import.meta.url)), 'utf8')

const participants = [
	{ id: 'p-kaya', displayName: 'S. Kaya', role: 'member', party: 'D66', seatNumber: 4 },
	{ id: 'p-bos', displayName: 'H. Bos', role: 'member', party: 'VVD', seatNumber: 3 },
	{ id: 'p-visser', displayName: 'R. Visser', role: 'chair', party: 'CDA', seatNumber: 1 },
	{ id: 'p-dewit', displayName: 'A. de Wit', role: 'observer', party: 'VVD' },
	{ id: 'p-smit', displayName: 'J. Smit', role: 'observer', party: 'PvdA' },
]
const active = { id: 'sub-1', outgoingParticipant: 'p-bos', incomingParticipant: 'p-dewit', seatNumber: 3, party: 'VVD', startedAt: '2026-03-04T20:15:00+01:00' }

describe('seatRows', () => {
	it('lists the voting seats in seat order, observers left out', () => {
		const rows = seatRows({ participants, substitutions: [] })
		expect(rows.map((r) => [r.seat, r.name])).toEqual([[1, 'R. Visser'], [3, 'H. Bos'], [4, 'S. Kaya']])
		expect(rows.map((r) => r.canSwap)).toEqual([false, true, true])
	})

	it('puts the substitute in the seat with "substitute for" the member', () => {
		const rows = seatRows({ participants, substitutions: [active] })
		const seat3 = rows.find((r) => r.seat === 3)
		expect(seat3).toMatchObject({ id: 'p-dewit', name: 'A. de Wit', party: 'VVD', substituteFor: 'H. Bos', canSwap: false })
		expect(seat3.substitution.id).toBe('sub-1')
	})

	it('gives the seat back once the substitution ended, and keeps nothing of it on the row', () => {
		const rows = seatRows({ participants, substitutions: [{ ...active, endedAt: '2026-03-04T21:40:00+01:00' }] })
		expect(rows.find((r) => r.seat === 3)).toMatchObject({ id: 'p-bos', substitution: null, canSwap: true })
	})
})

describe('substituteOptions', () => {
	it('offers the non-voting participants who hold no seat yet', () => {
		expect(substituteOptions({ participants, substitutions: [] }).map((o) => o.id)).toEqual(['p-dewit', 'p-smit'])
		expect(substituteOptions({ participants, substitutions: [active] }).map((o) => o.label)).toEqual(['J. Smit (PvdA)'])
	})
})

describe('the live meeting page', () => {
	it('mounts the seats panel, which reads the server and shows actions only when it may swap', () => {
		expect(source('views/LiveMeeting.vue')).toContain('<SeatsPanel :meetingId="id" />')
		const panel = source('components/liveMeeting/SeatsPanel.vue')
		expect(panel).toContain("seatsRequest('GET', this.meetingId, 'seats')")
		expect(panel).toContain('<template v-if="canSubstitute">')
		expect(source('modals/MandateSwapModal.vue')).toContain(':inputLabel="t(\'decidiq\', \'Substitute\')"')
	})
})
