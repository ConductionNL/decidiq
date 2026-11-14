/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden runs of the publication settings page's `loadBodies()`: the store
 * call it makes and the per-body configuration it seeds from the stored
 * configuration, over full, partial and missing entries and a failing store.
 * Pinned before the seeding moved into a named helper.
 *
 * @spec exclude golden test of the settings loader, no behaviour of its own
 */
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { instanceOf, loadSfcScript, recordingStores } from './helpers/sfcScript.js'

const FILE = path.resolve(
	__dirname,
	'../../src/views/settings/PublicationSettings.vue',
)

/**
 * Run loadBodies() with a stored configuration and recorded stores.
 *
 * @param {object} initialConfig The stored publication configuration.
 * @param {(call: object) => *} answer The store answers.
 *
 * @return {Promise<object>} The calls and the resulting state.
 */
async function run(initialConfig, answer) {
	const stores = recordingStores(answer)
	const component = loadSfcScript(FILE, {
		'@conduction/nextcloud-vue': { CnNoteCard: {} },
		'@nextcloud/initial-state': {
			loadState: (_app, key, fallback) =>
				key === 'publicationConfig' ? initialConfig : fallback,
		},
		'@nextcloud/router': { generateUrl: (u) => u },
		'@nextcloud/vue': { NcButton: {}, NcLoadingIcon: {}, NcSelect: {} },
		'../../components/tabs/useRelationStore.js': {
			ensureRelationType: stores.ensureRelationType,
		},
	})
	const vm = instanceOf(component, {})
	await vm.loadBodies()
	return {
		calls: stores.calls,
		state: {
			loading: vm.loading,
			error: vm.error,
			bodies: vm.bodies,
			config: vm.config,
		},
	}
}

describe('PublicationSettings loadBodies()', () => {
	it('seeds every body from its stored entry, defaulting what is missing', async () => {
		const result = await run(
			{
				b1: {
					catalog: 'cat-1',
					policy: {
						decision: 'prompt-on-transition',
						agenda: '',
						minutes: 'prompt-on-transition',
					},
					attendance: 'role-holders',
				},
				b2: { policy: null },
			},
			() => [{ id: 'b1' }, { id: 'b2' }, { id: 'b3' }],
		)
		expect(result).toMatchSnapshot()
	})

	it('keeps an empty list when the store answers null', async () => {
		const result = await run({}, () => null)
		expect(result.state).toEqual({
			loading: false,
			error: '',
			bodies: [],
			config: {},
		})
	})

	it('reports a failing store, falling back to the default message', async () => {
		const loud = await run({}, () => {
			throw new Error('boom')
		})
		expect(loud.state.error).toBe('boom')
		const silent = await run({}, () => {
			throw new Error('')
		})
		expect(silent.state).toEqual({
			loading: false,
			error: 'Failed to load governance bodies.',
			bodies: [],
			config: {},
		})
	})
})
