/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Voting groups (board DcBeheer, Stemgroepen): fixed groups of members of a
 * body, kept by the secretariat. Register fragment 127 declares the schema
 * and attaches it to the register; the admin page lists the groups and
 * links to the page where they are made and changed.
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'
import {
	excludedMemberNames,
	meetingBodyId,
	votingGroupChoices,
	votingGroupRows,
} from '../../src/utils/votingGroups.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))

describe('voting groups', () => {
	const fragment = readJson(
		'lib',
		'Settings',
		'register.d',
		'127-voting-groups.json',
	)
	const schema = fragment.components.schemas.VotingGroup

	it('are a schema attached to the register', () => {
		expect(schema.slug).toBe('voting-group')
		expect(fragment.components.registers.decidiq.schemas).toContain(
			'voting-group',
		)
		expect(schema.required).toEqual(['name', 'governanceBody'])
		expect(schema.properties.governanceBody).toMatchObject({
			format: 'uuid',
			$ref: 'governance-body',
		})
		expect(schema.properties.members.items).toMatchObject({
			format: 'uuid',
			$ref: 'person',
		})
		expect(schema.authorization.create).toContain('decidiq-secretariat')
	})

	it('bump the app version so the register is read in again', () => {
		// At least the version that brought this change: a later bump by
		// another change must not turn this red.
		const version = read('appinfo', 'info.xml').match(/<version>([^<]+)<\/version>/)[1]
		const parts = (value) => value.match(/\d+/g).map(Number)
		const [have, need] = [parts(version), parts('1.2.6-unstable.20261020200000')]
		const order = need.map((n, i) => Math.sign((have[i] ?? 0) - n)).find((d) => d !== 0) ?? 0
		expect(order, version).toBeGreaterThanOrEqual(0)
	})

	it('have a list page and a detail page', () => {
		const built = buildProfiledManifest(
			buildManifest,
			readJson('src', 'manifest.json'),
			fragments,
			readJson('src', 'menu-layout.simple.json'),
		)
		const list = built.pages.find((page) => page.id === 'VotingGroups')
		expect(list).toMatchObject({ route: '/voting-groups', type: 'index' })
		expect(list.config.schema).toBe('voting-group')
		expect(
			built.pages.find((page) => page.id === 'VotingGroupDetail').route,
		).toBe('/voting-groups/:id')
	})

	it('are listed on the admin page with their member count', () => {
		expect(
			votingGroupRows([
				{ id: 'g-1', name: 'Presidium', members: ['a', 'b'] },
				{ id: 'g-2', name: 'Whole council' },
			]),
		).toEqual([
			{ id: 'g-1', name: 'Presidium', memberCount: 2 },
			{ id: 'g-2', name: 'Whole council', memberCount: 0 },
		])
		const root = read('src', 'views', 'settings', 'AdminRoot.vue')
		expect(root.indexOf('<VotingGroupsSettings')).toBeGreaterThan(
			root.indexOf('<Settings v-if'),
		)
	})
})

describe('voting groups when a round opens (REQ-VGP-002, REQ-VGP-005)', () => {
	const GROUPS = [
		{ id: 'g-az', name: 'Commissie AZ', governanceBody: 'raad', members: ['a', 'b', 'c'], active: true },
		{ id: 'g-old', name: 'Oude commissie', governanceBody: 'raad', members: ['a'], active: false },
		{ id: 'g-other', name: 'Presidium', governanceBody: 'another-body', members: ['a'] },
		{ id: 'g-all', name: 'Voltallige raad', governanceBody: { id: 'raad' }, members: ['a', 'b', 'c', 'd'] },
	]

	it('offers the active groups of the meeting body, by name, with their member counts', () => {
		expect(votingGroupChoices(GROUPS, 'raad')).toEqual([
			{ id: 'g-az', name: 'Commissie AZ', memberCount: 3 },
			{ id: 'g-all', name: 'Voltallige raad', memberCount: 4 },
		])
	})

	it('offers nothing when the meeting body is unknown', () => {
		expect(votingGroupChoices(GROUPS, '')).toEqual([])
		expect(votingGroupChoices(null, 'raad')).toEqual([])
	})

	it('reads the meeting body from the field or the flat relation', () => {
		expect(meetingBodyId({ governanceBody: 'raad' })).toBe('raad')
		expect(meetingBodyId({ governanceBody: { id: 'raad' } })).toBe('raad')
		expect(meetingBodyId({ '@self': { relations: { governanceBody: 'raad' } } })).toBe('raad')
		expect(meetingBodyId(null)).toBe('')
	})

	it('names the members the group left out, and nobody when it left none out', () => {
		expect(excludedMemberNames({ excludedGroupMembers: [{ id: 'p-10', name: 'Anna Jansen' }, { id: 'p-11', name: '' }] }))
			.toEqual(['Anna Jansen', 'p-11'])
		expect(excludedMemberNames({ id: 'round-1' })).toEqual([])
		expect(excludedMemberNames(null)).toEqual([])
	})

	it('the open-round panel sends the chosen group and shows who was left out', () => {
		const panel = read('src', 'components', 'VotingRoundPanel.vue')
		expect(panel).toContain("votingGroup: this.revoteOfRoundId ? null : (this.newRound.votingGroup || null)")
		expect(panel).toContain('data-testid="voting-group-choice"')
		expect(panel).toContain('data-testid="voting-group-left-out"')
	})
})
