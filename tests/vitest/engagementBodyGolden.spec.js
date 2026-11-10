/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden run of the engagement request body: without a current item the
 * event carries no agendaItem, and the event details are copied, not shared.
 * Pinned before the body took one engagement object.
 *
 * @spec exclude golden test of the engagement body, no behaviour of its own
 */
import { describe, expect, it } from 'vitest'
import { engagementBody } from '../../src/utils/liveMeeting.js'

describe('engagementBody', () => {
	it('leaves out the agenda item when there is none and copies the details', () => {
		const extra = { duration: 30 }
		const body = engagementBody({
			meetingId: 'm1',
			participant: 'p1',
			eventType: 'speech',
			itemId: null,
			extra,
		})
		expect(body).toEqual({
			meeting: 'm1',
			participant: 'p1',
			eventType: 'speech',
			eventData: { duration: 30 },
		})
		expect(body.eventData).not.toBe(extra)
		expect(
			engagementBody({
				meetingId: 'm1',
				participant: 'p1',
				eventType: 'question',
				itemId: '',
			}).eventData,
		).toEqual({})
		expect(
			Object.keys(
				engagementBody({
					meetingId: 'm',
					participant: 'p',
					eventType: 'speech',
					itemId: 'i',
					extra: { a: 1 },
				}),
			),
		).toEqual(['meeting', 'participant', 'eventType', 'eventData'])
	})
})
