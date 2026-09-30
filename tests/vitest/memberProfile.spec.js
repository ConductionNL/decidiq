// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * bodies-member-profile-and-voting-record (matrix rows bod-05, vot-18): every
 * person has a profile page with memberships, portfolio, outside positions and
 * voting record, and member lists link to it.
 *
 * @spec openspec/changes/bodies-member-profile-and-voting-record/specs/person-and-membership/spec.md
 */
import { readFileSync, readdirSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	initials,
	membershipSections,
	participantPersonQueries,
	profilePath,
	recordRows,
	votingRecordUrl,
} from '../../src/utils/memberProfile.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

describe('member profile helpers', () => {
	it('names the profile route and the record endpoint', () => {
		expect(profilePath('abc-1')).toBe('/people/abc-1')
		expect(votingRecordUrl('a/b')).toBe(
			'/apps/decidiq/api/people/a%2Fb/voting-record',
		)
	})

	it('shows initials when there is no photo', () => {
		expect(initials('Marie Janssen')).toBe('MJ')
		expect(initials('Femke')).toBe('F')
		expect(initials('W. van Dam')).toBe('WD')
		expect(initials('')).toBe('?')
	})

	it('lists current memberships first and earlier ones apart, with body, party and portfolio', () => {
		const bodies = {
			raad: { id: 'raad', name: 'Gemeenteraad Amsterdam' },
			college: { id: 'college', name: 'College van B en W Amsterdam' },
		}
		const memberships = [
			{ id: 'm1', governanceBody: 'raad', role: 'chair', party: 'GroenLinks', startDate: '2018-05-30T00:00:00Z' },
			{ id: 'm2', governanceBody: 'college', role: 'chair', startDate: '2018-07-12T00:00:00Z', portfolio: ['Public order and safety', 'Communication'] },
			{ id: 'm3', governanceBody: 'raad', role: 'member', party: 'PvdA', startDate: '2010-01-01T00:00:00Z', endDate: '2014-03-01T00:00:00Z' },
		]
		const { current, earlier } = membershipSections(memberships, bodies, new Date('2026-09-30T12:00:00Z'))
		expect(current.map((row) => row.id)).toEqual(['m2', 'm1'])
		expect(current[0]).toMatchObject({ bodyId: 'college', bodyName: 'College van B en W Amsterdam', portfolio: 'Public order and safety, Communication', party: '' })
		expect(current[1]).toMatchObject({ bodyName: 'Gemeenteraad Amsterdam', party: 'GroenLinks', portfolio: '' })
		expect(earlier.map((row) => row.id)).toEqual(['m3'])
	})

	it('turns the record answer into rows, keeping the server order', () => {
		const rows = recordRows({
			personId: 'marie',
			votes: [
				{ vote: 'v2', date: '2026-04-15T21:06:00Z', decision: { id: 'd2', title: 'Vaststelling Programmabegroting 2027' }, choice: 'for', result: 'adopted', party: 'D66' },
				{ vote: 'v1', date: '2025-04-10T21:01:00Z', decision: null, choice: 'against', result: 'rejected', party: null },
			],
		})
		expect(rows).toEqual([
			{ id: 'v2', date: '2026-04-15T21:06:00Z', decisionId: 'd2', decision: 'Vaststelling Programmabegroting 2027', choice: 'for', result: 'adopted', party: 'D66' },
			{ id: 'v1', date: '2025-04-10T21:01:00Z', decisionId: '', decision: '', choice: 'against', result: 'rejected', party: '' },
		])
		expect(recordRows(null)).toEqual([])
	})

	it('finds the person of a participant on the Nextcloud user id first, then the email', () => {
		expect(participantPersonQueries({ nextcloudUserId: 'mjanssen', email: 'm@x.nl' })).toEqual([
			{ nextcloudUserId: 'mjanssen' },
			{ email: 'm@x.nl' },
		])
		expect(participantPersonQueries({ email: 'm@x.nl' })).toEqual([{ email: 'm@x.nl' }])
		expect(participantPersonQueries({})).toEqual([])
	})
})

describe('the profile page is declared and reachable', () => {
	const fragment = JSON.parse(read('src/manifest.d/member-profile.json'))
	const page = fragment.pages.find((p) => p.id === 'PersonDetail')

	it('declares /people/:id on the person schema with its four widgets', () => {
		expect(page.route).toBe('/people/:id')
		expect(page.config.schema).toBe('person')
		const ids = page.config.widgets.map((w) => w.id)
		expect([...ids].sort()).toEqual(['person-data', 'person-memberships', 'person-outside-positions', 'person-voting-record'])
		const cells = page.config.layout.map((cell) => cell.widgetId)
		for (const id of ids) expect(cells).toContain(id)
		for (const widget of page.config.widgets.filter((w) => w.type === 'custom')) {
			expect(Object.values(page.slots)).toContain(widget.component)
		}
		const positions = page.config.widgets.find((w) => w.id === 'person-outside-positions')
		expect(positions.content).toMatchObject({ schema: 'ancillary-position', filter: { person: '@objectId' } })
	})

	it('registers both custom widgets', () => {
		const registry = read('src/registry.js')
		expect(registry).toMatch(/PersonMembershipsTab: page\(PersonMembershipsTab\)/)
		expect(registry).toMatch(/PersonVotingRecordTab: page\(PersonVotingRecordTab\)/)
	})

	it('links a member name in the body members widget to the profile', () => {
		const tab = read('src/components/tabs/GovernanceBodyMembersTab.vue')
		expect(tab).toMatch(/#column-displayName/)
		expect(tab).toMatch(/profilePath\(row\.person\)/)
	})

	it('offers the profile from the participant page', () => {
		const manifest = JSON.parse(read('src/manifest.json'))
		const participant = manifest.pages.find((p) => p.id === 'ParticipantDetail')
		expect(participant.config.widgets.map((w) => w.component)).toContain('ParticipantProfileLink')
		expect(read('src/registry.js')).toMatch(/ParticipantProfileLink: page\(ParticipantProfileLink\)/)
	})

	it('has no other fragment claiming the route', () => {
		const others = readdirSync(resolve(here, '../../src/manifest.d'))
			.filter((f) => f.endsWith('.json') && f !== 'member-profile.json')
			.flatMap((f) => JSON.parse(read(`src/manifest.d/${f}`)).pages || [])
		expect(others.filter((p) => p.route === '/people/:id')).toEqual([])
	})
})
