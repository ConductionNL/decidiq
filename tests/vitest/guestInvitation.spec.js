// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * meeting-ad-hoc-with-guests (matrix row pla-20): the organiser of an ad hoc
 * meeting invites a guest from outside by email.
 *
 * @spec openspec/changes/meeting-ad-hoc-with-guests/specs/meeting-management/spec.md#requirement-req-mah-001-an-organiser-runs-their-own-ad-hoc-meeting
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { guestPath, inviteGuest, isGuest } from '../../src/utils/guestInvitation.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('a project lead invites an outside advisor (REQ-MAH-001)', () => {
	it('posts the trimmed name and email to the meeting guest route', async () => {
		const fetchMock = vi.fn(async () => ({
			ok: true,
			json: async () => ({
				participant: 'p1',
				attendance: 'a1',
				mailed: true,
			}),
		}))
		vi.stubGlobal('fetch', fetchMock)
		vi.stubGlobal('window', { OC: { requestToken: 'tok' } })
		const result = await inviteGuest('m-1', {
			name: ' Advisor ',
			email: ' advisor@example.org ',
		})
		expect(result.mailed).toBe(true)
		const [url, options] = fetchMock.mock.calls[0]
		expect(url).toBe('/index.php/apps/decidiq/api/meetings/m-1/guests')
		expect(options.method).toBe('POST')
		expect(JSON.parse(options.body)).toEqual({
			name: 'Advisor',
			email: 'advisor@example.org',
		})
	})

	it('a refusal throws the server message', async () => {
		vi.stubGlobal(
			'fetch',
			vi.fn(async () => ({
				ok: false,
				statusText: 'Forbidden',
				json: async () => ({
					message: 'Only the organiser of this meeting can invite guests.',
				}),
			})),
		)
		vi.stubGlobal('window', { OC: {} })
		await expect(
			inviteGuest('m-1', { name: '', email: 'a@example.org' }),
		).rejects.toThrow('Only the organiser')
	})

	it('a guest is a participant with role guest and no Nextcloud account', () => {
		expect(isGuest({ role: 'guest' })).toBe(true)
		expect(isGuest({ role: 'guest', nextcloudUserId: 'pieter' })).toBe(false)
		expect(isGuest({ role: 'member' })).toBe(false)
		expect(guestPath('a b')).toBe('/apps/decidiq/api/meetings/a%20b/guests')
	})

	it('the participants tab offers Invite a guest on a meeting without a body only', () => {
		const tab = read('src/components/tabs/MeetingParticipantsTab.vue')
		expect(tab).toMatch(/v-if="adHoc"/)
		expect(tab).toMatch(/this\.adHoc = !bodyId/)
		expect(tab).toMatch(
			/import GuestInviteDialog from '..\/..\/dialogs\/GuestInviteDialog.vue'/,
		)
		expect(read('src/dialogs/GuestInviteDialog.vue')).toMatch(
			/inviteGuest\(this\.meetingId/,
		)
	})

	it('the route is registered for the controller', () => {
		expect(read('appinfo/routes.php')).toMatch(
			/'guestInvitation#invite',\s*'url' => '\/api\/meetings\/\{id\}\/guests',\s*'verb' => 'POST'/,
		)
	})
})
