// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * live-recording-jump-to-item (matrix row liv-08): the Transcription widget
 * plays the recording and jumps to the moment each agenda item started.
 *
 * @spec openspec/specs/meeting-transcription/spec.md#requirement-req-lrj-001-jump-to-an-item-in-the-recording
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { isVideoRecording, itemStartTimes } from '../../src/utils/recordingJump.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

const HOUSING = '1a2b3c4d-5e6f-4a1b-8c2d-3e4f5a6b7c85'
const BUDGET = '2b3c4d5e-6f7a-4b2c-9d3e-4f5a6b7c8d12'

describe('a member replays a debate (REQ-LRJ-001)', () => {
	it('an item starts at its earliest aligned segment', () => {
		const segments = [
			{ startTime: 12.5, endTime: 20, agendaItem: BUDGET, text: 'a' },
			{ startTime: 610, endTime: 640, agendaItem: HOUSING, text: 'b' },
			{ startTime: 480.2, endTime: 600, agendaItem: HOUSING, text: 'c' },
			{ startTime: 700, endTime: 710, text: 'unassigned' },
			{ endTime: 5, agendaItem: BUDGET, text: 'no start' },
		]
		expect(itemStartTimes(segments)).toEqual({
			[BUDGET]: 12.5,
			[HOUSING]: 480.2,
		})
		expect(itemStartTimes(null)).toEqual({})
	})

	it('a video recording plays in a video player, audio in an audio player', () => {
		expect(isVideoRecording('Decidiq/Raad/2026-10-14/opname.mp4')).toBe(true)
		expect(isVideoRecording('Talk/Recording/call.WEBM')).toBe(true)
		expect(isVideoRecording('Decidiq/Raad/2026-10-14/opname.mp3')).toBe(false)
		expect(isVideoRecording('')).toBe(false)
	})

	it('the widget plays the recording and offers Play from here per agenda item', () => {
		const tab = read('src/components/tabs/MeetingTranscriptionTab.vue')
		expect(tab).toMatch(/\/recording/)
		expect(tab).toMatch(/itemStartTimes\(/)
		expect(tab).toMatch(/Play from here/)
		expect(tab).toMatch(/currentTime = /)
		expect(tab).toMatch(/data-testid="transcript-player"/)
	})
})
