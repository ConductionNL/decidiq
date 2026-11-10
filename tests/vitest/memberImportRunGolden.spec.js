/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the import step of the CSV and the group member-import
 * dialogs: which people and memberships are written, in which order, what
 * the dialog then says and emits, and how a failure ends. Each dialog is
 * evaluated with its mixins merged the way Vue merges them.
 *
 * Record with RECORD_MEMBER_IMPORT_GOLDEN=1.
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it, vi } from 'vitest'
import { loadSfcScript } from './helpers/sfcScript.js'

const GOLDEN = path.resolve(__dirname, '../fixtures/member-import-run-golden.json')
const MODALS = path.resolve(__dirname, '../../src/modals')

const { calls, fail, relationStore } = vi.hoisted(() => {
	const calls = []
	const fail = { at: null }
	const relationStore = {
		ensureRelationType: (type) => {
			calls.push(['ensureRelationType', type])
			return {
				saveObject: async (schema, payload) => {
					calls.push(['saveObject', type, schema, payload])
					if (fail.at === 'membership') {
						throw new Error('membership refused')
					}
				},
			}
		},
		resolveOrCreatePerson: async (store, person) => {
			calls.push(['resolveOrCreatePerson', person])
			if (fail.at === 'person') {
				throw {}
			}
			return person.name === 'Nobody' ? {} : { id: 'person-' + person.email }
		},
		buildMembershipPayload: (fields) => ({ built: fields }),
	}
	return { calls, fail, relationStore }
})

// A sibling module that imports the relation store gets the same double.
vi.mock('../../src/components/tabs/useRelationStore.js', () => relationStore)

/**
 * A dialog's methods with its mixins merged, its imports answered by doubles
 * and the real sibling modules.
 *
 * @param {string} file The dialog file.
 * @return {Promise<object>} The methods.
 */
async function methodsOf(file) {
	const full = path.join(MODALS, file)
	const stub = new Proxy({}, { get: (_t, key) => (key === 'default' ? {} : () => ({})) })
	const modules = {}
	for (const [, spec] of fs.readFileSync(full, 'utf8').matchAll(/^import\s+[\s\S]*?\s+from\s+'([^']+)'/gm)) {
		if (spec === '../components/tabs/useRelationStore.js') {
			modules[spec] = relationStore
		} else if (spec.startsWith('.')) {
			modules[spec] = await import(path.resolve(MODALS, spec))
		} else {
			modules[spec] = stub
		}
	}
	const component = loadSfcScript(full, modules)
	return Object.assign({}, ...[...(component.mixins || []), component].map((part) => part.methods || {}))
}

const PREVIEWS = {
	'MemberCsvImportDialog.vue': [
		{ line: 2, name: 'Anne', email: 'a@x.nl', role: 'chair', status: 'ok', matchedUid: 'anne' },
		{ line: 3, name: 'Bob', email: 'b@x.nl', role: 'member', status: 'duplicate', matchedUid: '' },
		{ line: 4, name: 'Nobody', email: 'n@x.nl', role: 'member', status: 'ok' },
		{ line: 5, name: 'Cas', email: 'c@x.nl', role: 'secretary', status: 'ok', matchedUid: '' },
		{ line: 6, name: '', email: 'bad', role: '', status: 'invalid' },
	],
	'MemberGroupImportDialog.vue': [
		{ uid: 'anne', displayName: 'Anne', email: 'a@x.nl', duplicate: false },
		{ uid: 'bob', displayName: 'Bob', email: 'b@x.nl', duplicate: true },
		{ uid: 'nobody', displayName: 'Nobody', email: 'n@x.nl' },
	],
}

/**
 * Run one import.
 *
 * @param {object} methods The dialog's methods.
 * @param {Array<object>} preview The preview rows.
 * @param {string|null} failAt Where the run fails, or null.
 * @return {Promise<object>} The calls, the state and the events.
 */
async function run(methods, preview, failAt) {
	calls.length = 0
	fail.at = failAt
	const emitted = []
	const vm = {
		bodyId: 'body-1',
		preview: preview.map((row) => ({ ...row })),
		importing: false,
		error: 'old error',
		doneMessage: '',
		t: (_app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text),
		$emit: (name) => emitted.push(name),
	}
	for (const [name, method] of Object.entries(methods)) {
		vm[name] = method.bind(vm)
	}
	const pending = vm.runImport()
	const during = [vm.importing, vm.error]
	await pending
	return { calls: [...calls], during, importing: vm.importing, error: vm.error, doneMessage: vm.doneMessage, emitted, preview: vm.preview }
}

describe('the member-import dialogs import', () => {
	it('writes, says and marks exactly as recorded', async () => {
		const actual = {}
		for (const [file, preview] of Object.entries(PREVIEWS)) {
			const methods = await methodsOf(file)
			for (const failAt of [null, 'person', 'membership']) {
				actual[`${file} ${failAt ?? 'success'}`] = await run(methods, preview, failAt)
			}
		}
		if (process.env.RECORD_MEMBER_IMPORT_GOLDEN === '1') {
			fs.writeFileSync(GOLDEN, JSON.stringify(actual, null, 2) + '\n')
		}
		expect(JSON.parse(JSON.stringify(actual))).toEqual(JSON.parse(fs.readFileSync(GOLDEN, 'utf8')))
	})
})
