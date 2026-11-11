import { generateUrl } from '@nextcloud/router'
/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden runs of the meeting agenda tab's loaders and server calls: which
 * store reads and requests each method makes, in which order and with which
 * arguments, and the state it leaves, over successful answers, refusals with
 * and without a message, unreadable bodies and thrown failures. Pinned
 * before those methods were split into named steps.
 *
 * @spec exclude golden test of the meeting agenda tab, no behaviour of its own
 */
import * as path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import * as agendaRules from '../../src/services/agendaRules.js'
import * as agendaCopy from '../../src/utils/agendaCopy.js'
import * as agendaItemTypeFields from '../../src/utils/agendaItemTypeFields.js'
import * as agendaPublication from '../../src/utils/agendaPublication.js'
import * as formalities from '../../src/utils/formalities.js'
import { instanceOf, loadSfcScript } from './helpers/sfcScript.js'

const FILE = path.resolve(
	__dirname,
	'../../src/components/tabs/MeetingAgendaTab.vue',
)

const ICONS = [
	'ArrowDown',
	'ArrowUp',
	'ContentCopy',
	'DragVertical',
	'EyeOutline',
	'Gavel',
	'Pencil',
	'Plus',
	'Presentation',
	'TrashCanOutline',
]

const STATE_KEYS = [
	'loading',
	'error',
	'rawRows',
	'agendaSchema',
	'itemTypeNames',
	'itemTypes',
	'packageResult',
	'packageError',
	'assembling',
	'reorderError',
	'publishing',
	'publishError',
	'copyOpen',
	'copyError',
	'copyNotice',
	'agendaTemplates',
	'defaultTemplateId',
	'earlierMeetings',
	'copySourceId',
	'copySourceItems',
]

/**
 * Build the tab over a recording store and a recording fetch.
 *
 * @param {object} answers The store's and the server's answers.
 * @return {{vm: object, log: Array<object>}} The instance and its log.
 */
function tab(answers) {
	const log = []
	const store = (type) => ({
		async fetchSchema(schema) {
			log.push(['fetchSchema', type, schema])
			return { slug: schema }
		},
		async fetchCollection(schema, params) {
			log.push(['fetchCollection', type, schema, params])
			return answers.collection(schema, params)
		},
		async fetchObject(schema, id) {
			log.push(['fetchObject', type, schema, id])
			return answers.object(schema, id)
		},
	})
	globalThis.OC = { requestToken: 'tok' }
	globalThis.fetch = vi.fn(async (url, options) => {
		log.push(['fetch', url, options])
		return answers.response(url)
	})
	const modules = {
		'@conduction/nextcloud-vue': {
			CnDataTable: {},
			CnDeleteDialog: {},
			CnFormDialog: {},
			CnNoteCard: {},
			CnRowActions: {},
		},
		'@nextcloud/router': { generateUrl },
		'@nextcloud/vue': { NcButton: {} },
		'../../dialogs/AgendaCopyDialog.vue': { default: {} },
		'../AgendaItemTypeFields.vue': { default: {} },
		'../../services/agendaRules.js': agendaRules,
		'../../utils/agendaCopy.js': agendaCopy,
		'../../utils/agendaItemTypeFields.js': agendaItemTypeFields,
		'../../utils/agendaPublication.js': agendaPublication,
		'../../utils/formalities.js': formalities,
		'./useRelationStore.js': { ensureRelationType: store },
	}
	for (const icon of ICONS) {
		modules[`vue-material-design-icons/${icon}.vue`] = { default: {} }
	}
	const vm = instanceOf(loadSfcScript(FILE, modules), {
		objectId: answers.objectId ?? 'mt-1',
	})
	vm.loadMeeting = async () => log.push(['loadMeeting'])
	return { vm, log }
}

/**
 * The state keys of an instance, as plain data.
 *
 * @param {object} vm The instance.
 * @return {object} The state.
 */
function state(vm) {
	return Object.fromEntries(STATE_KEYS.map((key) => [key, vm[key]]))
}

/**
 * A server answer.
 *
 * @param {boolean} ok Whether it succeeded.
 * @param {*} body The JSON body, or an Error to throw when it is read.
 * @return {object} The response.
 */
function response(ok, body) {
	return {
		ok,
		status: ok ? 200 : 403,
		json: async () => {
			if (body instanceof Error) throw body
			return body
		},
	}
}

const ITEMS = [
	{ id: 'i2', title: 'Sub', parentItem: 'i1', orderNumber: 2, meeting: 'mt-1' },
	{ id: 'i1', title: 'Opening', orderNumber: 1, meeting: 'mt-1' },
	{ id: 'i3', title: 'Other', orderNumber: 3, meeting: { id: 'mt-9' } },
]

const TYPES = [
	{ id: 't1', name: 'Question', '@self': { slug: 'question' } },
	{ name: 'Seeded', '@self': { slug: 'seeded' } },
	{ id: 't3', name: '' },
	null,
	{ id: 't4', name: 'Plain' },
]

const MEETINGS = [
	{ id: 'mt-1', governanceBody: 'gb-1' },
	{ id: 'mt-2', governanceBody: 'gb-1' },
	{ id: 'mt-3', governanceBody: { id: 'gb-2' } },
	{ uuid: 'mt-4', governanceBody: { id: 'gb-1' } },
]

/**
 * Answers that read everything and accept every request.
 *
 * @param {object} overrides Answers to replace.
 * @return {object} The answers.
 */
function answers(overrides = {}) {
	return {
		collection: (schema) =>
			({
				'agenda-item': ITEMS,
				'agenda-item-type': TYPES,
				'agenda-template': [{ id: 'tpl-1' }],
				meeting: MEETINGS,
			})[schema],
		object: () => ({ defaultAgendaTemplate: { id: 'tpl-1' } }),
		response: () => response(true, { success: true, folder: 'f' }),
		...overrides,
	}
}

function failing (message) {
  return () => {
	throw new Error(message)
}
}

afterEach(() => {
	delete globalThis.fetch
	delete globalThis.OC
})

describe('MeetingAgendaTab golden runs', () => {
	it('refresh', async () => {
		const out = []
		for (const [label, a, objectId] of [
			['loads', answers(), undefined],
			['no meeting', answers(), ''],
			['null items', answers({ collection: () => null }), undefined],
			['fails', answers({ collection: failing('store down') }), undefined],
			['fails silently', answers({ collection: failing('') }), undefined],
		]) {
			const { vm, log } = tab({ ...a, objectId })
			vm.loadItemTypes = () => log.push(['loadItemTypes'])
			await vm.refresh()
			out.push({ label, log, state: state(vm) })
		}
		expect(out).toMatchSnapshot()
	})

	it('loadItemTypes', async () => {
		const out = []
		for (const [label, a] of [
			['indexes', answers()],
			['none', answers({ collection: () => undefined })],
			['fails', answers({ collection: failing('x') })],
		]) {
			const { vm, log } = tab(a)
			vm.itemTypeNames = { stale: 'x' }
			await vm.loadItemTypes()
			out.push({ label, log, state: state(vm) })
		}
		expect(out).toMatchSnapshot()
	})

	it('openCopy', async () => {
		const out = []
		for (const [label, a, meeting] of [
			['same body with type', answers(), { governanceBody: 'gb-1', type: 'mty-1' }],
			['no body, no type', answers(), null],
			['type without default', answers({ object: () => null }), { type: { id: 'mty-2' } }],
			['nothing listed', answers({ collection: () => null }), { governanceBody: 'gb-1' }],
			['fails', answers({ collection: failing('down') }), null],
			['fails silently', answers({ object: failing('') }), { type: 'mty-1' }],
		]) {
			const { vm, log } = tab(a)
			vm.meeting = meeting
			vm.copyError = 'stale'
			vm.copyNotice = 'stale'
			vm.copySourceId = 'stale'
			vm.copySourceItems = ['stale']
			await vm.openCopy()
			out.push({ label, log, state: state(vm) })
		}
		expect(out).toMatchSnapshot()
	})

	it('loadCopySource', async () => {
		const out = []
		for (const [label, a, id] of [
			['loads', answers(), 'mt-1'],
			['none picked', answers(), ''],
			['null items', answers({ collection: () => null }), 'mt-1'],
			['fails', answers({ collection: failing('gone') }), 'mt-1'],
			['fails silently', answers({ collection: failing('') }), 'mt-1'],
		]) {
			const { vm, log } = tab(a)
			vm.copySourceItems = ['stale']
			await vm.loadCopySource(id)
			out.push({ label, log, state: state(vm) })
		}
		expect(out).toMatchSnapshot()
	})

	const REQUESTS = [
		['accepted', () => response(true, { success: true, id: 'p' })],
		['refused with message', () => response(false, { message: 'Not yours' })],
		['refused bare', () => response(false, {})],
		['refused unreadable', () => response(false, new Error('bad json'))],
		['accepted unreadable', () => response(true, new Error('bad json'))],
		['ok but unsuccessful', () => response(true, { success: false, message: 'Empty' })],
		['ok but unsuccessful bare', () => response(true, { success: false })],
		['throws', failing('offline')],
		['throws silently', failing('')],
	]

	for (const [method, call] of [
		['assemblePackage', (vm) => vm.assemblePackage()],
		['publishAgenda', (vm) => vm.publishAgenda()],
		['setFormality', (vm) => vm.setFormality({ id: 'i1' }, true)],
		['persistOrder', (vm) => vm.persistOrder(['i2', 'i1'])],
	]) {
		it(method, async () => {
			const out = []
			for (const [label, answer] of REQUESTS) {
				const { vm, log } = tab(answers({ response: answer }))
				vm.refresh = async () => log.push(['refresh'])
				vm.packageError = 'stale'
				vm.publishError = 'stale'
				vm.reorderError = 'stale'
				vm.packageResult = 'stale'
				await call(vm)
				out.push({ label, log, state: state(vm) })
			}
			expect(out).toMatchSnapshot()
		})
	}
})
