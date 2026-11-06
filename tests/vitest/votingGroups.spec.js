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
import { votingGroupRows } from '../../src/utils/votingGroups.js'

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
		expect(read('appinfo', 'info.xml')).toContain(
			'<version>1.2.6-unstable.20261020200000</version>',
		)
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
