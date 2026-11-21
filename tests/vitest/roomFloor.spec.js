/**
 * The floor request the speaker queue sends when the chair gives a member the floor
 * (platform-baseline-on-nextcloud, REQ-PLB-006).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/platform-baseline-on-nextcloud/specs/platform-baseline/spec.md#requirement-req-plb-006-the-rooms-cameras-and-microphones-follow-the-speaker
 */

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { floorRequest } from '../../src/utils/roomFloor.js'
import { PUBLIC_SPEAKER_PREFIX } from '../../src/utils/speakingRequests.js'

describe('floorRequest', () => {
	it('names the meeting in the path and the member in the body', () => {
		expect(floorRequest('meeting-raad-14-okt', 'participant-sem')).toEqual({
			path: '/apps/decidiq/api/meetings/meeting-raad-14-okt/room-system/floor',
			body: { participant: 'participant-sem' },
		})
	})

	it('encodes the meeting id', () => {
		expect(floorRequest('a/b', 'p').path).toBe('/apps/decidiq/api/meetings/a%2Fb/room-system/floor')
	})

	it('sends nothing for a public speaker, who has no seat', () => {
		expect(floorRequest('meeting-raad-14-okt', PUBLIC_SPEAKER_PREFIX + 'request-1')).toBeNull()
	})

	it('sends nothing without a meeting or a participant', () => {
		expect(floorRequest('', 'participant-sem')).toBeNull()
		expect(floorRequest('meeting-raad-14-okt', '')).toBeNull()
		expect(floorRequest('meeting-raad-14-okt', null)).toBeNull()
	})
})

describe('the speaker queue tells the room system when the chair gives the floor', () => {
	const source = readFileSync(
		resolve(dirname(fileURLToPath(import.meta.url)), '../../src/components/liveMeeting/SpeakerQueuePanel.vue'),
		'utf8',
	)

	it('announces the floor right after starting the speaker', () => {
		const giveFloor = source.slice(source.indexOf('giveFloor(participantId) {'), source.indexOf('async announceFloor('))
		expect(giveFloor).toContain('startSpeaker(')
		expect(giveFloor).toContain('this.announceFloor(participantId)')
	})

	it('posts the floor request and never lets a failure stop the queue', () => {
		const announce = source.slice(source.indexOf('async announceFloor('), source.indexOf('stop() {'))
		expect(announce).toContain('floorRequest(this.meetingId, participantId)')
		expect(announce).toContain("method: 'POST'")
		expect(announce).toContain('catch (e)')
	})
})
