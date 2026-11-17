// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-001-the-motion-text-can-be-read-with-stable-line-numbers
 */
import { describe, expect, it } from 'vitest'
import { numberLines, passageOf } from '../../src/utils/lineNumbering.js'

const MOTION = [
	'De raad van de gemeente Zuiddrecht, in vergadering bijeen op 12 maart 2027, overwegende dat de fietspaden buiten de kom onverlicht zijn,',
	'',
	'besluit:',
	'Een krediet van 640.000 euro beschikbaar stellen voor verlichting.',
].join('\n')

describe('numberLines', () => {
	it('gives the same numbers for the same text every time', () => {
		expect(numberLines(MOTION)).toEqual(numberLines(MOTION))
	})

	it('breaks at word boundaries at no more than 80 characters', () => {
		const lines = numberLines(MOTION)
		expect(lines.map((line) => line.number)).toEqual([1, 2, 3, 4])
		expect(lines[0].text).toBe(
			'De raad van de gemeente Zuiddrecht, in vergadering bijeen op 12 maart 2027,',
		)
		expect(lines[1].text).toBe(
			'overwegende dat de fietspaden buiten de kom onverlicht zijn,',
		)
		for (const line of lines) {
			expect(line.text.length).toBeLessThanOrEqual(80)
			expect(MOTION.slice(line.start, line.end)).toBe(line.text)
		}
	})

	it('starts a new line at a paragraph break and numbers no empty paragraph', () => {
		const lines = numberLines('Eerste.\n\nTweede regel.')
		expect(lines).toEqual([
			{ number: 1, text: 'Eerste.', start: 0, end: 7 },
			{ number: 2, text: 'Tweede regel.', start: 9, end: 22 },
		])
	})

	it('keeps a word longer than the width whole', () => {
		const long = 'x'.repeat(30)
		expect(
			numberLines(`kort ${long} eind`, 20).map((line) => line.text),
		).toEqual(['kort', long, 'eind'])
	})

	it('numbers nothing for an empty or missing text', () => {
		expect(numberLines('')).toEqual([])
		expect(numberLines(undefined)).toEqual([])
	})
})

describe('passageOf', () => {
	it('is the exact text of the lines, as it stands in the motion', () => {
		expect(passageOf(MOTION, 3, 4)).toBe(
			'besluit:\nEen krediet van 640.000 euro beschikbaar stellen voor verlichting.',
		)
		expect(MOTION.includes(passageOf(MOTION, 1, 2))).toBe(true)
	})

	it('is empty for a range outside the lines or backwards', () => {
		expect(passageOf(MOTION, 4, 9)).toBe('')
		expect(passageOf(MOTION, 3, 2)).toBe('')
	})
})
