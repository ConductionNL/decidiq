/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Speaking requests in the browser (inspreekrecht-plenair): the agenda
 * speakers per place, the speaker-queue preload and its per-entry limit, the
 * registration deadline state, the moderation/queue API calls, and the
 * dialogs' shared moderation helpers.
 *
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const dispatch = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (_, key) => params[key]),
}))
vi.mock('@conduction/nextcloud-vue', () => ({ dispatchAction: dispatch }))

import {
	agendaItemOptions,
	meetingOptions,
	optionalPositiveInt,
	runModeration,
	speakerNameOf,
} from '../../src/dialogs/speakingRequestModeration.js'
import * as api from '../../src/services/speakingRequestApi.js'
import { addSpeaker, isOverLimit, removeSpeaker } from '../../src/utils/speakerQueue.js'
import {
	CLOSING_SOON_HOURS,
	deadlineState,
	entryLimitSeconds,
	isPublicSpeaker,
	preloadSpeakers,
	slotsFor,
	toQueueEntries,
} from '../../src/utils/speakingRequests.js'

const HOUR = 3600 * 1000

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	dispatch.mockReset()
	api.clearDeadlineCache()
})

describe('slotsFor', () => {
	const agenda = {
		item1: { slots: [{ id: 'a' }], pending: [{ id: 'b' }] },
		meeting: { slots: [{ id: 'm' }], pending: [] },
	}

	it('gives an agenda item its own slots and pending requests', () => {
		expect(slotsFor(agenda, 'item1')).toEqual({ slots: [{ id: 'a' }], pending: [{ id: 'b' }] })
	})

	it('gives the meeting block the meeting-level speakers for an empty place', () => {
		expect(slotsFor(agenda, '')).toEqual({ slots: [{ id: 'm' }], pending: [] })
		expect(slotsFor(agenda, null)).toEqual({ slots: [{ id: 'm' }], pending: [] })
	})

	it('answers nothing for an unknown place or the empty answer PHP sends as []', () => {
		expect(slotsFor(agenda, 'other')).toEqual({ slots: [], pending: [] })
		expect(slotsFor([], 'item1')).toEqual({ slots: [], pending: [] })
		expect(slotsFor(null, 'item1')).toEqual({ slots: [], pending: [] })
	})
})

describe('toQueueEntries', () => {
	it('makes a labelled public-speaker entry with its allotted minutes as its limit', () => {
		expect(toQueueEntries([{ requestId: 'r1', name: ' Mw. Jansen ', minutes: 5 }], 1000)).toEqual([
			{
				participantId: 'speaking-request:r1',
				displayName: 'Mw. Jansen',
				requestedAt: 1000,
				startedAt: null,
				spokenMs: 0,
				speaking: false,
				requestId: 'r1',
				publicSpeaker: true,
				limitSeconds: 300,
			},
		])
	})

	it('keeps the server order, drops rows without an id and names an unnamed speaker', () => {
		const entries = toQueueEntries([
			{ requestId: 'b', name: 'B', minutes: 3 },
			{ name: 'no id' },
			{ requestId: 'a', name: '', minutes: 0 },
		], 0, 'Public speaker')
		expect(entries.map((e) => e.requestId)).toEqual(['b', 'a'])
		expect(entries[1].displayName).toBe('Public speaker')
		expect(entries[1].limitSeconds).toBeNull()
		expect(toQueueEntries(null, 0)).toEqual([])
	})
})

describe('preloadSpeakers', () => {
	const adHoc = addSpeaker([], { id: 'p1', displayName: 'Raadslid' }, 0)

	it('places public speakers ahead of the chair\'s own entries', () => {
		const queue = preloadSpeakers(adHoc, toQueueEntries([{ requestId: 'r1', name: 'A' }, { requestId: 'r2', name: 'B' }], 0))
		expect(queue.map((e) => e.participantId)).toEqual(['speaking-request:r1', 'speaking-request:r2', 'p1'])
	})

	it('adds new public speakers after the ones already queued and never resets a queued one', () => {
		const first = preloadSpeakers(adHoc, toQueueEntries([{ requestId: 'r1', name: 'A' }], 0))
		const running = first.map((e) => (e.requestId === 'r1' ? { ...e, speaking: true, startedAt: 5 } : e))
		const queue = preloadSpeakers(running, toQueueEntries([{ requestId: 'r1', name: 'A' }, { requestId: 'r3', name: 'C' }], 9))
		expect(queue.map((e) => e.participantId)).toEqual(['speaking-request:r1', 'speaking-request:r3', 'p1'])
		expect(queue[0]).toBe(running[0])
	})

	it('returns the same queue when there is nothing new', () => {
		expect(preloadSpeakers(adHoc, [])).toBe(adHoc)
		expect(preloadSpeakers(adHoc, null)).toBe(adHoc)
	})

	it('lets the chair remove a public speaker without anything else changing', () => {
		const queue = preloadSpeakers(adHoc, toQueueEntries([{ requestId: 'r1', name: 'A' }], 0))
		expect(removeSpeaker(queue, 'speaking-request:r1')).toEqual(adHoc)
	})
})

describe('isPublicSpeaker and entryLimitSeconds', () => {
	it('knows a public speaker by its flag or its id prefix, not a participant', () => {
		expect(isPublicSpeaker({ publicSpeaker: true })).toBe(true)
		expect(isPublicSpeaker({ participantId: 'speaking-request:x' })).toBe(true)
		expect(isPublicSpeaker({ participantId: 'p1' })).toBe(false)
		expect(isPublicSpeaker(null)).toBe(false)
	})

	it('uses an entry\'s own limit over the panel limit', () => {
		const entry = { limitSeconds: 120, speaking: true, startedAt: 0, spokenMs: 0 }
		expect(entryLimitSeconds(entry, 600)).toBe(120)
		expect(entryLimitSeconds({ limitSeconds: null }, 600)).toBe(600)
		expect(isOverLimit(entry, entryLimitSeconds(entry, 600), 121 * 1000)).toBe(true)
		expect(isOverLimit(entry, 600, 121 * 1000)).toBe(false)
	})
})

describe('deadlineState', () => {
	const start = '2026-12-01T19:00:00Z'
	const startMs = Date.parse(start)

	it('is closed from the deadline (start minus the policy hours) on', () => {
		expect(deadlineState(start, 24, startMs - 24 * HOUR)).toEqual({ state: 'closed', deadline: startMs - 24 * HOUR })
		expect(deadlineState(start, 0, startMs + 1).state).toBe('closed')
	})

	it('is closing within a day of the deadline and open before that', () => {
		expect(deadlineState(start, 24, startMs - 24 * HOUR - 1).state).toBe('closing')
		expect(deadlineState(start, 24, startMs - (24 + CLOSING_SOON_HOURS) * HOUR).state).toBe('closing')
		expect(deadlineState(start, 24, startMs - (24 + CLOSING_SOON_HOURS) * HOUR - 1).state).toBe('open')
	})

	it('is unknown without a valid start or deadline', () => {
		expect(deadlineState(null, 24, 0)).toEqual({ state: 'unknown', deadline: null })
		expect(deadlineState('not a date', 24, 0).state).toBe('unknown')
		expect(deadlineState(start, null, 0).state).toBe('unknown')
		expect(deadlineState(start, -1, 0).state).toBe('unknown')
		expect(deadlineState(new Date(startMs), '48', startMs).state).toBe('closed')
	})
})

describe('speakingRequestApi', () => {
	beforeEach(() => {
		http.post.mockResolvedValue({ data: { id: 'r1' } })
	})

	it('approves with only the values given', async () => {
		expect(await api.approveSpeakingRequest('r 1', { allottedMinutes: 4, position: null })).toEqual({ id: 'r1' })
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/decidiq/api/speaking-requests/r%201/approve', { allottedMinutes: 4 })
		await api.approveSpeakingRequest('r1')
		expect(http.post).toHaveBeenLastCalledWith('/index.php/apps/decidiq/api/speaking-requests/r1/approve', {})
	})

	it('rejects with the reason, refers with the meeting and an optional item, and records an outcome', async () => {
		await api.rejectSpeakingRequest('r1', 'Off-topic')
		expect(http.post).toHaveBeenLastCalledWith('/index.php/apps/decidiq/api/speaking-requests/r1/reject', { rejectionReason: 'Off-topic' })
		await api.referSpeakingRequest('r1', 'm2')
		expect(http.post).toHaveBeenLastCalledWith('/index.php/apps/decidiq/api/speaking-requests/r1/refer', { meeting: 'm2' })
		await api.referSpeakingRequest('r1', 'm2', 'i9')
		expect(http.post).toHaveBeenLastCalledWith('/index.php/apps/decidiq/api/speaking-requests/r1/refer', { meeting: 'm2', agendaItem: 'i9' })
		await api.markSpeakingOutcome('r1', 'no-show')
		expect(http.post).toHaveBeenLastCalledWith('/index.php/apps/decidiq/api/speaking-requests/r1/outcome', { outcome: 'no-show' })
	})

	it('reads a meeting\'s slots and an item\'s queue', async () => {
		http.get.mockResolvedValueOnce({ data: { meeting: { slots: [], pending: [] } } })
		expect(await api.fetchSpeakingSlots('m1')).toEqual({ meeting: { slots: [], pending: [] } })
		expect(http.get).toHaveBeenLastCalledWith('/index.php/apps/decidiq/api/meetings/m1/speaking-slots')
		http.get.mockResolvedValueOnce({ data: [{ requestId: 'r1' }] })
		expect(await api.fetchSpeakingQueue('m1', 'i1')).toEqual([{ requestId: 'r1' }])
		expect(http.get).toHaveBeenLastCalledWith('/index.php/apps/decidiq/api/meetings/m1/speaking-queue', { params: { agendaItem: 'i1' } })
		http.get.mockResolvedValueOnce({ data: { message: 'x' } })
		expect(await api.fetchSpeakingQueue('m1', null)).toEqual([])
	})

	it('preloads only an opened meeting with a current item, and never throws', async () => {
		expect(await api.queuePreloadFor({ id: 'm1', lifecycle: 'scheduled' }, 'i1', 'P', 0)).toBeNull()
		expect(await api.queuePreloadFor({ id: 'm1', lifecycle: 'opened' }, null, 'P', 0)).toBeNull()
		expect(http.get).not.toHaveBeenCalled()
		http.get.mockResolvedValueOnce({ data: [{ requestId: 'r1', name: '', minutes: 2 }] })
		const preload = await api.queuePreloadFor({ id: 'm1', lifecycle: 'opened' }, 'i1', 'P', 7)
		expect(preload.itemId).toBe('i1')
		expect(preload.entries).toHaveLength(1)
		expect(preload.entries[0]).toMatchObject({ displayName: 'P', limitSeconds: 120, requestedAt: 7 })
		http.get.mockRejectedValueOnce(new Error('403'))
		expect(await api.queuePreloadFor({ id: 'm1', lifecycle: 'opened' }, 'i1', 'P', 0)).toBeNull()
	})

	it('reads a meeting start and a body deadline once each, however many rows ask', async () => {
		http.get.mockImplementation((url) => Promise.resolve(url.includes('/meeting/')
			? { data: { scheduledDate: '2026-12-01T19:00:00Z' } }
			: { data: { results: [{ governanceBody: 'other', registrationDeadlineHours: 1 }, { governanceBody: 'b1', registrationDeadlineHours: 48 }] } }))
		const answers = await Promise.all([api.meetingStartOf('m1'), api.meetingStartOf('m1'), api.deadlineHoursOf('b1'), api.deadlineHoursOf('b1')])
		expect(answers).toEqual(['2026-12-01T19:00:00Z', '2026-12-01T19:00:00Z', 48, 48])
		expect(http.get).toHaveBeenCalledTimes(2)
		expect(http.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/decidiq/speaking-policy',
			{ params: { governanceBody: 'b1', _limit: 1 } },
		)
	})

	it('answers null for a body without a policy or a read that fails', async () => {
		http.get.mockResolvedValueOnce({ data: { results: [] } })
		expect(await api.deadlineHoursOf('b2')).toBeNull()
		http.get.mockRejectedValueOnce(new Error('404'))
		expect(await api.meetingStartOf('m9')).toBeNull()
	})
})

describe('speakingRequestModeration', () => {
	it('refreshes the page after a moderation call that succeeds', async () => {
		expect(await runModeration(() => Promise.resolve({}), 'fallback')).toBe('')
		expect(dispatch).toHaveBeenCalledWith({ type: 'refresh' })
	})

	it('shows the server\'s refusal, or the fallback, and does not refresh', async () => {
		const refused = Object.assign(new Error('x'), { response: { data: { message: 'A reason is required.' } } })
		expect(await runModeration(() => Promise.reject(refused), 'fallback')).toBe('A reason is required.')
		expect(await runModeration(() => Promise.reject(new Error('net')), 'fallback')).toBe('fallback')
		expect(dispatch).not.toHaveBeenCalled()
	})

	it('reads optional whole numbers and the speaker name', () => {
		expect(optionalPositiveInt('')).toBeNull()
		expect(optionalPositiveInt(' ')).toBeNull()
		expect(optionalPositiveInt(null)).toBeNull()
		expect(optionalPositiveInt('0')).toBeNull()
		expect(optionalPositiveInt('2.5')).toBeNull()
		expect(optionalPositiveInt('3')).toBe(3)
		expect(speakerNameOf({ topic: { speakerName: ' Jan ' } })).toBe('Jan')
		expect(speakerNameOf({})).toBe('')
	})

	it('offers the body\'s upcoming meetings, soonest first, without the current one', async () => {
		http.get.mockResolvedValueOnce({
			data: {
				results: [
					{ id: 'm3', title: 'Raad', scheduledDate: '2026-12-20T19:00:00Z' },
					{ id: 'm1', title: 'Current', scheduledDate: '2026-12-01T19:00:00Z' },
					{ id: 'm0', title: 'Past', scheduledDate: '2026-01-01T19:00:00Z' },
					{ id: 'm2', title: 'Commissie', scheduledDate: '2026-12-10T19:00:00Z' },
				],
			},
		})
		const options = await meetingOptions('b1', 'm1', Date.parse('2026-11-14T00:00:00Z'))
		expect(options).toEqual([
			{ id: 'm2', label: 'Commissie · 2026-12-10' },
			{ id: 'm3', label: 'Raad · 2026-12-20' },
		])
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/decidiq/meeting', { params: { _limit: 100, governanceBody: 'b1' } })
		http.get.mockRejectedValueOnce(new Error('down'))
		expect(await meetingOptions('', '')).toEqual([])
	})

	it('offers a meeting\'s agenda items in agenda order', async () => {
		http.get.mockResolvedValueOnce({
			data: [
				{ id: 'i2', meeting: 'm2', orderNumber: 2, title: 'Begroting' },
				{ id: 'x', meeting: 'other', orderNumber: 1, title: 'Elsewhere' },
				{ id: 'i1', meeting: 'm2', orderNumber: 1, title: 'Opening' },
			],
		})
		expect(await agendaItemOptions('m2')).toEqual([
			{ id: 'i1', label: '1. Opening' },
			{ id: 'i2', label: '2. Begroting' },
		])
		expect(await agendaItemOptions('')).toEqual([])
	})
})
