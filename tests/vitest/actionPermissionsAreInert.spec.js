/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * No action in the shipped manifests may declare a `permission`.
 *
 * 🔴 A `permission` ON AN ACTION LOOKS LIKE A GATE AND IS NOT ONE.
 *
 * @conduction/nextcloud-vue reads a singular `permission` in two places only:
 * `CnAppNav.passesPermission` (menu entries) and
 * `CnAppRoot.passesIntegrationPermission` (the settings Integrations section).
 * `CnIndexPage`, `CnRowActions` and `CnContextMenu` never look at it, and this
 * app's route guard reads `pages[].permission`, not actions. So an action
 * carrying one renders for every account, while the JSON reads like a working
 * gate and the manifest schema accepts it.
 *
 * Measured: the Decisions index declared a Publish row action with
 * `permission: "decidesk.decision.publish"` (#1263, #1270). It rendered for
 * everyone, and with `handler: "emit"` and no listener it did nothing either.
 * It was removed; publishing is gated server-side by POST /api/publications.
 *
 * Gate an action by what the library does honour (`visibleWhen` on the row,
 * or the server refusing the call), not by this key.
 *
 * @spec exclude Structural invariant of the shipped manifests; no behavioural spec.
 */
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/** The base manifest, every fragment and both menu layouts. */
function manifestFiles() {
	const files = [path.join(ROOT, 'src', 'manifest.json')]
	const dir = path.join(ROOT, 'src', 'manifest.d')
	if (fs.existsSync(dir)) {
		for (const name of fs.readdirSync(dir).sort()) {
			if (name.endsWith('.json')) files.push(path.join(dir, name))
		}
	}
	for (const name of fs.readdirSync(path.join(ROOT, 'src')).sort()) {
		if (/^menu-layout.*\.json$/.test(name))
			files.push(path.join(ROOT, 'src', name))
	}
	return files
}

/**
 * Every element of an array held under a key ending in `actions` (actions,
 * headerActions, bulkActions, quickActions, ...) that declares `permission`.
 *
 * @param {unknown} node The JSON node to walk.
 * @param {string} at The JSON path of `node`.
 * @param {Array<string>} found Accumulator of offending paths.
 * @return {Array<string>} The offending paths.
 */
function actionsWithPermission(node, at = '$', found = []) {
	if (Array.isArray(node)) {
		node.forEach((child, i) =>
			actionsWithPermission(child, `${at}[${i}]`, found),
		)
		return found
	}
	if (node === null || typeof node !== 'object') return found
	for (const [key, value] of Object.entries(node)) {
		const here = `${at}.${key}`
		if (/actions$/i.test(key) && Array.isArray(value)) {
			value.forEach((action, i) => {
				if (action && typeof action === 'object' && 'permission' in action) {
					found.push(`${here}[${i}] (${action.id ?? '?'})`)
				}
			})
		}
		actionsWithPermission(value, here, found)
	}
	return found
}

describe('action-level permission declarations', () => {
	it('the walker finds one when it is there', () => {
		const sample = {
			pages: [{ config: { actions: [{ id: 'publish', permission: 'x' }] } }],
		}
		expect(actionsWithPermission(sample)).toEqual([
			'$.pages[0].config.actions[0] (publish)',
		])
	})

	it('the walker leaves menu-level permissions alone, which the library does read', () => {
		expect(
			actionsWithPermission({ menu: [{ id: 'a', permission: 'admin' }] }),
		).toEqual([])
	})

	for (const file of manifestFiles()) {
		it(`${path.relative(ROOT, file)} declares none, because nothing reads them`, () => {
			const json = JSON.parse(fs.readFileSync(file, 'utf8'))
			expect(actionsWithPermission(json)).toEqual([])
		})
	}
})
