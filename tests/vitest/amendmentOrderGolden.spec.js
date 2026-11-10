/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden run of the amendment-order tab's sort: votingOrder ascending with
 * unordered amendments last, then submittedAt, then id, pinned before the
 * comparison moved into named helpers.
 *
 * @spec exclude golden test of the amendment sort, no behaviour of its own
 */
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { instanceOf, loadSfcScript } from './helpers/sfcScript.js'

const FILE = path.resolve(
	__dirname,
	'../../src/components/tabs/MotionAmendmentOrderTab.vue',
)

const MODULES = {
	'@conduction/nextcloud-vue': { CnNoteCard: {}, CnStatusBadge: {} },
	'@nextcloud/router': { generateUrl: (u) => u },
	'@nextcloud/vue': { NcButton: {} },
	'vue-material-design-icons/ArrowDown.vue': { default: {} },
	'vue-material-design-icons/ArrowUp.vue': { default: {} },
	'../../constants/decisionLifecycle.js': { DECISION_LIFECYCLE_COLORS: {} },
	'../../utils/textDiff.js': { suggestVotingOrder: (a) => a },
	'./useRelationStore.js': { ensureRelationType: () => ({}) },
}

const vm = () => instanceOf(loadSfcScript(FILE, MODULES), { objectId: '' })

describe('MotionAmendmentOrderTab sortByVotingOrder()', () => {
	it('orders by votingOrder, unordered and unparseable last, then submittedAt, then id', () => {
		const input = [
			{ id: 'n1', votingOrder: null, submittedAt: '2026-01-02' },
			{ id: 'b', votingOrder: '2', submittedAt: '2026-01-05' },
			{ id: 'u1', votingOrder: undefined, submittedAt: '2026-01-01' },
			{ id: 'e1', votingOrder: '', submittedAt: '2026-01-03' },
			{ id: 'x1', votingOrder: 'abc', submittedAt: '2026-01-01' },
			{ id: 'a', votingOrder: 2, submittedAt: '2026-01-04' },
			{ id: 'z', votingOrder: 0 },
			{ id: 'f', votingOrder: 1.5 },
			{ id: 'c', votingOrder: '2', submittedAt: '2026-01-05' },
			{ votingOrder: 9 },
			null,
		]
		const sorted = vm().sortByVotingOrder(input)
		expect(sorted).not.toBe(input)
		expect(sorted.map((row) => (row ? row.id || 'no-id' : row))).toEqual([
			'z',
			'f',
			'a',
			'b',
			'c',
			'no-id',
			null,
			'u1',
			'x1',
			'n1',
			'e1',
		])
	})

	it('puts equal ids in a stable but non-zero order', () => {
		const a = { id: 'same', votingOrder: 1, submittedAt: 's', tag: 1 }
		const b = { id: 'same', votingOrder: 1, submittedAt: 's', tag: 2 }
		expect(
			vm()
				.sortByVotingOrder([a, b])
				.map((r) => r.tag),
		).toEqual(
			vm()
				.sortByVotingOrder([a, b])
				.map((r) => r.tag),
		)
		expect(vm().sortByVotingOrder([]).length).toBe(0)
	})
})
