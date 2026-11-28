/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every icon a manifest names by PascalCase is registered in src/icons.js.
 * An unregistered name renders no icon at all, not a fallback (ADR-077
 * rule 3), and nothing else notices: the document-accessibility pages named
 * ShieldAlertOutline for weeks without it being registered.
 *
 * @spec openspec/changes/archive/2026-11-20-document-accessibility-check/tasks.md
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import icons from '../../src/icons.js'

const root = path.resolve(__dirname, '../..')

/**
 * The manifest files: the main manifest and every fragment.
 *
 * @return {string[]} Absolute paths
 */
function manifestFiles() {
	const fragmentDir = path.join(root, 'src/manifest.d')
	const fragments = fs.readdirSync(fragmentDir)
		.filter((name) => name.endsWith('.json'))
		.map((name) => path.join(fragmentDir, name))
	return [path.join(root, 'src/manifest.json'), ...fragments]
}

/**
 * Every PascalCase value of an `icon` key anywhere in a JSON tree.
 *
 * @param {*} node The parsed JSON
 * @param {Set<string>} found Collector
 * @return {Set<string>} The icon names
 */
function iconNames(node, found = new Set()) {
	if (Array.isArray(node)) {
		node.forEach((child) => iconNames(child, found))
	} else if (node !== null && typeof node === 'object') {
		for (const [key, value] of Object.entries(node)) {
			if (key === 'icon' && typeof value === 'string' && /^[A-Z][A-Za-z0-9]+$/.test(value)) {
				found.add(value)
			} else {
				iconNames(value, found)
			}
		}
	}
	return found
}

describe('manifest icons', () => {
	it('registers every icon a manifest names', () => {
		const missing = []
		for (const file of manifestFiles()) {
			const manifest = JSON.parse(fs.readFileSync(file, 'utf8'))
			for (const name of iconNames(manifest)) {
				if (!(name in icons)) {
					missing.push(`${path.relative(root, file)}: ${name}`)
				}
			}
		}
		expect(missing).toEqual([])
	})

	it('finds icon names nested anywhere in a manifest', () => {
		const names = iconNames({ menu: [{ icon: 'ShieldAlertOutline', children: [{ icon: 'Star' }] }], icon: 'not-pascal' })
		expect([...names].sort()).toEqual(['ShieldAlertOutline', 'Star'])
	})
})
