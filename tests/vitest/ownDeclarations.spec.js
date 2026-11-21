// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * interests-and-integrity Task 4: on My declarations a member sees only their
 * own other positions and gifts, declares a position, registers a gift, ends a
 * position and confirms the annual review; staff publish and withdraw from the
 * detail pages. Component mounting is absent in this repo's vitest, so the
 * logic lives in src/utils/ownDeclarations.js and the wiring is asserted on the
 * manifest fragment and the registry source. Every record written is checked
 * against the schema the register serves.
 *
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	canConfirm,
	canEnd,
	confirmedReview,
	dayOf,
	endDateIsValid,
	endedPosition,
	giftFormIsValid,
	newGift,
	newPosition,
	ownPersonIds,
	ownRecords,
	positionFormIsValid,
} from '../../src/utils/ownDeclarations.js'
import { validatorFor } from './helpers/registerSchema.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

const NOW = new Date(2026, 10, 21, 10, 0, 0)
const ME = '3f1c2b4a-5d6e-4f70-8a9b-0c1d2e3f4a5b'
const BODY = { id: '7a8b9c0d-1e2f-4a3b-9c4d-5e6f7a8b9c0d', label: 'Council' }

function position(extra = {}) {
	return {
		person: ME,
		governanceBody: BODY.id,
		organisation: 'Stichting Buurtwerk',
		role: 'Board member',
		remunerated: false,
		startDate: '2024-01-01',
		declaredAt: '2024-01-05',
		lifecycle: 'public',
		...extra,
	}
}

describe('only the current user\'s own declarations are listed', () => {
	const people = [
		{ id: ME, nextcloudUserId: 'jdoe' },
		{ id: 'person-other', nextcloudUserId: 'asmith' },
		{ id: 'person-unlinked' },
	]

	it('finds the people the user is by their Nextcloud user id', () => {
		expect(ownPersonIds(people, 'jdoe')).toEqual([ME])
		expect(ownPersonIds(people, '')).toEqual([])
		expect(ownPersonIds(null, 'jdoe')).toEqual([])
	})

	it('keeps the positions and gifts of those people and nobody else\'s', () => {
		const positions = [position({ id: 'p1' }), position({ id: 'p2', person: 'person-other' }), position({ id: 'p3', person: { id: ME } })]
		expect(ownRecords(positions, [ME], 'person').map((p) => p.id)).toEqual(['p1', 'p3'])
		const gifts = [{ id: 'g1', recipient: ME }, { id: 'g2', recipient: 'person-other' }]
		expect(ownRecords(gifts, [ME], 'recipient').map((g) => g.id)).toEqual(['g1'])
	})

	it('lists nothing for a user who is no person', () => {
		expect(ownRecords([position()], [], 'person')).toEqual([])
	})
})

describe('confirming the annual review', () => {
	it('sets reviewedAt to today and changes no other field', () => {
		const before = position({ id: 'p1', reviewedAt: '2025-01-01' })
		const after = confirmedReview(before, NOW)
		expect(after).toEqual({ ...before, reviewedAt: '2026-11-21' })
		expect(before.reviewedAt).toBe('2025-01-01')
	})

	it('is offered for every position that has not ended', () => {
		expect(canConfirm(position({ lifecycle: 'reported' }))).toBe(true)
		expect(canConfirm(position({ lifecycle: 'internal' }))).toBe(true)
		expect(canConfirm(position({ lifecycle: 'ended' }))).toBe(false)
		expect(canConfirm(null)).toBe(false)
	})
})

describe('ending a position', () => {
	it('is offered only from the states the lifecycle ends from', () => {
		expect(canEnd(position({ lifecycle: 'public' }))).toBe(true)
		expect(canEnd(position({ lifecycle: 'internal' }))).toBe(true)
		expect(canEnd(position({ lifecycle: 'reported' }))).toBe(false)
		expect(canEnd(position({ lifecycle: 'ended' }))).toBe(false)
	})

	it('records the end date and moves to ended, keeping every other field', () => {
		const before = position({ id: 'p1' })
		expect(endedPosition(before, '2026-11-01')).toEqual({ ...before, endDate: '2026-11-01', lifecycle: 'ended' })
	})

	it('refuses an end date before the start or not a day', () => {
		expect(endDateIsValid(position(), '2026-11-01')).toBe(true)
		expect(endDateIsValid(position(), '2024-01-01')).toBe(true)
		expect(endDateIsValid(position(), '2023-12-31')).toBe(false)
		expect(endDateIsValid(position(), '')).toBe(false)
		expect(endDateIsValid(position(), '1-11-2026')).toBe(false)
	})
})

describe('declaring a position', () => {
	const form = {
		governanceBody: BODY,
		organisation: ' Stichting Buurtwerk ',
		role: 'Board member',
		hoursIndication: '',
		startDate: '2026-10-01',
		remunerated: true,
		qualitateQua: false,
	}

	it('needs a body, an organisation, a function and a start date', () => {
		expect(positionFormIsValid(form)).toBe(true)
		expect(positionFormIsValid({ ...form, governanceBody: null })).toBe(false)
		expect(positionFormIsValid({ ...form, organisation: '  ' })).toBe(false)
		expect(positionFormIsValid({ ...form, role: 'x'.repeat(256) })).toBe(false)
		expect(positionFormIsValid({ ...form, startDate: '' })).toBe(false)
		expect(positionFormIsValid(null)).toBe(false)
	})

	it('is declared today by the member, in the initial state, and is a valid record', () => {
		const record = newPosition(form, ME, NOW)
		expect(record).toEqual({
			person: ME,
			governanceBody: BODY.id,
			organisation: 'Stichting Buurtwerk',
			role: 'Board member',
			remunerated: true,
			qualitateQua: false,
			startDate: '2026-10-01',
			declaredAt: '2026-11-21',
			lifecycle: 'reported',
		})
		const valid = validatorFor('ancillary-position')
		expect(valid(record), JSON.stringify(valid.errors)).toBe(true)
		expect(newPosition({ ...form, hoursIndication: '4 hours a week' }, ME, NOW).hoursIndication).toBe('4 hours a week')
	})

	it('writes records the schema accepts when confirmed and ended', () => {
		const valid = validatorFor('ancillary-position')
		expect(valid(confirmedReview(position(), NOW)), JSON.stringify(valid.errors)).toBe(true)
		expect(valid(endedPosition(position(), '2026-11-01')), JSON.stringify(valid.errors)).toBe(true)
	})
})

describe('registering a gift', () => {
	const form = {
		governanceBody: BODY,
		type: 'invitation',
		giver: 'Havenbedrijf',
		description: 'Dinner after the opening',
		estimatedValue: '75',
		receivedOn: '2026-11-10',
		decision: 'accepted',
		notes: '',
	}

	it('needs every required field, a known type and decision and a value of zero or more', () => {
		expect(giftFormIsValid(form)).toBe(true)
		expect(giftFormIsValid({ ...form, estimatedValue: '0' })).toBe(true)
		expect(giftFormIsValid({ ...form, estimatedValue: '' })).toBe(false)
		expect(giftFormIsValid({ ...form, estimatedValue: '-1' })).toBe(false)
		expect(giftFormIsValid({ ...form, type: 'loan' })).toBe(false)
		expect(giftFormIsValid({ ...form, decision: '' })).toBe(false)
		expect(giftFormIsValid({ ...form, giver: '' })).toBe(false)
		expect(giftFormIsValid({ ...form, receivedOn: '' })).toBe(false)
		expect(giftFormIsValid(undefined)).toBe(false)
	})

	it('is registered today with the member as recipient, and is a valid record', () => {
		const record = newGift({ ...form, notes: 'Declined the wine.' }, ME, NOW)
		expect(record).toMatchObject({ recipient: ME, governanceBody: BODY.id, estimatedValue: 75, declaredAt: '2026-11-21', notes: 'Declined the wine.' })
		const valid = validatorFor('declared-gift')
		expect(valid(record), JSON.stringify(valid.errors)).toBe(true)
		expect('notes' in newGift(form, ME, NOW)).toBe(false)
	})
})

describe('a day in the schema\'s date format', () => {
	it('pads month and day', () => {
		expect(dayOf(new Date(2026, 0, 5))).toBe('2026-01-05')
	})
})

describe('the pages carry the actions', () => {
	const fragment = JSON.parse(read('src/manifest.d/interests-and-integrity.json'))
	const page = (id) => fragment.pages.find((p) => p.id === id)

	it('My declarations shows the member\'s own panel, registered as a page component', () => {
		expect(page('MyDeclarations').slots).toEqual({ 'below-header': 'MyDeclarationsPanel' })
		expect(read('src/registry.js')).toMatch(/MyDeclarationsPanel: page\(MyDeclarationsPanel\)/)
	})

	it.each([
		['AncillaryPositionDetail', 'ancillary-position'],
		['DeclaredGiftDetail', 'declared-gift'],
	])('%s publishes and withdraws by setting the two dates on the live object', (id, slug) => {
		const actions = page(id).config.headerActions
		expect(actions.map((a) => a.id)).toEqual(['publish', 'withdraw-publication'])
		for (const action of actions) {
			expect(action.method).toBe('PATCH')
			expect(action.url).toBe(`/apps/openregister/api/objects/decidiq/${slug}/@objectId`)
			expect(action.confirm).toBe(true)
		}
		expect(actions[0].payload).toEqual({ publicationDate: '@now', depublicationDate: null })
		expect(actions[1].payload).toEqual({ depublicationDate: '@now' })
	})
})
