/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the evening's page (pla-17): one column per session with
 * room, chair, time, lifecycle, first agenda items and live status; a
 * session's page names its evening and its sibling sessions; Add session
 * presets the evening, its date and its body. This repo's vitest cannot mount
 * a `.vue` file, so the widget's wiring is asserted against the sources.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	newSession,
	sessionColumns,
	siblingsOf,
} from '../../src/utils/meetingSessions.js'

function source(path) {
	return readFileSync(
		fileURLToPath(new URL(`../../${path}`, import.meta.url)),
		'utf8',
	)
}

const evening = {
	id: 'ev',
	title: 'Commissieavond 3 november',
	scheduledDate: '2026-11-03T18:00:00+01:00',
	endDate: '2026-11-03T23:00:00+01:00',
	governanceBody: 'body-raad',
	meetingMode: 'hybrid',
	isPublic: true,
}
const sessions = [
	{
		id: 's1',
		title: 'Commissie Bestuur',
		parentMeeting: 'ev',
		room: 'Commissiekamer 1',
		chair: 'p-jansen',
		lifecycle: 'scheduled',
		scheduledDate: '2026-11-03T19:30:00+01:00',
	},
	{
		id: 's2',
		title: 'Commissie Ruimte',
		parentMeeting: 'ev',
		room: 'Raadzaal',
		lifecycle: 'opened',
		scheduledDate: '2026-11-03T19:30:00+01:00',
	},
]

describe('sessionColumns', () => {
	it('gives each session its room, chair, time, lifecycle, first agenda items and live status', () => {
		const columns = sessionColumns(sessions, {
			agendaItems: {
				s1: [
					{ id: 'a3', title: 'Rondvraag', orderNumber: 9 },
					{
						id: 'a1',
						title: 'Evaluatie burgerparticipatie',
						orderNumber: 1,
					},
					{ id: 'a2', title: 'Verordening rekenkamer', orderNumber: 2 },
					{ id: 'a4', title: 'Sluiting', orderNumber: 10 },
				],
			},
			broadcasts: { s2: { lifecycle: 'live' } },
			chairs: { 'p-jansen': { displayName: 'M. Jansen' } },
			locale: 'en-GB',
			timeZone: 'Europe/Amsterdam',
		})

		expect(columns.map((c) => c.id)).toEqual(['s1', 's2'])
		expect(columns[0]).toMatchObject({
			title: 'Commissie Bestuur',
			room: 'Commissiekamer 1',
			chair: 'M. Jansen',
			time: '19:30',
			lifecycle: 'scheduled',
			live: null,
		})
		expect(columns[0].firstItems.map((i) => i.title)).toEqual([
			'Evaluatie burgerparticipatie',
			'Verordening rekenkamer',
			'Rondvraag',
		])
		expect(columns[1]).toMatchObject({ chair: '', live: 'live', firstItems: [] })
	})
})

describe('siblingsOf', () => {
	it('lists the other sessions of the same evening', () => {
		const third = {
			id: 's3',
			title: 'Commissie Samenleving',
			parentMeeting: { id: 'ev' },
		}
		const other = { id: 'x', title: 'Elders', parentMeeting: 'ev-2' }

		expect(
			siblingsOf(sessions[0], [...sessions, third, other]).map((s) => s.id),
		).toEqual(['s2', 's3'])
		expect(siblingsOf(evening, sessions)).toEqual([])
	})
})

describe('newSession', () => {
	it('presets the evening, its date, body, mode and publicity, at the time entered', () => {
		expect(
			newSession(evening, {
				title: 'Commissie Bestuur',
				room: 'Commissiekamer 1',
				time: '19:30',
			}),
		).toEqual({
			title: 'Commissie Bestuur',
			room: 'Commissiekamer 1',
			parentMeeting: 'ev',
			scheduledDate: '2026-11-03T19:30:00+01:00',
			governanceBody: 'body-raad',
			meetingMode: 'hybrid',
			isPublic: true,
		})
	})

	it("keeps the evening's own notation of the time zone and leaves out what the evening lacks", () => {
		const utc = { id: 'ev', scheduledDate: '2026-11-03T17:00:00Z' }
		expect(newSession(utc, { title: 'X', room: '', time: '18:15' })).toEqual({
			title: 'X',
			parentMeeting: 'ev',
			scheduledDate: '2026-11-03T18:15:00Z',
		})
	})
})

describe('the meeting page', () => {
	it('mounts the sessions widget on the meeting detail page', () => {
		const manifest = JSON.parse(source('src/manifest.json'))
		const detail = manifest.pages.find((p) => p.id === 'MeetingDetail')
		const widget = detail.config.widgets.find((w) => w.id === 'meeting-sessions')

		expect(widget).toMatchObject({
			type: 'custom',
			component: 'MeetingSessionsTab',
		})
		expect(
			detail.config.layout.some((l) => l.widgetId === 'meeting-sessions'),
		).toBe(true)
		expect(source('src/registry.js')).toMatch(
			/MeetingSessionsTab: page\(MeetingSessionsTab\)/,
		)
		expect(JSON.stringify(manifest)).toContain(
			'"widget-meeting-sessions":"MeetingSessionsTab"',
		)
	})

	it('draws a column per session, names the evening on a session, and saves a new session with the presets', () => {
		const tab = source('src/components/tabs/MeetingSessionsTab.vue')

		expect(tab).toContain("from '../../utils/meetingSessions.js'")
		expect(tab).toMatch(/v-for="column in columns"/)
		expect(tab).toContain('meeting-sessions-evening-link')
		expect(tab).toMatch(/v-for="sibling in siblings"/)
		expect(tab).toMatch(/newSession\(/)
		expect(tab).toMatch(/saveObject\(\s*'meeting'/)
	})
})
