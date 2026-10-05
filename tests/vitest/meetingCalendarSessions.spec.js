/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for how the meetings calendar groups an evening's parallel
 * sessions (pla-17): the evening is one event holding its sessions, and a
 * session whose evening is not loaded stands on its own. This repo's vitest
 * cannot mount a `.vue` file, so the calendar's wiring is asserted against
 * the sources.
 *
 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-003-the-calendar-and-the-meetings-list-group-sessions-under-their-evening
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	groupSessions,
	parentOf,
	sessionLabel,
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
}
const sessions = [
	{
		id: 's3',
		title: 'Commissie Samenleving',
		parentMeeting: 'ev',
		room: 'Commissiekamer 2',
		scheduledDate: '2026-11-03T19:30:00+01:00',
	},
	{
		id: 's1',
		title: 'Commissie Bestuur',
		parentMeeting: { id: 'ev' },
		room: 'Commissiekamer 1',
		scheduledDate: '2026-11-03T19:30:00+01:00',
	},
	{
		id: 's2',
		title: 'Commissie Ruimte',
		parentMeeting: 'ev',
		room: 'Raadzaal',
		scheduledDate: '2026-11-03T19:00:00+01:00',
	},
]
const other = {
	id: 'raad',
	title: 'Raadsvergadering',
	scheduledDate: '2026-11-05T19:30:00+01:00',
}

describe('groupSessions', () => {
	it('shows the evening once, holding its three sessions in time order', () => {
		const grouped = groupSessions([...sessions, evening, other])

		expect(grouped.map((m) => m.id)).toEqual(['ev', 'raad'])
		expect(grouped[0].sessions.map((s) => s.id)).toEqual(['s2', 's1', 's3'])
		expect(grouped[1].sessions).toEqual([])
	})

	it('lets a session whose evening is not loaded stand on its own', () => {
		const grouped = groupSessions([sessions[0], other])

		expect(grouped.map((m) => m.id)).toEqual(['s3', 'raad'])
		expect(grouped[0].sessions).toEqual([])
	})

	it('reads the evening from a uuid or an expanded reference, and never changes the input', () => {
		expect(parentOf(sessions[0])).toBe('ev')
		expect(parentOf(sessions[1])).toBe('ev')
		expect(parentOf(other)).toBeNull()

		const input = [evening, ...sessions]
		groupSessions(input)
		expect(input[0].sessions).toBeUndefined()
	})
})

describe('sessionLabel', () => {
	it('names the time, the session and its room', () => {
		expect(sessionLabel(sessions[1], 'en-GB', 'Europe/Amsterdam')).toBe(
			'19:30 Commissie Bestuur (Commissiekamer 1)',
		)
		expect(
			sessionLabel({ title: 'Commissie X' }, 'en-GB', 'Europe/Amsterdam'),
		).toBe('Commissie X')
	})
})

describe('the calendar and the meetings list', () => {
	it('places grouped evenings on the grid and lists their sessions inside the event', () => {
		const view = source('src/views/meetings/MeetingCalendarView.vue')

		expect(view).toContain("from '../../utils/meetingSessions.js'")
		expect(view).toMatch(/groupSessions\(this\.meetings\)/)
		expect(view).toContain('meeting-calendar-sessions-')
		expect(view).toMatch(/v-for="session in meeting\.sessions"/)
		expect(view).toMatch(/@click="open\(session\)"/)
	})

	it('lets the meetings list filter on the evening', () => {
		// The index sidebar builds its facets from the schema's facetable
		// properties unless the page declares its own filter set, so a
		// facetable parentMeeting and an enabled sidebar are the facet.
		const manifest = JSON.parse(source('src/manifest.json'))
		const meetings = manifest.pages.find((p) => p.id === 'Meetings')
		expect(meetings.config.sidebar.enabled).toBe(true)
		expect(meetings.config.sidebar.filterFields).toBeUndefined()

		const fragment = JSON.parse(
			source('lib/Settings/register.d/121-parallel-sessions.json'),
		)
		expect(
			fragment.components.schemas.Meeting.properties.parentMeeting.facetable,
		).toBe(true)
	})
})
