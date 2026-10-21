/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A decision names its portfolio holder (board DcVoorstellen, column
 * Portefeuillehouder; DcVoorstel side card): the executive member
 * responsible, a Person, declared in register fragment 126 and shown as the
 * last of the board's columns on the proposals list.
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))

const simple = buildProfiledManifest(
	buildManifest,
	readJson('src', 'manifest.json'),
	fragments,
	readJson('src', 'menu-layout.simple.json'),
)
const keyOf = (column) => (typeof column === 'string' ? column : column.key)
const dutch = readJson('l10n', 'nl.json').translations

describe('the portfolio holder of a decision', () => {
	const fragment = readJson(
		'lib',
		'Settings',
		'register.d',
		'126-decision-portfolio-holder.json',
	)
	const decision = fragment.components.schemas.Decision

	it('is a Person reference on the decision schema, with a newer schema version', () => {
		expect(decision.slug).toBe('decision')
		expect(decision.properties.portfolioHolder).toMatchObject({
			type: 'string',
			format: 'uuid',
			$ref: 'Person',
		})
		const [major, minor] = decision.version.split('.').map(Number)
		expect(major * 100 + minor).toBeGreaterThan(106)
	})

	it('bumps the app version so the register is read in again', () => {
		expect(read('appinfo', 'info.xml')).toContain(
			'<version>1.2.6-unstable.20261020200000</version>',
		)
	})

	it('is the last of the board columns on the proposals list, by name', () => {
		const columns = simple.pages.find((page) => page.id === 'Motions').config
			.columns
		expect(columns.slice(0, 6).map(keyOf)).toEqual([
			'title',
			'proposer',
			'governingBody',
			'lifecycle',
			'meeting',
			'portfolioHolder',
		])
		const column = columns.find((item) => keyOf(item) === 'portfolioHolder')
		expect(column.labelField).toBe('name')
		expect(dutch[column.label]).toBe('Portefeuillehouder')
	})
})
