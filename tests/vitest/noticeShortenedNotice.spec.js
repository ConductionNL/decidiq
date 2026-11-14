/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A recorded shortened notice on an extraordinary meeting is a deliberate
 * state, not an overdue error; a regular meeting keeps the overdue warning.
 *
 * @spec openspec/changes/urgent-decision-procedure/specs/meeting-management/spec.md#requirement-shortened-notice-deviation-recording-for-emergency-convocations
 */
import { describe, expect, it } from 'vitest'
import { getNoticeDeadlineInfo, isRecordedShortenedNotice } from '../../src/services/noticeRules.js'

const NOW = new Date('2026-06-01T10:00:00Z')

describe('noticeRules shortened notice', () => {
	it('presents a recorded deviation on an extraordinary meeting as shortened', () => {
		const info = getNoticeDeadlineInfo({ meetingDate: '2026-06-04', meetingType: 'extraordinary', shortenedNotice: true }, NOW)

		expect(info.level).toBe('shortened')
		expect(info.deadline).toBe('2026-05-20')
		expect(info.daysUntilDeadline).toBe(-12)
	})

	it('keeps the overdue warning for a regular meeting, recorded flag or not', () => {
		expect(getNoticeDeadlineInfo({ meetingDate: '2026-06-04', meetingType: 'regular', shortenedNotice: true }, NOW).level).toBe('overdue')
		expect(getNoticeDeadlineInfo({ meetingDate: '2026-06-04', meetingType: 'extraordinary' }, NOW).level).toBe('overdue')
	})

	it('recognises only an extraordinary meeting with shortenedNotice true', () => {
		expect(isRecordedShortenedNotice({ meetingType: 'extraordinary', shortenedNotice: true })).toBe(true)
		expect(isRecordedShortenedNotice({ meetingType: 'extraordinary', shortenedNotice: 'true' })).toBe(false)
		expect(isRecordedShortenedNotice(null)).toBe(false)
	})
})
