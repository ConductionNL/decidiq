// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * planning-activity-calendar-by-audience (matrix row pla-16): the meeting
 * calendar asks the server for the visible month, narrowed by body and by the
 * meeting types that carry the chosen audience.
 *
 * @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-002-the-calendar-filters-by-audience-and-by-body
 * @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-003-the-calendar-asks-the-server-for-the-visible-month
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	AUDIENCES,
	calendarParams,
	gridRange,
	NO_AUDIENCE,
	withoutAudience,
} from '../../src/utils/activityCalendar.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

const types = [
	{ id: 'raad', name: 'Raadsvergadering', audiences: ['council', 'residents'] },
	{ id: 'college', name: 'Collegevergadering', audiences: ['executive'] },
	{ id: 'info', name: 'Informatieavond', audiences: ['residents', 'council'] },
	{ id: 'bare', name: 'Overleg' },
]

describe('the calendar asks for the visible month (REQ-ACAL-003)', () => {
	it('covers the six-week grid around April 2026 and nothing else', () => {
		const { from, to } = gridRange(2026, 3)
		// April 2026 starts on a Wednesday, so the grid starts Monday 30 March.
		expect(new Date(from).getDate()).toBe(30)
		expect(new Date(from).getMonth()).toBe(2)
		expect(new Date(to).getTime() - new Date(from).getTime()).toBe(42 * 24 * 60 * 60 * 1000 - 1)
	})

	it('sends a scheduledDate range and no cap of 500 over all time', () => {
		const params = calendarParams({ year: 2026, month: 3, types })
		expect(params['scheduledDate[gte]']).toBe(gridRange(2026, 3).from)
		expect(params['scheduledDate[lte]']).toBe(gridRange(2026, 3).to)
		expect(params._limit).not.toBe(500)
	})

	it('no longer loads 500 meetings of all time', () => {
		const view = read('src/views/meetings/MeetingCalendarView.vue')
		expect(view).not.toContain('_limit: 500')
		expect(view).toContain('calendarParams')
	})

	it('asks again when the month changes, also through Today', () => {
		const view = read('src/views/meetings/MeetingCalendarView.vue')
		expect(view).toMatch(/goToday\(\) \{[^}]*this\.load\(\)/)
		expect(view).toMatch(/step\(delta\) \{[^}]*this\.load\(\)/)
	})
})

describe('the calendar filters by audience and by body (REQ-ACAL-002)', () => {
	it('asks for the meeting types that carry the chosen audience', () => {
		expect(calendarParams({ year: 2026, month: 3, audience: 'executive', types }).type).toEqual(['college'])
		expect(calendarParams({ year: 2026, month: 3, audience: 'residents', types }).type).toEqual(['raad', 'info'])
	})

	it('passes the body', () => {
		expect(calendarParams({ year: 2026, month: 3, body: 'body-1', types }).governanceBody).toBe('body-1')
	})

	it('asks nothing when no type carries the audience', () => {
		expect(calendarParams({ year: 2026, month: 3, audience: 'staff', types })).toBeNull()
	})

	it('keeps a meeting without a type findable under No audience set', () => {
		const meetings = [{ id: 'm1', type: 'raad' }, { id: 'm2' }, { id: 'm3', type: 'bare' }]
		expect(withoutAudience(meetings, NO_AUDIENCE, types).map((m) => m.id)).toEqual(['m2', 'm3'])
		expect(withoutAudience(meetings, '', types)).toHaveLength(3)
	})

	it('knows the five audiences', () => {
		expect(AUDIENCES).toEqual(['council', 'executive', 'joint-arrangement', 'residents', 'staff'])
	})
})

describe('staff publish a public meeting to the residents calendar (REQ-ACAL-004)', () => {
	it('offers the calendar entry next to agenda publication on the meeting page', () => {
		const tab = read('src/components/tabs/AgendaPublicationTab.vue')
		expect(tab).toContain('sourceType="activity"')
		expect(tab).toContain("'Publish to the public calendar'")
	})

	it('offers it only for a public meeting, which needs no convocation', () => {
		const actions = read('src/components/tabs/PublicationActionsTab.vue')
		expect(actions).toMatch(/sourceType === 'activity'\)[\s\S]{0,160}isPublic === true/)
	})
})
