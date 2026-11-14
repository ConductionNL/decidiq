/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * platform-microsoft-365-calendar-and-teams (matrix rows plt-12 and pla-04):
 * a member subscribes to their meetings from the user settings through
 * OpenRegister's calendar feed, and a digital or hybrid meeting carries a
 * Teams meeting that members join from the meeting page and the live page.
 * This repo's vitest cannot mount a `.vue` file, so the components' wiring
 * is asserted against their sources.
 *
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-003-a-member-subscribes-from-the-personal-settings
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	FEED_LABEL,
	feedsUrl,
	meetingFeeds,
	mintBody,
	registerUrl,
	schemaIdOf,
} from '../../src/utils/calendarFeeds.js'
import {
	canCreateTeams,
	isOnlineMeeting,
	isTeamsJoinUrl,
	teamsJoinUrl,
	teamsMeetingUrl,
} from '../../src/utils/teamsMeeting.js'

const JOIN = 'https://teams.microsoft.com/l/meetup-join/00000000-0000-0000-0000-000000000000'

function source(path) {
	return readFileSync(
		fileURLToPath(new URL(`../../${path}`, import.meta.url)),
		'utf8',
	)
}

describe('calendar feed helpers', () => {
	it('addresses OpenRegister’s feed API and the register with its schemas', () => {
		expect(feedsUrl()).toMatch(/\/apps\/openregister\/api\/calendar-feeds$/)
		expect(feedsUrl(42)).toMatch(/\/apps\/openregister\/api\/calendar-feeds\/42$/)
		expect(registerUrl('decidiq')).toMatch(
			/\/apps\/openregister\/api\/registers\/decidiq\?_extend\[\]=schemas$/,
		)
	})

	it('finds the meeting schema id among the register’s expanded schemas', () => {
		const register = {
			schemas: [
				{ id: 7, slug: 'agenda-item' },
				{ id: 12, slug: 'Meeting' },
			],
		}
		expect(schemaIdOf(register)).toBe('12')
		expect(schemaIdOf({ schemas: [3, 4] })).toBe('')
		expect(schemaIdOf(null)).toBe('')
	})

	it('mints a schema-scoped token labelled as decidiq’s', () => {
		expect(mintBody(12)).toEqual({
			scopeType: 'schema',
			scopeId: '12',
			label: FEED_LABEL,
		})
	})

	it('lists only live meeting subscriptions, newest first', () => {
		const tokens = [
			{ id: 1, scopeType: 'schema', scopeId: '12', live: true, createdAt: '2026-10-01T10:00:00+00:00' },
			{ id: 2, scopeType: 'schema', scopeId: '12', live: true, createdAt: '2026-11-01T10:00:00+00:00' },
			{ id: 3, scopeType: 'schema', scopeId: '99', live: true, createdAt: '2026-11-02T10:00:00+00:00' },
			{ id: 4, scopeType: 'view', scopeId: '12', live: true, createdAt: '2026-11-03T10:00:00+00:00' },
			{ id: 5, scopeType: 'schema', scopeId: '12', live: false, revokedAt: '2026-11-04T10:00:00+00:00', createdAt: '2026-11-04T09:00:00+00:00' },
		]
		expect(meetingFeeds(tokens, '12').map((t) => t.id)).toEqual([2, 1])
		expect(meetingFeeds(tokens, '')).toEqual([])
		expect(meetingFeeds(null, '12')).toEqual([])
	})
})

describe('teams meeting helpers', () => {
	it('accepts only Teams join addresses', () => {
		expect(isTeamsJoinUrl(JOIN)).toBe(true)
		expect(isTeamsJoinUrl('https://example.org/meet/123')).toBe(false)
		expect(isTeamsJoinUrl('http://teams.microsoft.com/l/x')).toBe(false)
		expect(isTeamsJoinUrl(undefined)).toBe(false)
	})

	it('reads the join address off the meeting, ignoring a stored non-Teams one', () => {
		expect(teamsJoinUrl({ teamsMeeting: { joinUrl: JOIN } })).toBe(JOIN)
		expect(teamsJoinUrl({ teamsMeeting: { joinUrl: 'https://example.org/x' } })).toBe('')
		expect(teamsJoinUrl({})).toBe('')
		expect(teamsJoinUrl(null)).toBe('')
	})

	it('treats digital and hybrid meetings as online', () => {
		expect(isOnlineMeeting({ meetingMode: 'digital' })).toBe(true)
		expect(isOnlineMeeting({ meetingMode: 'hybrid' })).toBe(true)
		expect(isOnlineMeeting({ meetingMode: 'in-person' })).toBe(false)
	})

	it('offers Create only for an online meeting without one, once Microsoft 365 is connected', () => {
		const digital = { meetingMode: 'digital' }
		expect(canCreateTeams(digital, { connected: true })).toBe(true)
		expect(canCreateTeams(digital, { connected: false })).toBe(false)
		expect(canCreateTeams(digital, null)).toBe(false)
		expect(canCreateTeams({ meetingMode: 'in-person' }, { connected: true })).toBe(false)
		expect(canCreateTeams({ ...digital, teamsMeeting: { joinUrl: JOIN } }, { connected: true })).toBe(false)
	})

	it('addresses the TeamsMeetingController route', () => {
		expect(teamsMeetingUrl('m-1')).toMatch(/\/apps\/decidiq\/api\/meetings\/m-1\/teams-meeting$/)
	})
})

describe('wiring', () => {
	it('puts the calendar subscription section in the user settings dialog', () => {
		const settings = source('src/views/settings/UserSettings.vue')
		expect(settings).toContain('<CalendarSubscriptionSection />')
		expect(settings).toContain("t('decidiq', 'Calendar subscription')")
		const section = source('src/components/userSettings/CalendarSubscriptionSection.vue')
		expect(section).toContain("'Subscribe in Outlook or on your phone'")
		expect(section).toContain("'In Outlook, choose Add calendar, then Subscribe from web'")
		expect(section).toContain("'Stop this subscription'")
		expect(section).toContain('axios.delete(feedsUrl(feed.id))')
	})

	it('shows Join in Microsoft Teams on the meeting page and the live meeting page, in a new tab', () => {
		const manifest = JSON.parse(source('src/manifest.json'))
		const page = manifest.pages.find((p) => p.id === 'MeetingDetail')
		const planning = page.config.widgets.find((w) => w.id === 'meeting-planning')
		expect(planning.content.include).toContain('teamsMeeting')
		expect(page.config.widgets.some((w) => w.id === 'meeting-teams' && w.component === 'MeetingTeamsTab')).toBe(true)
		expect(page.config.layout.some((l) => l.widgetId === 'meeting-teams')).toBe(true)
		expect(page.slots['widget-meeting-teams']).toBe('MeetingTeamsTab')
		expect(source('src/registry.js')).toContain('MeetingTeamsTab: page(MeetingTeamsTab)')

		for (const path of ['src/components/tabs/MeetingTeamsTab.vue', 'src/views/LiveMeeting.vue']) {
			const vue = source(path)
			expect(vue).toContain("t('decidiq', 'Join in Microsoft Teams')")
			expect(vue).toContain('target="_blank"')
			expect(vue).toContain('rel="noopener noreferrer"')
		}
		expect(source('src/components/tabs/MeetingTeamsTab.vue')).toContain("'Create a Teams meeting'")
	})
})
