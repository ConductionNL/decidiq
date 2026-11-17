// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-006-the-motion-page-lists-every-recommendation-with-its-status
 */
import { describe, expect, it } from 'vitest'
import {
	decisionPath,
	isPassageChanged,
	lineRange,
	newRecommendation,
	oldestFirst,
} from '../../src/utils/changeRecommendations.js'

describe('isPassageChanged', () => {
	const text = 'Een krediet van 640.000 euro beschikbaar stellen.'

	it('is false while the passage still occurs', () => {
		expect(
			isPassageChanged(
				{ status: 'pending', targetPassage: '640.000 euro' },
				text,
			),
		).toBe(false)
	})

	it('is true when the text no longer contains the passage, or there is none', () => {
		expect(
			isPassageChanged(
				{ status: 'pending', targetPassage: '700.000 euro' },
				text,
			),
		).toBe(true)
		expect(isPassageChanged({ targetPassage: '' }, text)).toBe(true)
	})

	it('is never true for a decided recommendation', () => {
		expect(
			isPassageChanged({ status: 'accepted', targetPassage: 'gone' }, text),
		).toBe(false)
		expect(
			isPassageChanged({ status: 'rejected', targetPassage: 'gone' }, text),
		).toBe(false)
	})
})

describe('oldestFirst', () => {
	it('orders by creation, then id, without touching the input', () => {
		const rows = [
			{ id: 'c', '@self': { created: '2026-11-03' } },
			{ id: 'b', created: '2026-11-01' },
			null,
			{ id: 'a', created: '2026-11-01' },
		]
		expect(oldestFirst(rows).map((row) => row.id)).toEqual(['a', 'b', 'c'])
		expect(rows[0].id).toBe('c')
		expect(oldestFirst(undefined)).toEqual([])
	})
})

describe('lineRange', () => {
	it('names one line or a range', () => {
		expect(lineRange({ lineFrom: 4, lineTo: 5 })).toBe('4-5')
		expect(lineRange({ lineFrom: 9, lineTo: 9 })).toBe('9')
		expect(lineRange({ lineFrom: 9 })).toBe('9')
		expect(lineRange({})).toBe('')
	})
})

describe('decisionPath and newRecommendation', () => {
	it('points at the accept or reject route', () => {
		expect(decisionPath('rec 1', 'accept')).toBe(
			'/apps/decidiq/api/change-recommendations/rec%201/accept',
		)
		expect(decisionPath('rec-1', 'anything')).toBe(
			'/apps/decidiq/api/change-recommendations/rec-1/reject',
		)
	})

	it('saves lines 4 to 5 with their exact text as the passage, pending', () => {
		expect(
			newRecommendation('motion-1', {
				lineFrom: '4',
				lineTo: 5,
				targetPassage: 'Een krediet van 640.000 euro',
				proposedText: 'Een krediet van 645.000 euro',
				reason: ' Bedrag uit de begrotingswijziging ',
			}),
		).toEqual({
			motion: 'motion-1',
			lineFrom: 4,
			lineTo: 5,
			targetPassage: 'Een krediet van 640.000 euro',
			proposedText: 'Een krediet van 645.000 euro',
			reason: 'Bedrag uit de begrotingswijziging',
			status: 'pending',
		})
	})
})
