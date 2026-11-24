// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A speaker's slot links to the transcript segment where they spoke (inspreekrecht-plenair, REQ-INS-006).
 *
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-006-written-bijdrage-and-transcript-segment-linkage
 */
import { describe, expect, it } from 'vitest'
import { formatOffset, segmentAnchor, segmentStart, startFromHash, transcriptRoute } from '../../src/utils/transcriptSegments.js'

describe('addressing a transcript segment', () => {
	it('reads a start in seconds or as the transcript shows it', () => {
		expect(segmentStart(754)).toBe(754)
		expect(segmentStart(754.8)).toBe(754)
		expect(segmentStart('754')).toBe(754)
		expect(segmentStart(' 754.5 ')).toBe(754)
		expect(segmentStart('12:34')).toBe(754)
		expect(segmentStart('1:02:03')).toBe(3723)
	})

	it('names no moment for anything else', () => {
		for (const value of [null, undefined, '', 'Voorbeeld Transcriptsegment 1', '12:75', '1:61:00', -3, Number.NaN, '12:3']) {
			expect(segmentStart(value), String(value)).toBeNull()
		}
	})

	it('anchors a segment by its whole second, and reads that anchor back from a hash', () => {
		expect(segmentAnchor(754.9)).toBe('transcript-segment-754')
		expect(startFromHash('#transcript-segment-754')).toBe(754)
		expect(startFromHash('transcript-segment-0')).toBe(0)
		expect(startFromHash('#minutes')).toBeNull()
		expect(startFromHash(undefined)).toBeNull()
	})

	it('shows a start as m:ss, and as h:mm:ss from an hour on', () => {
		expect(formatOffset(0)).toBe('0:00')
		expect(formatOffset(754)).toBe('12:34')
		expect(formatOffset(3723)).toBe('1:02:03')
	})
})

describe('the speaker slot on the agenda', () => {
	const link = (slot, meetingId = 'meeting-1') => transcriptRoute(meetingId, slot.transcriptSegment)

	it('links a speaker with a linked segment to that moment on the meeting page', () => {
		expect(link({ transcriptSegment: '12:34' })).toEqual({
			name: 'MeetingDetail',
			params: { id: 'meeting-1' },
			hash: '#transcript-segment-754',
		})
		expect(formatOffset(segmentStart(754))).toBe('12:34')
	})

	it('offers no link without a segment or a meeting', () => {
		expect(link({ transcriptSegment: null })).toBeNull()
		expect(link({})).toBeNull()
		expect(link({ transcriptSegment: '754' }, '')).toBeNull()
	})
})
