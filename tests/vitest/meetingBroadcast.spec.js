// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * live-public-livestream (matrix rows liv-06 liv-10 liv-19 liv-22): the
 * Broadcast widget offers only the moves the broadcast's declared lifecycle
 * allows, shows no buttons without a connected streaming service, and reads
 * back the test result and the live captions answer.
 *
 * @spec openspec/changes/live-public-livestream/specs/meeting-broadcast/spec.md#requirement-req-lstr-002-the-clerk-runs-a-test-broadcast-that-only-staff-can-see
 */
import { readFileSync } from 'node:fs'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

const { actionUrl, actionsFor, captionsNotice, statusUrl, testResultOf, testUrl } =
	await import('../../src/utils/meetingBroadcast.js')

const fragment = JSON.parse(
	readFileSync(
		new URL(
			'../../lib/Settings/register.d/120-meeting-broadcast.json',
			import.meta.url,
		),
	),
)
const schema = Object.values(fragment.components.schemas)[0]
const lifecycle = schema['x-openregister-lifecycle']

/** The service method each widget action calls, and the state it ends in. */
const TARGET = {
	test: 'testing',
	start: 'live',
	pause: 'paused',
	resume: 'live',
	stop: 'ended',
	testResult: 'planned',
}

describe('Broadcast widget actions', () => {
	it('offers no buttons without a connected streaming service', () => {
		expect(actionsFor(null, false)).toEqual([])
		expect(actionsFor({ lifecycle: 'planned' }, false)).toEqual([])
	})

	it('offers a test and going live before the meeting', () => {
		expect(actionsFor(null, true)).toEqual(['test', 'start'])
		expect(actionsFor({ lifecycle: 'planned' }, true)).toEqual(['test', 'start'])
		expect(actionsFor({ lifecycle: 'testing' }, true)).toEqual([
			'testResult',
			'start',
		])
	})

	it('offers pause, resume and stop while live, and nothing once ended', () => {
		expect(actionsFor({ lifecycle: 'live' }, true)).toEqual(['pause', 'stop'])
		expect(actionsFor({ lifecycle: 'paused' }, true)).toEqual(['resume', 'stop'])
		expect(actionsFor({ lifecycle: 'ended' }, true)).toEqual([])
	})

	it('offers only moves the schema lifecycle declares', () => {
		const declared = new Set(
			lifecycle.transitions.map((move) => `${move.from}>${move.to}`),
		)
		for (const state of lifecycle.states) {
			for (const action of actionsFor({ lifecycle: state }, true)) {
				expect(
					declared.has(`${state}>${TARGET[action]}`),
					`${state} ${action}`,
				).toBe(true)
			}
		}
	})
})

describe('Broadcast widget reads', () => {
	it('names the routes BroadcastController serves', () => {
		const routes = readFileSync(
			new URL('../../appinfo/routes.php', import.meta.url),
			'utf8',
		)
		expect(statusUrl('m1')).toBe(
			'/index.php/apps/decidiq/api/meetings/m1/broadcast',
		)
		expect(testUrl('m1')).toBe(
			'/index.php/apps/decidiq/api/meetings/m1/broadcast/test',
		)
		expect(actionUrl('b1', 'testResult')).toBe(
			'/index.php/apps/decidiq/api/meeting-broadcasts/b1/test-result',
		)
		for (const action of ['start', 'pause', 'resume', 'stop']) {
			expect(actionUrl('b1', action)).toBe(
				`/index.php/apps/decidiq/api/meeting-broadcasts/b1/${action}`,
			)
			expect(routes).toContain(`'/api/meeting-broadcasts/{id}/${action}'`)
		}
		expect(routes).toContain("'/api/meeting-broadcasts/{id}/test-result'")
	})

	it('reads back the test result with its note, author and time', () => {
		expect(testResultOf({ lifecycle: 'planned' })).toBeNull()
		expect(
			testResultOf({
				testResult: 'problems',
				testNote: 'Microphone 4 had no sound, replaced',
				testedBy: 'Griffier',
				testedAt: '2026-03-19T18:45:00+01:00',
			}),
		).toEqual({
			ok: false,
			note: 'Microphone 4 had no sound, replaced',
			by: 'Griffier',
			at: '2026-03-19T18:45:00+01:00',
		})
		expect(
			testResultOf({ testResult: 'ok', testedBy: 'Griffier', testedAt: 'x' })
				.ok,
		).toBe(true)
	})

	it('says when the service has no live captions', () => {
		expect(
			captionsNotice({ lifecycle: 'live', liveCaptions: 'unavailable' }),
		).toBe('unavailable')
		expect(
			captionsNotice({ lifecycle: 'live', liveCaptions: 'requested' }),
		).toBe('requested')
		expect(captionsNotice({ lifecycle: 'planned' })).toBe('')
	})
})
