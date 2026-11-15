/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden labels of the approval chain timeline tab: the status badge label
 * and variant of a stage, who it was asked of, and what an action says, over
 * every status, outcome, assignment and action shape. Pinned before those
 * methods were split into named steps.
 *
 * @spec exclude golden test of the approval chain tab labels, no behaviour of its own
 */
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { instanceOf, loadSfcScript } from './helpers/sfcScript.js'

const FILE = path.resolve(
	__dirname,
	'../../src/integrations/CnApprovalChainTab.vue',
)

/**
 * A literal translate double that shows its placeholders filled.
 *
 * @param {string} _app The app.
 * @param {string} text The text.
 * @param {object} [vars] The placeholders.
 * @return {string} The text.
 */
function translate(_app, text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (_m, key) => `<${vars[key]}>`)
}

const vm = instanceOf(
	loadSfcScript(FILE, {
		'@conduction/nextcloud-vue': { CnStatusBadge: {} },
		'@nextcloud/l10n': { translate },
		'@nextcloud/vue': { NcEmptyContent: {}, NcLoadingIcon: {} },
		'vue-material-design-icons/Signature.vue': { default: {} },
		'./approvalChainLink.js': {
			actionsByStep: () => ({}),
			isOverdue: (stage) => stage.dueAt === 'past',
			listActions: async () => [],
			listStages: async () => [],
			objId: (row) => String(row?.id || ''),
			stagePolicyLines: () => [],
		},
	}),
	{ subjectId: 's1' },
)

const STATUSES = [undefined, '', 'active', 'pending', 'decided', 'skipped', 'constructor']
const OUTCOMES = [undefined, '', 'approved', 'rejected', 'returned', 'advised', 'constructor']
const DUE = [undefined, 'past', 'future']

describe('CnApprovalChainTab labels', () => {
	it('labels and colours every stage state', () => {
		const out = []
		for (const status of STATUSES) {
			for (const outcome of OUTCOMES) {
				for (const dueAt of DUE) {
					const stage = { status, outcome, dueAt }
					out.push([status, outcome, dueAt, vm.statusLabel(stage), vm.variantOf(stage)])
				}
			}
		}
		expect(out).toMatchSnapshot()
	})

	it('names who a stage was asked of', () => {
		const out = [
			{},
			{ assignedPerson: 'ann' },
			{ assignedBody: 'board' },
			{ assignedPerson: 'ann', assignedBody: 'board' },
			{ assignedPerson: 'ann', substituteActor: 'bob' },
			{ substituteActor: 'bob' },
			{ assignedPerson: 'ann', substituteActor: 0 },
			{ assignedPerson: 7, substituteActor: 8 },
		].map((stage) => [stage, vm.actorLabel(stage)])
		expect(out).toMatchSnapshot()
	})

	it('says what an action was', () => {
		const out = [
			{},
			{ action: 'lapsed', actor: 'system' },
			{ action: 'approved', actor: 'ann' },
			{ action: 'approved' },
			{ action: 'rejected', actor: 'ann', onBehalfOf: 'bob' },
			{ action: 'approved', onBehalfOf: '' },
			{ action: 'lapsed', onBehalfOf: 'bob' },
		].map((action) => [action, vm.verbLabel(action)])
		expect(out).toMatchSnapshot()
	})
})
