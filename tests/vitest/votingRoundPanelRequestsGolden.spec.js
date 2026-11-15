/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden runs of the voting round panel's write calls: the URL, method,
 * headers and body of every request, and the state each leaves behind on
 * success, on a refusal with and without a message, and on a network
 * failure. Pinned before the calls shared one request builder.
 *
 * @spec exclude golden test of the voting round panel requests, no behaviour of its own
 */
import * as path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import * as objectRelations from '../../src/utils/objectRelations.js'
import * as rankedBallot from '../../src/utils/rankedBallot.js'
import * as votingGroups from '../../src/utils/votingGroups.js'
import * as votingPermissions from '../../src/utils/votingPermissions.js'
import * as votingRules from '../../src/utils/votingRules.js'
import { instanceOf, loadSfcScript } from './helpers/sfcScript.js'

const FILE = path.resolve(__dirname, '../../src/components/VotingRoundPanel.vue')

const STATE = [
	'castVoteError',
	'voteCast',
	'openRoundError',
	'openingRound',
	'showOpenRoundDialog',
	'revoteOfRoundId',
	'chairCastingError',
	'castingRanking',
	'activeProxy',
	'showProxyDialog',
	'oriStatus',
	'confirmCloseRound',
]

let calls

/**
 * A panel instance over recording globals.
 *
 * @param {object} answer How fetch answers: { ok, json } or { throws: true }.
 * @return {object} The instance.
 */
function panel(answer) {
	const component = loadSfcScript(FILE, {
		'@conduction/nextcloud-vue': { CnDetailCard: {}, CnStatusBadge: {} },
		'@nextcloud/router': { generateUrl: (u) => `/nc${u}` },
		'@nextcloud/vue': { NcButton: {}, NcSelect: {}, NcTextField: {} },
		'../dialogs/RecordVotesDialog.vue': { default: {} },
		'./RankedBallot.vue': { default: {} },
		'./RankedResultsCard.vue': { default: {} },
		'../store/store.js': { useObjectStore: () => ({}) },
		'../utils/conflicts.js': { eligibleCount: () => 0 },
		'../utils/objectRelations.js': objectRelations,
		'../utils/rankedBallot.js': rankedBallot,
		'../utils/votingPermissions.js': votingPermissions,
		'../utils/votingRules.js': votingRules,
		'../utils/votingGroups.js': votingGroups,
		'./tabs/useRelationStore.js': { ensureRelationType: () => ({}) },
	})
	const vm = instanceOf(component, {
		motionId: 'm1',
		subjectType: 'motion',
		meetingId: 'meet-1',
	})
	for (const [name, fn] of Object.entries(component.computed || {})) {
		if (!(name in vm) && name !== 'roundId')
			Object.defineProperty(vm, name, {
				get: () => fn.call(vm),
				configurable: true,
			})
	}
	vm.roundId = 'r1'
	vm.proxyToId = 'p-to'
	vm.showOfHands = { for: 3, against: 2, abstain: 1 }
	vm.fetchCurrentRound = async () => calls.push({ fetchCurrentRound: true })
	vi.stubGlobal('fetch', async (url, init) => {
		calls.push({ url, init })
		if (answer.throws) throw new Error('offline')
		return { ok: answer.ok, json: async () => answer.json }
	})
	return vm
}

/**
 * Run one method and keep the requests and the state.
 *
 * @param {string} method The method name.
 * @param {Array} args Its arguments.
 * @param {object} answer How fetch answers.
 * @return {Promise<object>} The requests and the state.
 */
async function run(method, args, answer) {
	calls = []
	const vm = panel(answer)
	await vm[method](...args)
	const state = {}
	for (const key of STATE) state[key] = vm[key]
	return { calls, state }
}

const ANSWERS = {
	ok: { ok: true, json: { status: 'published' } },
	refused: { ok: false, json: { message: 'Not allowed' } },
	silent: { ok: false, json: {} },
	offline: { throws: true },
}

const METHODS = [
	['castVote', ['for']],
	['castRanking', [['a', 'b']]],
	['openRound', []],
	['closeRound', []],
	['castChairVote', ['against']],
	['saveShowOfHands', []],
	['grantProxy', []],
	['revokeProxy', []],
	['publishToOri', []],
]

beforeEach(() => {
	vi.stubGlobal('OC', {
		generateUrl: (u) => `/oc${u}`,
		requestToken: 'tok',
		currentUser: 'alice',
	})
	globalThis.t = (_app, text) => text
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('VotingRoundPanel write calls', () => {
	for (const [method, args] of METHODS) {
		it(`${method} sends the same request and leaves the same state`, async () => {
			const results = {}
			for (const [name, answer] of Object.entries(ANSWERS)) {
				results[name] = await run(method, args, answer)
			}
			expect(results).toMatchSnapshot()
		})
	}
})

describe('VotingRoundPanel voting groups (REQ-VGP-002, REQ-VGP-005)', () => {
	it('opens a round with the chosen group and names the members it left out', async () => {
		calls = []
		const vm = panel({ ok: true, json: { id: 'r1', excludedGroupMembers: [{ id: 'p-10', name: 'Anna Jansen' }] } })
		vm.newRound.votingGroup = 'g-az'

		await vm.openRound()

		expect(JSON.parse(calls[0].init.body).votingGroup).toBe('g-az')
		expect(vm.leftOutNames).toEqual(['Anna Jansen'])
	})

	it('never sends a group with a revote', async () => {
		calls = []
		const vm = panel({ ok: true, json: { id: 'r2' } })
		vm.newRound.votingGroup = 'g-az'
		vm.revoteOfRoundId = 'r1'

		await vm.openRound()

		expect(JSON.parse(calls[0].init.body).votingGroup).toBeNull()
		expect(vm.leftOutNames).toEqual([])
	})

	it('offers the active groups of the meeting body', async () => {
		const vm = panel({ ok: true, json: {} })
		const answers = {
			'/nc/apps/openregister/api/objects/decidiq/meeting/{id}': { governanceBody: 'raad' },
			'/nc/apps/openregister/api/objects/decidiq/voting-group?_limit=100': {
				results: [
					{ id: 'g-az', name: 'Commissie AZ', governanceBody: 'raad', members: ['a', 'b'] },
					{ id: 'g-x', name: 'Elsewhere', governanceBody: 'other', members: ['a'] },
					{ id: 'g-old', name: 'Old', governanceBody: 'raad', active: false },
				],
			},
		}
		const asked = []
		vi.stubGlobal('fetch', async (url) => {
			asked.push(url)
			return { ok: true, json: async () => answers[url] }
		})

		await vm.loadVotingGroups()

		expect(asked).toEqual(Object.keys(answers))
		expect(vm.votingGroupChoices).toEqual([{ id: 'g-az', name: 'Commissie AZ', memberCount: 2 }])
	})

	it('offers no group when the meeting cannot be read', async () => {
		const vm = panel({ ok: true, json: {} })
		vi.stubGlobal('fetch', async () => ({ ok: false, json: async () => ({}) }))

		await vm.loadVotingGroups()

		expect(vm.votingGroupChoices).toEqual([])
	})
})
