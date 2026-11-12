import { generateUrl } from '@nextcloud/router'
/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden runs of the meeting votes tab's round rows and faction lines: the
 * tally a round row carries (missing counts read as 0, the closing time
 * before the opening time) and the per-faction summary read from the
 * breakdown endpoint, over open, secret, refused, unreadable and failing
 * answers. Pinned before those helpers were split into named steps.
 *
 * @spec exclude golden test of the meeting votes tab, no behaviour of its own
 */
import * as path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import * as voteBreakdown from '../../src/utils/voteBreakdown.js'
import { instanceOf, loadSfcScript } from './helpers/sfcScript.js'

const FILE = path.resolve(
	__dirname,
	'../../src/components/tabs/MeetingVotesTab.vue',
)

/**
 * Build the tab.
 *
 * @return {object} The instance.
 */
function tab() {
	const vm = instanceOf(
		loadSfcScript(FILE, {
			'@conduction/nextcloud-vue': {
				CnDataTable: {},
				CnNoteCard: {},
				CnStatusBadge: {},
			},
			'@nextcloud/router': { generateUrl },
			'../../utils/voteBreakdown.js': voteBreakdown,
			'./useRelationStore.js': { ensureRelationType: () => ({}) },
		}),
		{ objectId: 'mt-1' },
	)
	vm.t = (_app, text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (_m, key) => `<${vars[key]}>`)
	return vm
}

const BREAKDOWN = {
	secret: false,
	factions: [
		{ faction: 'A', for: 3, against: 1, abstain: 0 },
		{ faction: 'B', for: 0, against: 2, abstain: 1 },
	],
	members: [],
}

afterEach(() => {
	delete globalThis.fetch
	delete globalThis.window
})

describe('MeetingVotesTab golden runs', () => {
	it('factionSummary', async () => {
		const out = []
		for (const [label, answer] of [
			['open', { ok: true, body: BREAKDOWN }],
			['secret', { ok: true, body: { secret: true, factions: BREAKDOWN.factions } }],
			['no factions', { ok: true, body: { factions: [] } }],
			['refused', { ok: false, body: BREAKDOWN }],
			['null body', { ok: true, body: null }],
			['unreadable', { ok: true, body: new Error('bad json') }],
			['throws', new Error('offline')],
		]) {
			const log = []
			globalThis.window = { OC: { requestToken: 'tok' } }
			globalThis.fetch = vi.fn(async (url, options) => {
				log.push([url, options])
				if (answer instanceof Error) throw answer
				return {
					ok: answer.ok,
					json: async () => {
						if (answer.body instanceof Error) throw answer.body
						return answer.body
					},
				}
			})
			out.push({ label, log, summary: await tab().factionSummary('r-1') })
		}
		expect(out).toMatchSnapshot()
	})

	it('roundRow', async () => {
		const vm = tab()
		vm.factionSummary = async (roundId) => `factions of ${roundId}`
		const out = []
		for (const [motion, round] of [
			[{ id: 'm1', title: 'Motion A', motionType: 'motie' }, { id: 'r1', votesFor: 5, votesAgainst: 0, votesAbstain: 2, result: 'adopted', closedAt: 'c', openedAt: 'o' }],
			[{ uuid: 'm2' }, { uuid: 'r2', openedAt: 'o' }],
			[{}, { id: 'r3', votesFor: null, votesAgainst: undefined, result: '', closedAt: '' }],
		]) {
			out.push(await vm.roundRow(motion, round))
		}
		expect(out).toMatchSnapshot()
	})
})
