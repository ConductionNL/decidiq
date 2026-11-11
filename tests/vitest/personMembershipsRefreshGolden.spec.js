/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden runs of the person memberships tab's `refresh()`: the person, their
 * memberships and the bodies they sit on, with a body that cannot be read,
 * a membership without a body, no memberships and a failing store. Pinned
 * before refresh() was split into named steps.
 *
 * @spec exclude golden test of the memberships tab loader, no behaviour of its own
 */
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import * as memberProfile from '../../src/utils/memberProfile.js'
import { instanceOf, loadSfcScript } from './helpers/sfcScript.js'

globalThis.t = (_app, text) => text

const FILE = path.resolve(
	__dirname,
	'../../src/components/tabs/PersonMembershipsTab.vue',
)

/**
 * Run refresh() over a recording store.
 *
 * @param {object} data The store's answers.
 * @return {Promise<object>} The calls and the state.
 */
async function run(data) {
	const calls = []
	const store = (type) => ({
		async fetchCollection(schema, params) {
			calls.push({ fn: 'fetchCollection', type, schema, params })
			if (data.fail) throw new Error(data.silent ? '' : 'store down')
			return data.memberships
		},
		async fetchObject(schema, id) {
			calls.push({ fn: 'fetchObject', type, schema, id })
			if (id === 'body-broken') throw new Error('gone')
			return schema === 'person' ? data.person : data.bodies[id]
		},
	})
	const component = loadSfcScript(FILE, {
		'@conduction/nextcloud-vue': { CnNoteCard: {} },
		'../../utils/memberProfile.js': memberProfile,
		'./useRelationStore.js': { ensureRelationType: store },
	})
	const vm = instanceOf(component, { objectId: 'p-1' })
	vm.photoFailed = true
	await vm.refresh()
	const state = {}
	for (const key of Object.keys(component.data())) state[key] = vm[key]
	return { calls, state }
}

const DATA = {
	person: { id: 'p-1', name: 'Anna' },
	memberships: [
		{
			id: 'm1',
			governanceBody: 'body-1',
			role: 'member',
			startDate: '2024-01-01',
		},
		{
			id: 'm2',
			governanceBody: 'body-broken',
			role: 'chair',
			startDate: '2023-01-01',
		},
		{
			id: 'm3',
			governanceBody: 'body-1',
			role: 'member',
			startDate: '2020-01-01',
			endDate: '2021-01-01',
		},
		{ id: 'm4', role: 'guest' },
	],
	bodies: { 'body-1': { id: 'body-1', name: 'Gemeenteraad' } },
}

describe('PersonMembershipsTab refresh()', () => {
	it('loads the person, their memberships and their bodies', async () => {
		expect(await run(DATA)).toMatchSnapshot()
		expect(await run({ ...DATA, memberships: null })).toMatchSnapshot()
	})

	it('reports a failing store, falling back to the default message', async () => {
		expect((await run({ ...DATA, fail: true })).state.error).toBe('store down')
		expect(
			(await run({ ...DATA, fail: true, silent: true })).state,
		).toMatchSnapshot()
	})
})
