/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * capability-rows-get-requirements 3.3 (bod-14): the voting round panel
 * offers only the methods the meeting's body allows. The helpers run for
 * real; the panel's stores are doubles that record what was read.
 *
 * @spec openspec/changes/capability-rows-get-requirements/specs/meeting-management/spec.md#requirement-a-body-limits-which-voting-methods-its-meetings-may-use
 */

import * as path from 'path'
import { describe, expect, it } from 'vitest'
import * as objectRelations from '../../src/utils/objectRelations.js'
import * as rankedBallot from '../../src/utils/rankedBallot.js'
import * as votingGroups from '../../src/utils/votingGroups.js'
import * as votingMethods from '../../src/utils/votingMethods.js'
import * as votingPermissions from '../../src/utils/votingPermissions.js'
import * as votingRules from '../../src/utils/votingRules.js'
import { instanceOf, loadSfcScript, recordingStores } from './helpers/sfcScript.js'

const { VOTING_METHODS, allowedVotingMethods, meetingBodyId, startingVotingMethod } = votingMethods
const FILE = path.resolve(__dirname, '../../src/components/VotingRoundPanel.vue')

/**
 * A panel over a meeting store and a body store.
 *
 * @param {object} options `meetingId`, `meeting` (what the meeting read answers, or an Error) and `body`.
 * @return {{vm: object, reads: Array<object>, calls: Array<object>}} The instance and what it read.
 */
function panel({ meetingId = 'meet-1', meeting = { governanceBody: 'body-1' }, body = {} } = {}) {
	const reads = []
	const stores = recordingStores(() => {
		if (body instanceof Error) throw body
		return body
	})
	const objectStore = {
		async fetchObject(schema, id) {
			reads.push({ schema, id })
			if (meeting instanceof Error) throw meeting
			return meeting
		},
	}
	const component = loadSfcScript(FILE, {
		'@conduction/nextcloud-vue': { CnDetailCard: {}, CnStatusBadge: {} },
		'@nextcloud/router': { generateUrl: (u) => `/nc${u}` },
		'@nextcloud/vue': { NcButton: {}, NcSelect: {}, NcTextField: {} },
		'../dialogs/RecordVotesDialog.vue': { default: {} },
		'./RankedBallot.vue': { default: {} },
		'./RankedResultsCard.vue': { default: {} },
		'../store/store.js': { useObjectStore: () => objectStore },
		'../utils/conflicts.js': { eligibleCount: () => 0 },
		'../utils/objectRelations.js': objectRelations,
		'../utils/rankedBallot.js': rankedBallot,
		'../utils/votingGroups.js': votingGroups,
		'../utils/votingMethods.js': votingMethods,
		'../utils/votingPermissions.js': votingPermissions,
		'../utils/votingRules.js': votingRules,
		'./tabs/useRelationStore.js': { ensureRelationType: stores.ensureRelationType },
	})
	const vm = instanceOf(component, { motionId: 'm1', subjectType: 'motion', meetingId })
	vm.objectStore = objectStore
	vm.methodLabels = component.computed.methodLabels.call(vm)
	return { vm, reads, calls: stores.calls }
}

describe('the methods a body allows', () => {
	it('keeps the panel order and drops what it does not know', () => {
		expect(allowedVotingMethods({ allowedVotingMethods: ['weighted', 'show-of-hands', 'secret-paper'] })).toEqual(['show-of-hands', 'weighted'])
	})

	it('offers every method when the body names none', () => {
		for (const body of [null, {}, { allowedVotingMethods: [] }, { allowedVotingMethods: 'weighted' }, { allowedVotingMethods: ['nope'] }]) {
			expect(allowedVotingMethods(body)).toEqual([...VOTING_METHODS])
		}
	})

	it('starts a round on the current pick when allowed, else on the first allowed', () => {
		expect(startingVotingMethod('weighted', ['show-of-hands', 'weighted'])).toBe('weighted')
		expect(startingVotingMethod('for-against-abstain', ['show-of-hands', 'weighted'])).toBe('show-of-hands')
	})

	it('reads the body of a meeting from its reference', () => {
		expect(meetingBodyId({ governanceBody: 'b-1' })).toBe('b-1')
		expect(meetingBodyId({ governanceBody: { id: 'b-2' } })).toBe('b-2')
		expect(meetingBodyId({ governanceBody: { uuid: 'b-3' } })).toBe('b-3')
		expect(meetingBodyId({})).toBe('')
		expect(meetingBodyId(null)).toBe('')
	})
})

describe('the voting round panel', () => {
	it('offers only the methods the meeting\'s body allows, and starts on one of them', async () => {
		const { vm, reads, calls } = panel({ body: { allowedVotingMethods: ['show-of-hands', 'weighted'] } })

		await vm.loadAllowedMethods()

		expect(reads).toEqual([{ schema: 'meeting', id: 'meet-1' }])
		expect(calls.map((c) => [c.fn, c.type, c.schema, c.id])).toEqual([['fetchObject', 'governance-body', 'governance-body', 'body-1']])
		expect(vm.allowedMethods).toEqual(['show-of-hands', 'weighted'])
		expect(vm.newRound.votingMethod).toBe('show-of-hands')
		expect(vm.allowedMethods.map((m) => vm.methodLabels[m])).toEqual(['Show of hands', 'Weighted vote'])
	})

	it('offers every method without a meeting, without a body, or when the body cannot be read', async () => {
		const cases = [
			panel({ meetingId: '' }),
			panel({ meeting: {} }),
			panel({ meeting: new Error('gone') }),
			panel({ body: new Error('gone') }),
		]
		for (const { vm } of cases) {
			await vm.loadAllowedMethods()
			expect(vm.allowedMethods).toEqual([...VOTING_METHODS])
			expect(vm.newRound.votingMethod).toBe('for-against-abstain')
		}
		expect(cases[0].reads).toEqual([])
		expect(cases[1].calls).toEqual([])
	})

	it('names every method it can offer', () => {
		const { vm } = panel()
		expect(Object.keys(vm.methodLabels)).toEqual([...VOTING_METHODS])
	})
})
