/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden runs of the transcription tab's `useAsMinutes()`: which minutes it
 * looks up, which record it saves, and the notice or error it leaves, over
 * existing draft minutes, minutes past the draft stage, no minutes yet and a
 * failing store. Pinned before the method was split into named steps.
 *
 * @spec exclude golden test of the draft-to-minutes write, no behaviour of its own
 */
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { instanceOf, loadSfcScript } from './helpers/sfcScript.js'

const FILE = path.resolve(
	__dirname,
	'../../src/components/tabs/MeetingTranscriptionTab.vue',
)

/**
 * Run useAsMinutes() against a recording minutes store.
 *
 * @param {object} setup The instance overrides (resolvedObjectId, draft).
 * @param {(call: object) => *} answer The store answers.
 *
 * @return {Promise<object>} The calls and the resulting state.
 */
async function run(setup, answer) {
	const calls = []
	const store = {
		async fetchCollection(schema, params) {
			const call = { fn: 'fetchCollection', schema, params }
			calls.push(call)
			return answer(call)
		},
		async saveObject(schema, object) {
			const call = { fn: 'saveObject', schema, object }
			calls.push(call)
			return answer(call)
		},
	}
	const component = loadSfcScript(FILE, {
		'@conduction/nextcloud-vue': { CnNoteCard: {}, CnStatusBadge: {} },
		'@nextcloud/router': { generateUrl: (u) => u },
		'@nextcloud/vue': { NcButton: {}, NcSelect: {}, NcTextArea: {} },
		'../../modals/TranscriptionConsentModal.vue': { default: {} },
		'../../utils/minutesDraft.js': {
			keptSections: () => [],
			minutesFromAiDraft: (minutes, draft) => ({ ...minutes, draft }),
			newMinutesFor: (meeting, title) => ({ fresh: true, meeting, title }),
		},
		'../../utils/recordingJump.js': {
			isVideoRecording: () => false,
			itemStartTimes: () => [],
		},
		'./useRelationStore.js': {
			ensureRelationType: (type) => {
				calls.push({ fn: 'ensureRelationType', type })
				return store
			},
		},
	})
	const vm = instanceOf(component, { objectId: '' })
	Object.assign(vm, setup)
	await vm.useAsMinutes()
	return {
		calls,
		state: {
			working: vm.working,
			error: vm.error,
			usedNotice: vm.usedNotice,
		},
	}
}

const DRAFT = { sections: ['s1'] }

describe('MeetingTranscriptionTab useAsMinutes()', () => {
	it('does nothing without a meeting or a draft', async () => {
		const fail = () => {
			throw new Error('no call expected')
		}
		for (const setup of [
			{ resolvedObjectId: '', draft: DRAFT },
			{ resolvedObjectId: 'm1', draft: null },
		]) {
			const result = await run(setup, fail)
			expect(result.calls).toEqual([])
			expect(result.state).toEqual({
				working: false,
				error: '',
				usedNotice: '',
			})
		}
	})

	it('writes into the draft minutes of this meeting, matching object and uuid refs', async () => {
		for (const meeting of [{ id: 'm1' }, { uuid: 'm1' }, 'm1']) {
			const result = await run(
				{ resolvedObjectId: 'm1', draft: DRAFT },
				(call) =>
					call.fn === 'fetchCollection'
						? [
								{ id: 'x', meeting: 'other' },
								{ id: 'mins', meeting, lifecycle: 'draft' },
							]
						: {},
			)
			expect(result).toMatchSnapshot()
		}
	})

	it('treats minutes without a lifecycle as a draft', async () => {
		const result = await run({ resolvedObjectId: 'm1', draft: DRAFT }, (call) =>
			call.fn === 'fetchCollection' ? [{ id: 'mins', meeting: 'm1' }] : {},
		)
		expect(result.calls.map((c) => c.fn)).toEqual([
			'ensureRelationType',
			'fetchCollection',
			'saveObject',
		])
		expect(result.calls[2].object.id).toBe('mins')
	})

	it('refuses minutes past the draft stage', async () => {
		const result = await run({ resolvedObjectId: 'm1', draft: DRAFT }, () => [
			{ id: 'mins', meeting: 'm1', lifecycle: 'approved' },
		])
		expect(result).toMatchSnapshot()
	})

	it('creates new minutes when the meeting has none, also when the store answers null', async () => {
		for (const found of [[], null, [{ id: 'y', meeting: null }]]) {
			const result = await run(
				{ resolvedObjectId: 'm1', draft: DRAFT },
				(call) => (call.fn === 'fetchCollection' ? found : {}),
			)
			expect(result).toMatchSnapshot()
		}
	})

	it('reports a failing store, falling back to the default message', async () => {
		const loud = await run({ resolvedObjectId: 'm1', draft: DRAFT }, () => {
			throw new Error('boom')
		})
		expect(loud.state).toEqual({ working: false, error: 'boom', usedNotice: '' })
		const silent = await run(
			{ resolvedObjectId: 'm1', draft: DRAFT },
			(call) => {
				if (call.fn === 'saveObject') throw new Error('')
				return []
			},
		)
		expect(silent.state).toEqual({
			working: false,
			error: 'The draft could not be written into the minutes.',
			usedNotice: '',
		})
	})
})
