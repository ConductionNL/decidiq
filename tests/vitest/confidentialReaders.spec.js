/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The portal readers widget on a confidential agenda item and the admin
 * trust setting (portal-voting-record-and-confidential-papers tasks 3 and 4).
 */

import { describe, expect, it, vi } from 'vitest'
import {
	confidentialTrust,
	confidentialTrustPayload,
} from '../../src/utils/confidentialPapers.js'
import {
	addBody,
	closedItemAudience,
	personOptions,
	readerRows,
	readersOpen,
	readersUrl,
	relationId,
} from '../../src/utils/confidentialReaders.js'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

const persons = [
	{ id: 'pieter-bos', name: 'Pieter Bos' },
	{ id: 'sanne-mulder', name: 'Sanne Mulder' },
	{ id: 'anna-de-vries', name: 'Anna de Vries' },
]

describe('confidential readers', () => {
	it('names the readers in the item order and falls back to the id', () => {
		const item = { public: false, authorisedPersons: ['sanne-mulder', 'onbekend', ''] }
		expect(readerRows(item, persons)).toEqual([
			{ id: 'sanne-mulder', name: 'Sanne Mulder' },
			{ id: 'onbekend', name: 'onbekend' },
		])
	})

	it('offers only the people who are not readers yet, by name', () => {
		const item = { public: false, authorisedPersons: ['pieter-bos'] }
		expect(personOptions(item, persons)).toEqual([
			{ id: 'anna-de-vries', label: 'Anna de Vries' },
			{ id: 'sanne-mulder', label: 'Sanne Mulder' },
		])
	})

	it('takes readers only while the item is not public', () => {
		expect(readersOpen({ public: false })).toBe(true)
		expect(readersOpen({ public: true })).toBe(false)
		expect(readersOpen({})).toBe(false)
	})

	it('sends the number without spaces and keeps the type to bsn or kvk', () => {
		expect(addBody('pieter-bos', 'bsn', '111 222 333')).toEqual({
			person: 'pieter-bos',
			identityType: 'bsn',
			number: '111222333',
		})
		expect(addBody('pieter-bos', 'other', '1')).toMatchObject({ identityType: 'bsn' })
		expect(addBody('pieter-bos', 'kvk', '1234 5678')).toMatchObject({ identityType: 'kvk', number: '12345678' })
	})

	it('builds the add and remove routes', () => {
		expect(readersUrl('item 1')).toBe('/index.php/apps/decidiq/api/agenda-items/item%201/readers')
		expect(readersUrl('item-1', 'pieter-bos')).toBe('/index.php/apps/decidiq/api/agenda-items/item-1/readers/pieter-bos')
	})
})

describe('confidential papers trust setting', () => {
	it('reads unset as substantial and anything else as high, as the server does', () => {
		expect(confidentialTrust({})).toBe('substantial')
		expect(confidentialTrust({ portal_confidential_min_trust: 'Substantial' })).toBe('substantial')
		expect(confidentialTrust({ portal_confidential_min_trust: 'high' })).toBe('high')
		expect(confidentialTrust({ portal_confidential_min_trust: 'low' })).toBe('high')
		expect(confidentialTrustPayload('substantial')).toEqual({ portal_confidential_min_trust: 'substantial' })
		expect(confidentialTrustPayload('nonsense')).toEqual({ portal_confidential_min_trust: 'high' })
	})
})

/**
 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-001-an-agenda-item-and-each-paper-say-whether-they-are-public
 */
describe('who may read a closed item (board DcAgendapunt)', () => {
	it('says nothing while the item is public or unknown', () => {
		expect(closedItemAudience({ public: true }, { name: 'Gemeenteraad' })).toBeNull()
		expect(closedItemAudience({}, { name: 'Gemeenteraad' })).toBeNull()
		expect(closedItemAudience(null, null)).toBeNull()
	})

	it('names the body of the meeting once the item is closed', () => {
		expect(closedItemAudience({ public: false }, { name: ' Gemeenteraad ' })).toEqual({ bodyName: 'Gemeenteraad' })
	})

	it('still says it when the body could not be read, without a name', () => {
		expect(closedItemAudience({ public: false }, null)).toEqual({ bodyName: '' })
		expect(closedItemAudience({ public: false }, { name: 42 })).toEqual({ bodyName: '' })
	})

	it('reads a relation stored as an id or as an object', () => {
		expect(relationId('meeting-1')).toBe('meeting-1')
		expect(relationId({ id: 'meeting-2', name: 'x' })).toBe('meeting-2')
		expect(relationId({ '@self': { id: 'meeting-3' } })).toBe('meeting-3')
		expect(relationId({})).toBe('')
		expect(relationId(null)).toBe('')
		expect(relationId(7)).toBe('')
	})
})
