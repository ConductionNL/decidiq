// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A digital or hybrid meeting has its video call on the meeting page
// (meeting-video-call, pla-08).
//
// @spec openspec/changes/meeting-video-call/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call
// @e2e tests/e2e/meeting-video-call.spec.ts

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	hasVideoCall,
	joinPath,
	memberUids,
	roomToken,
	talkLinksPath,
	tokenFromInput,
} from '../../src/utils/videoCall.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

describe('which meetings have a video call', () => {
	it('shows for a digital or hybrid meeting and hides for an in-person one', () => {
		expect(hasVideoCall({ meetingMode: 'hybrid' })).toBe(true)
		expect(hasVideoCall({ meetingMode: 'digital' })).toBe(true)
		expect(hasVideoCall({ meetingMode: 'in-person' })).toBe(false)
		expect(hasVideoCall({})).toBe(false)
		expect(hasVideoCall(null)).toBe(false)
	})
})

describe('the linked Talk room', () => {
	it('reads the room linked to the meeting and joins it', () => {
		expect(talkLinksPath('m-1')).toBe(
			'/apps/openregister/api/objects/decidiq/meeting/m-1/talk',
		)
		expect(roomToken([{ roomToken: 'abc123', roomName: 'Commissie' }])).toBe(
			'abc123',
		)
		expect(roomToken([])).toBe('')
		expect(joinPath('abc123')).toBe('/call/abc123')
	})

	it('takes a pasted Talk link or a bare token', () => {
		expect(
			tokenFromInput('https://cloud.example.nl/index.php/call/x7k2p9qa'),
		).toBe('x7k2p9qa')
		expect(tokenFromInput(' x7k2p9qa ')).toBe('x7k2p9qa')
		expect(tokenFromInput('not a link')).toBe('')
	})

	it('invites the current members of the body', () => {
		expect(
			memberUids(
				[
					{ nextcloudUserId: 'pieter', governanceBody: 'b-1' },
					{ nextcloudUserId: 'anna', governanceBody: { id: 'b-1' } },
					{
						nextcloudUserId: 'oud',
						governanceBody: 'b-1',
						leftAt: '2026-01-01',
					},
					{ nextcloudUserId: 'ander', governanceBody: 'b-2' },
					{ governanceBody: 'b-1' },
				],
				'b-1',
			),
		).toEqual(['pieter', 'anna'])
	})
})

describe('the meeting page', () => {
	it('carries the Video call widget', () => {
		const manifest = read('src/manifest.json')
		expect(manifest).toContain(
			'"widget-meeting-video-call": "MeetingVideoCallTab"',
		)
		expect(read('src/registry.js')).toContain(
			'MeetingVideoCallTab: page(MeetingVideoCallTab)',
		)
		const tab = read('src/components/tabs/MeetingVideoCallTab.vue')
		expect(tab).toContain('meeting-video-call-join')
		expect(tab).toContain('meeting-video-call-create')
		expect(tab).toContain('hasVideoCall')
	})
})
