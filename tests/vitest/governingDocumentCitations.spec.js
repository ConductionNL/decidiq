// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// @spec openspec/changes/governing-documents-register

import { describe, expect, it } from 'vitest'
import {
	citationsOf,
	citedByPath,
	documentLabel,
	inForcePath,
	isCalendarDate,
	referenceId,
	storedCitation,
	withCitation,
	withoutCitation,
} from '../../src/utils/governingDocumentCitations.js'

describe('the citations a decision carries', () => {
	it('reads each citation with its document, version and article', () => {
		const decision = {
			citesGoverningDocuments: [
				{ document: 'statuten-vng', article: ' art. 14 lid 3 ' },
				{ document: { id: 'rvo' }, version: { '@self': { id: 'rvo-v1' } } },
			],
		}

		expect(citationsOf(decision)).toEqual([
			{ document: 'statuten-vng', version: '', article: 'art. 14 lid 3' },
			{ document: 'rvo', version: 'rvo-v1', article: '' },
		])
	})

	it('drops entries without a document and reads a decision without citations as none', () => {
		expect(citationsOf({ citesGoverningDocuments: [null, 'x', { article: 'art. 1' }] })).toEqual([])
		expect(citationsOf({})).toEqual([])
		expect(citationsOf(null)).toEqual([])
		expect(citationsOf({ citesGoverningDocuments: 'statuten-vng' })).toEqual([])
	})
})

describe('adding and removing a citation', () => {
	const current = [{ document: 'statuten-vng', version: '', article: 'art. 20' }]

	it('stores only the fields that are given', () => {
		expect(storedCitation({ document: 'd', version: '', article: '  ' })).toEqual({ document: 'd' })
		expect(storedCitation({ document: 'd', version: 'v', article: 'art. 2' })).toEqual({ document: 'd', version: 'v', article: 'art. 2' })
	})

	it('adds a citation after the ones already there', () => {
		expect(withCitation(current, { document: 'rvo', article: 'art. 5' })).toEqual([
			{ document: 'statuten-vng', article: 'art. 20' },
			{ document: 'rvo', article: 'art. 5' },
		])
	})

	it('does not add the same citation twice', () => {
		expect(withCitation(current, { document: 'statuten-vng', article: 'ART. 20' })).toEqual([
			{ document: 'statuten-vng', article: 'art. 20' },
		])
	})

	it('refuses a citation without a document', () => {
		expect(() => withCitation(current, { document: '', article: 'art. 1' })).toThrow('A citation needs a governing document.')
	})

	it('removes the citation at an index and keeps the others', () => {
		const two = withCitation(current, { document: 'rvo' })
		expect(withoutCitation(two, 0)).toEqual([{ document: 'rvo' }])
		expect(withoutCitation(two, 5)).toEqual(two)
	})
})

describe('the governing-document endpoints and dates', () => {
	it('builds the in-force path, with a date only when one is asked', () => {
		expect(inForcePath('doc 1')).toBe('/apps/decidiq/api/governing-documents/doc%201/in-force')
		expect(inForcePath('doc', '2021-06-15')).toBe('/apps/decidiq/api/governing-documents/doc/in-force?date=2021-06-15')
	})

	it('builds the cited-by path', () => {
		expect(citedByPath('doc')).toBe('/apps/decidiq/api/governing-documents/doc/cited-by')
	})

	it('accepts only real calendar dates', () => {
		expect(isCalendarDate('2021-06-15')).toBe(true)
		expect(isCalendarDate('2024-02-29')).toBe(true)
		expect(isCalendarDate('2021-02-30')).toBe(false)
		expect(isCalendarDate('15-06-2021')).toBe(false)
		expect(isCalendarDate('')).toBe(false)
	})

	it('names a document by its citation title', () => {
		expect(documentLabel({ id: 'd', citationTitle: 'Statuten VNG' })).toBe('Statuten VNG')
		expect(documentLabel({ id: 'd' })).toBe('d')
		expect(documentLabel(null)).toBe('')
		expect(referenceId(7)).toBe('7')
	})
})
