/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for expandRecurrence: every combination of a set of start
 * dates, frequencies, intervals, end dates and exception lists, with every
 * result snapshotted.
 *
 * @spec openspec/specs/meeting-management/spec.md
 */
import { describe, expect, it } from 'vitest'
import { expandRecurrence } from '../../src/services/agendaRules.js'

const STARTS = [
	'2026-01-31',
	'2026-02-28T10:00:00+01:00',
	'2024-02-29',
	'not a date',
]
const FREQUENCIES = ['daily', 'weekly', 'monthly', 'yearly', undefined]
const INTERVALS = [undefined, 1, 2, '3', 0, -1, 'x', 1.5]
const UNTILS = [undefined, '2026-03-31', '2027-12-31', '2025-01-01', 'never']
const EXCEPTIONS = [
	undefined,
	[],
	['2026-02-07', '2026-03-31T09:00:00Z', 20260314],
	['2026-01-31', '2026-02-28'],
]

const CASES = {}
let n = 0
for (const start of STARTS) {
	for (const frequency of FREQUENCIES) {
		for (const interval of INTERVALS) {
			for (const until of UNTILS) {
				const exceptions = EXCEPTIONS[n++ % EXCEPTIONS.length]
				const pattern = { frequency, until }
				if (interval !== undefined) {
					pattern.interval = interval
				}
				if (exceptions !== undefined) {
					pattern.exceptions = exceptions
				}
				CASES[[start, frequency, interval, until, n].join(' | ')] = [
					start,
					pattern,
				]
			}
		}
	}
}
CASES['no pattern'] = ['2026-01-01', undefined]
CASES['null pattern'] = ['2026-01-01', null]
CASES['daily past the cap'] = [
	'2026-01-01',
	{ frequency: 'daily', until: '2026-12-31' },
]
CASES['monthly past the cap'] = [
	'2020-01-15',
	{ frequency: 'monthly', until: '2030-12-31' },
]
CASES['cap reached exactly'] = [
	'2026-01-01',
	{ frequency: 'weekly', until: '2026-12-24' },
]
CASES['exceptions as text'] = [
	'2026-01-01',
	{ frequency: 'daily', until: '2026-01-05', exceptions: 'x' },
]
CASES['exceptions as text, bad date'] = [
	'nope',
	{ frequency: 'daily', until: '2026-01-05', exceptions: 'x' },
]
CASES['monthly on the 30th'] = [
	'2026-01-30',
	{ frequency: 'monthly', interval: 1, until: '2026-12-31' },
]

describe('expandRecurrence golden', () => {
	for (const [name, [start, pattern]] of Object.entries(CASES)) {
		it(name, () => {
			let out
			try {
				out = expandRecurrence(start, pattern)
			} catch (e) {
				out = { threw: e.constructor.name }
			}
			expect(out).toMatchSnapshot()
		})
	}
})
