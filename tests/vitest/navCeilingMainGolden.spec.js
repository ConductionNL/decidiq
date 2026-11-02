/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the nav-ceiling gate's command line: the script runs over
 * small fixture repositories, and its exit code, stdout and stderr are
 * snapshotted with the fixture path replaced by <root> and the JSON parser's
 * own message (it differs between Node versions) left out.
 *
 * @spec openspec/architecture/adr-004-information-architecture.md
 */
import { spawnSync } from 'child_process'
import fs from 'fs'
import os from 'os'
import path from 'path'
import { afterAll, describe, expect, it } from 'vitest'

const SCRIPT = path.resolve(__dirname, '../../scripts/check-nav-ceiling.js')
const roots = []

const entry = (id, extra = {}) => ({ id, label: id, route: { name: id }, ...extra })
const six = ['a', 'b', 'c', 'd', 'e', 'f'].map((id) => entry(id))

const CASES = {
	'no manifest': {},
	'manifest not json': { 'src/manifest.json': '{ nope' },
	'manifest without menu': { 'src/manifest.json': {} },
	'six entries, no layout': { 'src/manifest.json': { menu: six } },
	'seven entries': { 'src/manifest.json': { menu: [...six, entry('g')] } },
	'footer and settings do not count': {
		'src/manifest.json': {
			menu: [
				...six,
				entry('h', { section: 'footer' }),
				entry('s', { section: 'settings' }),
			],
		},
	},
	'layout not json': {
		'src/manifest.json': { menu: six },
		'src/menu-layout.json': '[',
	},
	'layout with wrong types': {
		'src/manifest.json': { menu: [...six, entry('g')] },
		'src/menu-layout.json': {
			relocations: 'x',
			removals: 'g',
			settingsSection: { g: 1 },
		},
	},
	'layout null relocations': {
		'src/manifest.json': { menu: six },
		'src/menu-layout.json': { relocations: null },
	},
	'unplaced fragment entry': {
		'src/manifest.json': { menu: six.slice(0, 2) },
		'src/manifest.d/b-extra.json': { menu: [entry('x')] },
		'src/manifest.d/a-first.json': { menu: [entry('y', { section: 'footer' })] },
	},
	'fragment entries placed every way': {
		'src/manifest.json': { menu: six.slice(0, 3) },
		'src/manifest.d/one.json': {
			menu: [
				entry('r'),
				entry('m'),
				entry('t'),
				entry('u', { section: 'settings' }),
			],
		},
		'src/manifest.d/notes.txt': 'ignored',
		'src/menu-layout.json': {
			relocations: { r: 'a' },
			removals: ['m'],
			settingsSection: ['t'],
		},
	},
	'fragment not json': {
		'src/manifest.json': { menu: six },
		'src/manifest.d/broken.json': '{',
		'src/manifest.d/fine.json': { title: 'no menu' },
	},
	'everything at once': {
		'src/manifest.json': { menu: [...six, entry('g'), entry('h')] },
		'src/manifest.d/broken.json': 'x',
		'src/manifest.d/extra.json': { menu: [entry('z')] },
		'src/menu-layout.json': { removals: ['h'] },
	},
	'layout relocations as a list, valid removals': {
		'src/manifest.json': { menu: [...six, entry('g'), entry('h')] },
		'src/manifest.d/x.json': { menu: [entry('g')] },
		'src/menu-layout.json': {
			relocations: ['g'],
			removals: ['h', 'g'],
			settingsSection: 'h',
		},
	},
	'layout settings section only': {
		'src/manifest.json': { menu: [...six, entry('g')] },
		'src/manifest.d/x.json': { menu: [entry('g')] },
		'src/menu-layout.json': { settingsSection: ['g'], removals: null },
	},
	'relocations as text, removals defaulted': {
		'src/manifest.json': { menu: [...six, entry('h')] },
		'src/manifest.d/zero.json': { menu: [entry('0')] },
		'src/menu-layout.json': { relocations: 'ab', removals: 'h' },
	},
	'settings section defaulted': {
		'src/manifest.json': { menu: six },
		'src/manifest.d/x.json': { menu: [entry('a')] },
		'src/menu-layout.json': { settingsSection: 'a' },
	},
	'empty menu-layout object': {
		'src/manifest.json': { menu: six },
		'src/menu-layout.json': {},
	},
}

/**
 * Write a fixture repository.
 *
 * @param {object} files - relative path to contents (a string, or JSON)
 * @return {string} the repository root
 */
function fixture(files) {
	const root = fs.mkdtempSync(path.join(os.tmpdir(), 'nav-ceiling-'))
	roots.push(root)
	for (const [rel, body] of Object.entries(files)) {
		const file = path.join(root, rel)
		fs.mkdirSync(path.dirname(file), { recursive: true })
		fs.writeFileSync(
			file,
			typeof body === 'string' ? body : JSON.stringify(body),
		)
	}
	return root
}

afterAll(() => {
	for (const root of roots) {
		fs.rmSync(root, { recursive: true, force: true })
	}
})

describe('check-nav-ceiling main golden', () => {
	for (const [name, files] of Object.entries(CASES)) {
		it(name, () => {
			const root = fixture(files)
			const run = spawnSync(process.execPath, [SCRIPT, root], {
				encoding: 'utf8',
			})
			const clean = (text) =>
				text
					.split(root)
					.join('<root>')
					.replace(
						/is not valid JSON \((.*)\)$/gm,
						'is not valid JSON (<parser message>)',
					)
			expect({
				status: run.status,
				stdout: clean(run.stdout),
				stderr: clean(run.stderr),
			}).toMatchSnapshot()
		})
	}
})
