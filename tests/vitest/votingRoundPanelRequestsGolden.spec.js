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
import * as votingMethods from '../../src/utils/votingMethods.js'
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
		'../utils/votingMethods.js': votingMethods,
		'../utils/votingPermissions.js': votingPermissions,
		'../utils/votingRules.js': votingRules,
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
