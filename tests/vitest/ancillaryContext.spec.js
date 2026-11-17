// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Registered other positions as assistive context on the conflict-of-interest
 * surfaces (interests-and-integrity, REQ-INT-007).
 *
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-007-assistive-nevenfuncties-context-on-the-coi-surfaces
 */

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	ancillaryContextRows,
	hasActivePositions,
	matchesSubject,
	wordsOf,
} from '../../src/utils/ancillaryContext.js'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const read = (file) => readFileSync(resolve(ROOT, file), 'utf8')

const welzijn = {
	id: 'ap-1',
	organisation: 'Stichting Welzijn Noord',
	role: 'Bestuurslid',
	lifecycle: 'public',
}
const sportclub = {
	id: 'ap-2',
	organisation: 'Voetbalclub Oostzaan',
	role: 'Penningmeester',
	lifecycle: 'internal',
}
const ended = {
	id: 'ap-3',
	organisation: 'Welzijn Noord Woningbouw',
	role: 'Toezichthouder',
	lifecycle: 'ended',
}

describe('ancillaryContextRows (REQ-INT-007)', () => {
	it('lists both active positions and marks the one matching the subject, first', () => {
		const rows = ancillaryContextRows(
			[sportclub, welzijn, ended],
			'Subsidie Stichting Welzijn Noord',
		)

		expect(rows).toEqual([
			{
				id: 'ap-1',
				organisation: 'Stichting Welzijn Noord',
				role: 'Bestuurslid',
				matches: true,
			},
			{
				id: 'ap-2',
				organisation: 'Voetbalclub Oostzaan',
				role: 'Penningmeester',
				matches: false,
			},
		])
	})

	it('marks nothing when the subject has no title', () => {
		expect(
			ancillaryContextRows([welzijn], '').map((row) => row.matches),
		).toEqual([false])
	})

	it('matches on the function as well, ignoring case and short words', () => {
		expect(
			matchesSubject(sportclub, 'Benoeming PENNINGMEESTER rekenkamer'),
		).toBe(true)
		expect(
			matchesSubject({ organisation: 'De Raad van Noord' }, 'Van de raad'),
		).toBe(true)
		expect(matchesSubject({ organisation: 'De Uil' }, 'de uil en de van')).toBe(
			false,
		)
		expect([...wordsOf('Subsidie: Stichting, Welzijn-Noord (2027)')]).toEqual([
			'subsidie',
			'stichting',
			'welzijn',
			'noord',
			'2027',
		])
	})
})

describe('the chair panel link (REQ-INT-007)', () => {
	it('links a member only when she has a position that has not ended', () => {
		expect(hasActivePositions([ended, welzijn])).toBe(true)
		expect(hasActivePositions([ended])).toBe(false)
		expect(hasActivePositions([])).toBe(false)
		expect(hasActivePositions(null)).toBe(false)
	})
})

describe('no automatic conflict handling (REQ-INT-007)', () => {
	it('the declare dialog only shows the positions and still posts only what the member entered', () => {
		const dialog = read('src/dialogs/ConflictDeclareDialog.vue')
		expect(dialog).toMatch(/data-testid="conflict-declare-positions"/)
		expect(dialog).toMatch(
			/ancillaryContextRows\(this\.positions, this\.subjectTitle\)/,
		)
		// The submitted payload carries the three form values and nothing derived.
		expect(dialog).toMatch(
			/\$emit\('submit', \{\s*declarationType: this\.type\?\.id \|\| 'financial-interest',\s*description: this\.description,\s*recuseFromVote: this\.recuse,\s*\}\)/,
		)
	})

	it('the conflicts tab never posts on its own', () => {
		const tab = read('src/components/tabs/ConflictsTab.vue')
		expect(tab.match(/method: 'POST'/g)).toHaveLength(1)
		expect(tab).toMatch(/async declare\(values\)/)
	})
})
