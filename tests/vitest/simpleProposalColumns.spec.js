/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The proposals list in the simple structure (board DcVoorstellen): the
 * columns read Title, Proposer, Body, Stage and Next meeting, in the board's
 * order, before the columns the list already had. The Portfolio holder
 * column is decisionPortfolioHolder.spec.js.
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

/**
 * Build one structure from the manifest, the fragments and a profile file.
 *
 * @param {string} file The profile file under src/.
 * @return {object} The built manifest.
 */
function build(file) {
	return buildProfiledManifest(
		buildManifest,
		readJson('src', 'manifest.json'),
		fragments,
		readJson('src', file),
	)
}

/**
 * The decision schema's properties: the base register plus every fragment.
 *
 * @return {object} The merged properties.
 */
function decisionProperties() {
	const properties = {
		...readJson('lib', 'Settings', 'decidesk_register.json').components.schemas
			.Decision.properties,
	}
	const dir = path.join(ROOT, 'lib', 'Settings', 'register.d')
	for (const name of fs.readdirSync(dir).sort()) {
		if (!name.endsWith('.json')) continue
		const schemas =
			readJson('lib', 'Settings', 'register.d', name).components?.schemas ?? {}
		for (const [key, schema] of Object.entries(schemas)) {
			if (key === 'Decision' || schema?.slug === 'decision') {
				Object.assign(properties, schema.properties ?? {})
			}
		}
	}
	return properties
}

const keyOf = (column) => (typeof column === 'string' ? column : column.key)
/**
 * The proposals list's columns in a built structure.
 *
 * @param {object} built The built manifest.
 * @return {Array<string|object>} The columns.
 */
function columnsOf(built) {
	return built.pages.find((page) => page.id === 'Motions').config.columns
}
const simple = columnsOf(build('menu-layout.simple.json'))
const full = columnsOf(build('menu-layout.json'))
const dutch = readJson('l10n', 'nl.json').translations
const properties = decisionProperties()

describe('the proposals list in the simple structure', () => {
	it('leads with the board columns in the board order', () => {
		expect(simple.slice(0, 5).map(keyOf)).toEqual([
			'title',
			'proposer',
			'governingBody',
			'lifecycle',
			'meeting',
		])
	})

	it('keeps every column the list had', () => {
		for (const column of full) {
			expect(simple.map(keyOf), keyOf(column)).toContain(keyOf(column))
		}
		expect(new Set(simple.map(keyOf)).size).toBe(simple.length)
	})

	it('reads the new columns off real fields, the meeting by its title', () => {
		const added = simple.filter((column) => typeof column === 'object')
		expect(added.map(keyOf)).toEqual([
			'governingBody',
			'meeting',
			'portfolioHolder',
		])
		for (const column of added) {
			expect(properties[column.key], column.key).toBeDefined()
			expect(dutch[column.label], column.label).toBeTruthy()
		}
		expect(properties.meeting.format).toBe('uuid')
		expect(added[1].labelField).toBe('title')
		expect(dutch[added[0].label]).toBe('Orgaan')
		expect(dutch[added[1].label]).toBe('Volgende vergadering')
	})

	it('leaves the full structure as the manifest declares it', () => {
		expect(full.map(keyOf)).toEqual(
			readJson('src', 'manifest.json')
				.pages.find((page) => page.id === 'Motions')
				.config.columns.map(keyOf),
		)
	})
})
