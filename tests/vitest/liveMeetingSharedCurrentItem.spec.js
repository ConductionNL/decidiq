// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The shared live meeting (live-meeting-shared-current-item, liv-01 liv-04
// liv-05 liv-11): the chair's current item is saved on the meeting and every
// live screen and the room screen follow it; a decision is recorded on the
// current item; speeches and questions carry the item.
//
// @spec openspec/changes/live-meeting-shared-current-item/specs/agenda-live-management/spec.md
// @e2e tests/e2e/live-meeting-shared-current-item.spec.ts

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	FOLLOW_INTERVAL_MS,
	engagementBody,
	isOpenRound,
	liveDecisionBody,
	openRoundFor,
	runsTheMeeting,
	sharedCurrentItemId,
} from '../../src/utils/liveMeeting.js'
import { validatorFor } from './helpers/registerSchema.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

const MEETING = '0f5b8a8e-6a3c-4c1b-9d3e-1a2b3c4d5e61'
const ITEM5 = '1a2b3c4d-5e6f-4a1b-8c2d-3e4f5a6b7c85'
const MOTION = '2b3c4d5e-6f7a-4b2c-9d3e-4f5a6b7c8d12'

const meeting = {
	id: MEETING,
	title: 'Raad 14 oktober',
	meetingType: 'regular',
	scheduledDate: '2026-10-14T19:30:00+02:00',
	meetingMode: 'in-person',
	lifecycle: 'opened',
	'@self': { id: MEETING, relations: {} },
}

describe('everyone follows the current item (REQ-LSC-001)', () => {
	it('the current item the server saves passes the meeting schema', () => {
		const { id, '@self': self, ...properties } = meeting
		const valid = validatorFor('meeting')
		const saved = { ...properties, currentAgendaItem: ITEM5 }
		expect(valid(saved), JSON.stringify(valid.errors)).toBe(true)
		expect(id).toBe(self.id)
	})

	it('reads the shared current item, or none', () => {
		expect(sharedCurrentItemId({ currentAgendaItem: ITEM5 })).toBe(ITEM5)
		expect(sharedCurrentItemId({ currentAgendaItem: '' })).toBeNull()
		expect(sharedCurrentItemId(null)).toBeNull()
	})

	it('follows within seconds', () => {
		expect(FOLLOW_INTERVAL_MS).toBeLessThanOrEqual(5000)
	})

	it('the live screen saves the chosen item, follows the meeting and asks the server who runs it', () => {
		const source = read('src/views/LiveMeeting.vue')
		expect(source).toMatch(/\/current-item/)
		expect(source).toMatch(/method: 'PUT'/)
		expect(source).not.toMatch(/saveObject\('meeting'/)
		expect(source).toMatch(/sharedCurrentItemId\(/)
		expect(source).toMatch(/FOLLOW_INTERVAL_MS/)
		expect(source).toMatch(/\/my-roles/)
		expect(source).toMatch(/runsTheMeeting\(/)
	})

	it('only the chair, secretary or an admin runs the meeting', () => {
		expect(runsTheMeeting({ chair: true, secretary: false, admin: false })).toBe(true)
		expect(runsTheMeeting({ chair: false, secretary: true, admin: false })).toBe(true)
		expect(runsTheMeeting({ chair: false, secretary: false, admin: false })).toBe(false)
		expect(runsTheMeeting(null)).toBe(false)
	})
})

describe('a room screen shows the current item and the vote (REQ-LSC-002)', () => {
	const rounds = [
		{ id: 'r-closed', openedAt: '2026-10-14T19:40:00Z', closedAt: '2026-10-14T19:45:00Z', '@self': { relations: { motion: MOTION } } },
		{ id: 'r-open', openedAt: '2026-10-14T20:00:00Z', '@self': { relations: { motion: MOTION } } },
	]
	const motions = [{ id: MOTION, title: 'M-12', '@self': { relations: { agendaItem: ITEM5 } } }]

	it('a round is open between opening and closing', () => {
		expect(isOpenRound(rounds[0])).toBe(false)
		expect(isOpenRound(rounds[1])).toBe(true)
		expect(isOpenRound({})).toBe(false)
	})

	it('finds the open round on the motion of the current item', () => {
		expect(openRoundFor(rounds, { id: ITEM5 }, motions)?.id).toBe('r-open')
		expect(openRoundFor(rounds, { id: 'other-item' }, motions)).toBeNull()
		expect(openRoundFor(rounds, null, motions)).toBeNull()
	})

	it('the screen page is registered and linked from the live screen', () => {
		const manifest = JSON.parse(read('src/manifest.json'))
		const page = manifest.pages.find((p) => p.id === 'MeetingScreen')
		expect(page?.route).toBe('/meetings/:id/screen')
		expect(page?.component).toBe('MeetingScreenView')
		expect(read('src/registry.js')).toMatch(/MeetingScreenView/)
		expect(read('src/views/LiveMeeting.vue')).toMatch(/MeetingScreen/)
		expect(read('src/views/MeetingScreen.vue')).toMatch(/openRoundFor\(/)
	})
})

describe('a decision is recorded when it is taken (REQ-LSC-003)', () => {
	it('sends the decision for the current item with its type', () => {
		expect(
			liveDecisionBody(
				{ title: ' Woningbouwplan ', text: 'De raad stemt in.', outcome: 'adopted', decisionType: 'resolution' },
				ITEM5,
			),
		).toEqual({
			title: 'Woningbouwplan',
			text: 'De raad stemt in.',
			outcome: 'adopted',
			decisionType: 'resolution',
			agendaItem: ITEM5,
		})
	})

	it('the live screen opens the decision dialog for the current item', () => {
		expect(read('src/views/LiveMeeting.vue')).toMatch(/LiveDecisionDialog/)
		expect(read('src/dialogs/LiveDecisionDialog.vue')).toMatch(/live-decisions/)
		expect(read('src/dialogs/LiveDecisionDialog.vue')).toMatch(/liveDecisionBody\(/)
	})
})

describe('speeches and questions are logged per item (REQ-LSC-004)', () => {
	it('a speech and a question carry the current item', () => {
		expect(engagementBody(MEETING, 'P-anna', 'speech', ITEM5, { duration: 90 })).toEqual({
			meeting: MEETING,
			participant: 'P-anna',
			eventType: 'speech',
			eventData: { duration: 90, agendaItem: ITEM5 },
		})
		expect(engagementBody(MEETING, 'P-pieter', 'question', ITEM5).eventData).toEqual({ agendaItem: ITEM5 })
	})

	it('the speaker queue logs with the current item and offers Question raised', () => {
		const source = read('src/components/liveMeeting/SpeakerQueuePanel.vue')
		expect(source).toMatch(/engagementBody\(/)
		expect(source).toMatch(/currentItemId/)
		expect(source).toMatch(/'question'/)
		expect(read('src/views/LiveMeeting.vue')).toMatch(/:currentItemId=/)
	})
})
