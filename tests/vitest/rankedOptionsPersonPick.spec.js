/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The ranked round's option editor takes Person picks (REQ-PRF-001).
 *
 * @spec openspec/changes/voting-ranked-preference-ballot/specs/preferential-ballot/spec.md#requirement-req-prf-001-chair-can-open-a-votinground-with-method-ranked-choice
 */

import { describe, expect, it } from 'vitest'
import { pickPerson, rankedOptionsFrom } from '../../src/utils/rankedBallot.js'

describe('rankedOptionsFrom', () => {
	it('stores each picked Person with its option', () => {
		expect(
			rankedOptionsFrom([
				{ label: 'Van der Meer', person: 'p-1' },
				{ label: 'Hoekstra', person: 'p-2' },
				{ label: 'De Vries', person: 'p-3' },
			]),
		).toEqual([
			{ key: 'van-der-meer', label: 'Van der Meer', person: 'p-1' },
			{ key: 'hoekstra', label: 'Hoekstra', person: 'p-2' },
			{ key: 'de-vries', label: 'De Vries', person: 'p-3' },
		])
	})

	it('sends no person for a typed option', () => {
		expect(
			rankedOptionsFrom([
				{ label: 'Rent a hall' },
				{ label: 'Build', person: '' },
			]),
		).toEqual([
			{ key: 'rent-a-hall', label: 'Rent a hall' },
			{ key: 'build', label: 'Build' },
		])
	})

	it('keeps keys unique and leaves out rows without a label', () => {
		expect(
			rankedOptionsFrom([
				{ label: 'Jansen' },
				{ label: ' ' },
				{ label: 'Jansen', person: 'p-9' },
			]),
		).toEqual([
			{ key: 'jansen', label: 'Jansen' },
			{ key: 'jansen-2', label: 'Jansen', person: 'p-9' },
		])
	})
})

describe('pickPerson', () => {
	it('fills an empty label with the person name', () => {
		expect(
			pickPerson({ label: '' }, { id: 'p-1', label: 'Van der Meer' }),
		).toEqual({ label: 'Van der Meer', person: 'p-1' })
	})

	it('keeps a label the chair typed', () => {
		expect(
			pickPerson(
				{ label: 'Mr. Van der Meer (VVD)' },
				{ id: 'p-1', label: 'Van der Meer' },
			),
		).toEqual({
			label: 'Mr. Van der Meer (VVD)',
			person: 'p-1',
		})
	})

	it('clears only the reference when the pick is cleared', () => {
		expect(pickPerson({ label: 'Van der Meer', person: 'p-1' }, null)).toEqual({
			label: 'Van der Meer',
			person: '',
		})
	})
})
