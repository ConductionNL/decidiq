import { generateOcsUrl, generateUrl } from '@nextcloud/router'
/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden runs of the meeting video call tab's server calls: the request
 * wrapper's headers and refusal messages, creating the Talk room and
 * inviting the body's members. Every request and store read is recorded
 * with its arguments, in order, with the state left behind. Pinned before
 * those methods were split into named steps.
 *
 * @spec exclude golden test of the meeting video call tab, no behaviour of its own
 */
import * as path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import * as videoCall from '../../src/utils/videoCall.js'
import { instanceOf, loadSfcScript } from './helpers/sfcScript.js'

const FILE = path.resolve(
	__dirname,
	'../../src/components/tabs/MeetingVideoCallTab.vue',
)

/**
 * Build the tab over a recording fetch and store.
 *
 * @param {object} answers The server's answers by URL part, and the participants.
 * @return {{vm: object, log: Array}} The tab and its log.
 */
function tab(answers) {
	const log = []
	globalThis.OC = { requestToken: 'tok' }
	globalThis.fetch = vi.fn(async (url, options) => {
		log.push(['fetch', url, options])
		const key = Object.keys(answers.server).find((part) => url.includes(part))
		const answer = answers.server[key]
		if (answer instanceof Error) throw answer
		return {
			ok: answer.ok !== false,
			statusText: answer.statusText ?? 'Status',
			json: async () => {
				if (answer.body instanceof Error) throw answer.body
				return answer.body
			},
		}
	})
	const store = (type) => ({
		async fetchCollection(schema, params) {
			log.push(['fetchCollection', type, schema, params])
			return answers.participants
		},
	})
	const vm = instanceOf(
		loadSfcScript(FILE, {
			'@conduction/nextcloud-vue': { CnNoteCard: {} },
			'@nextcloud/router': { generateOcsUrl, generateUrl },
			'@nextcloud/vue': { NcButton: {}, NcTextField: {} },
			'vue-material-design-icons/Video.vue': { default: {} },
			'../../services/agendaRules.js': { canManageAgenda: () => true },
			'../../utils/videoCall.js': videoCall,
			'./objectIdLoader.js': { objectIdLoader: {} },
			'./useRelationStore.js': { ensureRelationType: store },
		}),
		{ objectId: 'mt-1' },
	)
	vm.loadRoom = async () => log.push(['loadRoom'])
	return { vm, log }
}

const MEMBERS = [
	{ governanceBody: 'gb-1', nextcloudUserId: 'ann', role: 'member' },
	{ governanceBody: 'gb-1', nextcloudUserId: 'bob', role: 'chair' },
	{ governanceBody: 'gb-2', nextcloudUserId: 'eve', role: 'member' },
]

afterEach(() => {
	delete globalThis.fetch
	delete globalThis.OC
})

describe('MeetingVideoCallTab golden runs', () => {
	it('request', async () => {
		const out = []
		for (const [label, answer] of [
			['ok', { body: { a: 1 } }],
			['ok unreadable', { body: new Error('x') }],
			['refused with message', { ok: false, body: { message: 'm', error: 'e' } }],
			['refused with error', { ok: false, body: { error: 'e' } }],
			['refused bare', { ok: false, body: {}, statusText: 'Forbidden' }],
			['refused unreadable', { ok: false, body: new Error('x'), statusText: '' }],
		]) {
			const { vm, log } = tab({ server: { '/x': answer } })
			let result
			try {
				result = await vm.request('/x', { method: 'PUT', headers: { a: 'b' }, body: '1' })
			} catch (e) {
				result = `threw: ${e.message}`
			}
			out.push({ label, log, result })
		}
		const { vm, log } = tab({ server: { '/x': { body: {} } } })
		await vm.request('/x')
		out.push({ label: 'no options', log })
		expect(out).toMatchSnapshot()
	})

	it('createRoom and inviteMembers', async () => {
		const out = []
		for (const [label, meeting, server, participants] of [
			['creates and invites', { title: 'Raad', governanceBody: { id: 'gb-1' } }, { '/new': { body: { link: { token: 'tk1' } } }, participants: { body: {} } }, MEMBERS],
			['untitled, body by id, one invite fails', { governanceBody: 'gb-1' }, { '/new': { body: { token: 'tk2' } }, participants: { ok: false, body: {} } }, MEMBERS],
			['no body', { title: 'T' }, { '/new': { body: { token: 'tk3' } } }, MEMBERS],
			['no token', null, { '/new': { body: {} } }, MEMBERS],
			['refused', { title: 'T' }, { '/new': { ok: false, body: { message: 'No Talk' } } }, MEMBERS],
			['refused bare', { title: 'T' }, { '/new': { ok: false, body: {}, statusText: '' } }, MEMBERS],
			['throws', { title: 'T' }, { '/new': new Error('offline') }, MEMBERS],
		]) {
			const { vm, log } = tab({ server, participants })
			vm.meeting = meeting
			vm.error = 'stale'
			await vm.createRoom()
			out.push({ label, log, state: { busy: vm.busy, error: vm.error } })
		}
		expect(out).toMatchSnapshot()
	})
})
