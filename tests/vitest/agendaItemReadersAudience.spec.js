/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The readers widget on a closed agenda item reads the body of the item's
 * meeting so it can say who may read the item (board DcAgendapunt).
 *
 * @spec openspec/changes/agenda-item-confidential-papers/specs/agenda-item-confidentiality/spec.md#requirement-req-acp-001-an-agenda-item-and-each-paper-say-whether-they-are-public
 */
import * as path from 'path'
import { describe, expect, it, vi } from 'vitest'
import * as readers from '../../src/utils/confidentialReaders.js'
import { instanceOf, loadSfcScript } from './helpers/sfcScript.js'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => `/index.php${p}` }))

const FILE = path.resolve(__dirname, '../../src/components/tabs/AgendaItemReadersTab.vue')

/**
 * Build the widget over a recording axios.
 *
 * @param {Object<string, *>} answers The answer per URL, an Error to fail it.
 * @return {{vm: object, calls: string[]}}
 */
function widget(answers) {
	const calls = []
	const axios = {
		get: vi.fn(async (url) => {
			calls.push(url)
			const answer = answers[url]
			if (answer instanceof Error || answer === undefined) throw answer || new Error(`unexpected ${url}`)
			return { data: answer }
		}),
	}
	const vm = instanceOf(
		loadSfcScript(FILE, {
			'@nextcloud/axios': { default: axios },
			'@nextcloud/vue': { NcButton: {}, NcCheckboxRadioSwitch: {}, NcLoadingIcon: {}, NcSelect: {}, NcTextField: {} },
			'../../utils/confidentialReaders.js': readers,
			'./objectIdLoader.js': { objectIdLoader: {} },
		}),
		{ objectId: 'item-7' },
	)
	return { vm, calls }
}

const OBJECTS = '/apps/openregister/api/objects/decidiq'

describe('the closed item names its meeting\'s body', () => {
	it('reads the meeting and its body for a closed item', async () => {
		const { vm, calls } = widget({
			[`${OBJECTS}/meeting/m-1`]: { id: 'm-1', governanceBody: { id: 'gb-1' } },
			[`${OBJECTS}/governance-body/gb-1`]: { id: 'gb-1', name: 'Gemeenteraad' },
		})
		vm.item = { public: false, meeting: 'm-1' }
		await vm.loadBody()
		expect(calls).toEqual([`${OBJECTS}/meeting/m-1`, `${OBJECTS}/governance-body/gb-1`])
		expect(readers.closedItemAudience(vm.item, vm.body)).toEqual({ bodyName: 'Gemeenteraad' })
	})

	it('reads nothing for a public item or an item without a meeting', async () => {
		const { vm, calls } = widget({})
		vm.item = { public: true, meeting: 'm-1' }
		await vm.loadBody()
		vm.item = { public: false }
		await vm.loadBody()
		expect(calls).toEqual([])
		expect(vm.body).toBeNull()
	})

	it('stops at a meeting without a body', async () => {
		const { vm, calls } = widget({ [`${OBJECTS}/meeting/m-1`]: { id: 'm-1' } })
		vm.item = { public: false, meeting: { id: 'm-1' } }
		await vm.loadBody()
		expect(calls).toEqual([`${OBJECTS}/meeting/m-1`])
		expect(vm.body).toBeNull()
	})

	it('keeps the line without a name when a read fails', async () => {
		const { vm } = widget({
			[`${OBJECTS}/meeting/m-1`]: { id: 'm-1', governanceBody: 'gb-1' },
			[`${OBJECTS}/governance-body/gb-1`]: new Error('403'),
		})
		vm.item = { public: false, meeting: 'm-1' }
		vm.body = { name: 'stale' }
		await vm.loadBody()
		expect(vm.body).toBeNull()
		expect(readers.closedItemAudience(vm.item, vm.body)).toEqual({ bodyName: '' })
	})
})
