// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * meeting-ad-hoc-with-guests Task 3 (matrix row pla-20, board
 * DcAdhocOverleg): one Nieuw overleg page sets up a meeting without a body
 * with its agenda, papers, colleagues and guests, then Overleg aanmaken.
 * Every object the page writes is validated against the merged register
 * schema it is written into.
 *
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	agendaPayloads,
	attendancePayloads,
	createAdhocMeeting,
	createTalkRoom,
	meetingPayload,
	missingFields,
	uploadPapers,
} from '../../src/utils/adhocMeeting.js'
import { validatorFor } from './helpers/registerSchema.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

const FORM = {
	title: 'Overleg subsidie wijkcentrum Zuid',
	start: '2026-10-21T15:00',
	endTime: '16:30',
	location: 'Stadskantoor, kamer 2.14',
	talk: true,
	agenda: [
		'Opening en kennismaking',
		' Stand van zaken subsidie wijkcentrum ',
		'',
	],
	papers: [{ name: 'Subsidieaanvraag 2027.pdf' }],
	colleagues: [
		{ id: 'p-lisa', displayName: 'Lisa Vermeulen', nextcloudUserId: 'lisa' },
	],
	guests: [{ name: 'Karim Ouali', email: 'k.ouali@wijkcentrumzuid.nl' }],
}

/**
 * A fake api that records the order of the calls.
 *
 * @param {object} overrides Calls that behave differently
 * @return {object} The api and its call log
 */
function recordingApi(overrides = {}) {
	const calls = []
	let n = 0
	const api = {
		saveObject: vi.fn(async (slug, payload) => {
			calls.push(['save', slug, payload])
			n += 1
			return { ...payload, id: `${slug}-${n}` }
		}),
		uploadPapers: vi.fn(async (meetingId, files) => {
			calls.push(['papers', meetingId, files.length])
		}),
		createTalkRoom: vi.fn(async (meetingId, title, uids) => {
			calls.push(['talk', meetingId, title, uids])
		}),
		inviteGuest: vi.fn(async (meetingId, guest) => {
			calls.push(['guest', meetingId, guest.email])
			return { mailed: true }
		}),
		...overrides,
	}
	return { api, calls }
}

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('the Nieuw overleg page writes what the register accepts (REQ-MAH-001)', () => {
	it('the meeting has no body, starts as draft, and is valid against the meeting schema', () => {
		const payload = meetingPayload(FORM)
		const validate = validatorFor('meeting')
		expect(validate(payload), JSON.stringify(validate.errors)).toBe(true)
		expect(payload.governanceBody).toBeUndefined()
		expect(payload.lifecycle).toBe('draft')
		expect(payload.isPublic).toBe(false)
		expect(payload.title).toBe('Overleg subsidie wijkcentrum Zuid')
		expect(payload.scheduledDate).toBe(
			new Date('2026-10-21T15:00').toISOString(),
		)
		expect(payload.endDate).toBe(new Date('2026-10-21T16:30').toISOString())
	})

	it('the meeting mode follows the place and the Talk choice', () => {
		expect(meetingPayload({ ...FORM, talk: false }).meetingMode).toBe(
			'in-person',
		)
		expect(meetingPayload(FORM).meetingMode).toBe('hybrid')
		expect(meetingPayload({ ...FORM, location: '' }).meetingMode).toBe('digital')
		expect(meetingPayload({ ...FORM, endTime: '' }).endDate).toBeUndefined()
	})

	it('agenda points are numbered in order, blanks dropped, each valid against agenda-item', () => {
		const items = agendaPayloads('m-1', FORM.agenda)
		expect(items.map((i) => [i.orderNumber, i.title])).toEqual([
			[1, 'Opening en kennismaking'],
			[2, 'Stand van zaken subsidie wijkcentrum'],
		])
		const validate = validatorFor('agenda-item')
		for (const item of items) {
			expect(item.meeting).toBe('m-1')
			expect(validate(item), JSON.stringify(validate.errors)).toBe(true)
		}
	})

	it('a colleague joins through a meeting-attendance record, the same shape a guest gets', () => {
		const [record] = attendancePayloads('m-1', FORM.colleagues)
		expect(record).toEqual({ meeting: 'm-1', participant: 'p-lisa' })
		const validate = validatorFor('meeting-attendance')
		expect(validate(record), JSON.stringify(validate.errors)).toBe(true)
	})

	it('title, date and place are required; Talk only is enough without a place', () => {
		expect(missingFields({ ...FORM, title: ' ', start: '' })).toEqual([
			'title',
			'start',
		])
		expect(missingFields({ ...FORM, location: '', talk: false })).toEqual([
			'location',
		])
		expect(missingFields({ ...FORM, location: '' })).toEqual([])
		expect(missingFields({ ...FORM, endTime: '14:00' })).toEqual(['endTime'])
	})
})

describe('Overleg aanmaken runs the steps in an order the invitation can rely on', () => {
	it('meeting, agenda, colleagues, papers, Talk, and guests last so their mail lists the agenda', async () => {
		const { api, calls } = recordingApi()
		const result = await createAdhocMeeting(FORM, api)
		expect(result).toEqual({ meetingId: 'meeting-1', failures: [] })
		expect(calls.map((c) => c[0] + (c[0] === 'save' ? `:${c[1]}` : ''))).toEqual(
			[
				'save:meeting',
				'save:agenda-item',
				'save:agenda-item',
				'save:meeting-attendance',
				'papers',
				'talk',
				'guest',
			],
		)
		expect(calls[5]).toEqual(['talk', 'meeting-1', FORM.title, ['lisa']])
		expect(calls[6]).toEqual([
			'guest',
			'meeting-1',
			'k.ouali@wijkcentrumzuid.nl',
		])
	})

	it('no Talk room is made when Talk is not chosen, and no upload without papers', async () => {
		const { api } = recordingApi()
		await createAdhocMeeting({ ...FORM, talk: false, papers: [] }, api)
		expect(api.createTalkRoom).not.toHaveBeenCalled()
		expect(api.uploadPapers).not.toHaveBeenCalled()
	})

	it('a step that fails after the meeting exists is reported, and the rest still runs', async () => {
		const { api } = recordingApi({
			uploadPapers: vi.fn(async () => {
				throw new Error('quota')
			}),
			inviteGuest: vi.fn(async () => ({ mailed: false })),
		})
		const result = await createAdhocMeeting(FORM, api)
		expect(result.meetingId).toBe('meeting-1')
		expect(result.failures).toEqual([
			{ step: 'papers', detail: 'quota' },
			{ step: 'mail', detail: 'k.ouali@wijkcentrumzuid.nl' },
		])
		expect(api.createTalkRoom).toHaveBeenCalled()
	})

	it('when the meeting itself cannot be saved, nothing else is written', async () => {
		const { api } = recordingApi({
			saveObject: vi.fn(async () => {
				throw new Error('refused')
			}),
		})
		await expect(createAdhocMeeting(FORM, api)).rejects.toThrow('refused')
		expect(api.inviteGuest).not.toHaveBeenCalled()
	})
})

describe('papers go to the meeting folder through OpenRegister', () => {
	it('posts every file as files[] to the meeting multipart route', async () => {
		const fetchMock = vi.fn(async () => ({ ok: true, json: async () => ({}) }))
		vi.stubGlobal('fetch', fetchMock)
		vi.stubGlobal('window', { OC: { requestToken: 'tok' } })
		const a = new File(['a'], 'a.pdf')
		const b = new File(['b'], 'b.xlsx')
		await uploadPapers('m 1', [a, b])
		const [url, options] = fetchMock.mock.calls[0]
		expect(url).toBe(
			'/index.php/apps/openregister/api/objects/decidiq/meeting/m%201/filesMultipart',
		)
		expect(options.method).toBe('POST')
		expect(options.body.getAll('files[]').map((f) => f.name)).toEqual([
			'a.pdf',
			'b.xlsx',
		])
	})
})

describe('Talk-gesprek aanmaken', () => {
	it('creates the room through the meeting Talk link and adds each colleague', async () => {
		const fetchMock = vi.fn(async (url) => ({
			ok: true,
			json: async () =>
				String(url).endsWith('/talk/new')
					? { link: { roomToken: 'abc' } }
					: {},
		}))
		vi.stubGlobal('fetch', fetchMock)
		vi.stubGlobal('window', { OC: { requestToken: 'tok' } })
		const token = await createTalkRoom('m-1', 'Overleg', ['lisa', 'jan'])
		expect(token).toBe('abc')
		const urls = fetchMock.mock.calls.map((c) => c[0])
		expect(urls[0]).toBe(
			'/index.php/apps/openregister/api/objects/decidiq/meeting/m-1/talk/new',
		)
		expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({
			roomName: 'Overleg',
			roomType: 2,
		})
		expect(urls.slice(1)).toEqual([
			'/ocs/v2.php/apps/spreed/api/v4/room/abc/participants',
			'/ocs/v2.php/apps/spreed/api/v4/room/abc/participants',
		])
		expect(JSON.parse(fetchMock.mock.calls[2][1].body)).toEqual({
			newParticipant: 'jan',
			source: 'users',
		})
	})
})

describe('the page is reachable and drawn as the board', () => {
	it('the manifest declares /meetings/new above the meeting detail route', () => {
		const pages = JSON.parse(read('src/manifest.json')).pages
		const ids = pages.map((p) => p.id)
		const page = pages.find((p) => p.id === 'AdhocMeetingNew')
		expect(page).toMatchObject({
			route: '/meetings/new',
			type: 'custom',
			component: 'AdhocMeetingPage',
		})
		expect(ids.indexOf('AdhocMeetingNew')).toBeLessThan(
			ids.indexOf('MeetingDetail'),
		)
		expect(read('src/registry.js')).toMatch(
			/AdhocMeetingPage: page\(AdhocMeetingPage\)/,
		)
	})

	it('the meetings index offers New meeting, and the page has the board sections', () => {
		expect(read('src/views/meetings/MeetingViewToggle.vue')).toMatch(
			/name: 'AdhocMeetingNew'/,
		)
		const vue = read('src/views/meetings/AdhocMeetingPage.vue')
		for (const testid of [
			'adhoc-title',
			'adhoc-start',
			'adhoc-location',
			'adhoc-talk',
			'adhoc-agenda-add',
			'adhoc-papers',
			'adhoc-guest-add',
			'adhoc-colleague-add',
			'adhoc-cancel',
			'adhoc-create',
		]) {
			expect(vue).toContain(`data-testid="${testid}"`)
		}
		expect(vue).toMatch(/createAdhocMeeting\(/)
	})

	it('the guest dialog only stages a guest while the meeting does not exist yet', () => {
		const dialog = read('src/dialogs/GuestInviteDialog.vue')
		expect(dialog).toMatch(/meetingId: \{ type: String, default: '' \}/)
		expect(dialog).toMatch(
			/if \(!this\.meetingId\) \{\s*this\.stage\(\)\s*return/,
		)
		expect(dialog).toMatch(
			/\$emit\('staged', \{ name: this\.name\.trim\(\) \|\| email, email \}\)/,
		)
		expect(read('src/views/meetings/AdhocMeetingPage.vue')).toMatch(
			/@staged="stageGuest"/,
		)
	})
})
