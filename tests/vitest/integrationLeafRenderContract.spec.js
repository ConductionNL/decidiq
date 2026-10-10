/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every leaf descriptor decidiq exports ships the render pair its declared
 * `renderMode` needs, read off the LOADED MODULE rather than its source text.
 *
 * 🔴 WHY A SECOND CHECK, WHEN GATE-24 ALREADY READS THIS
 * `scripts/check-integration-parity.js` rule R1 asserts the same invariant by
 * scanning the file with a regular expression. That catches a descriptor
 * written wrong. It cannot catch a descriptor that is written right and
 * arrives wrong: a `mount` shorthand dropped by a refactor of the function it
 * names, an import that resolves to `undefined`, a key shadowed by a later
 * spread. This one imports the module and looks at the value, so the two
 * checks fail on different things and neither is a copy of the other.
 *
 * 🔑 THE CONTRACT IS THE REGISTRY'S, NOT THIS FILE'S
 * `@conduction/nextcloud-vue`'s `registry.register()` refuses a descriptor
 * whose declared mode has no pair (throws in dev, warns and returns null in
 * prod), which means an incomplete leaf never enters the registry at all. So
 * the failure mode is not "a leaf renders nothing", it is "a leaf is absent
 * and nobody was told". RENDER_CONTRACT below mirrors that registry rule; it
 * is asserted here, before a build, instead of discovered on a page.
 *
 * 🔑 IT FINDS THE LEAVES ITSELF. A new `src/integrations/register*Leaf.js` is
 * covered the day it lands, with nothing to remember to add here.
 *
 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md (REQ-AR-010)
 */
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'

const INTEGRATIONS = path.resolve(__dirname, '../../src/integrations')

/**
 * The keys a descriptor must carry for each render mode, mirroring
 * `registry.register()` in @conduction/nextcloud-vue.
 *
 * `component` is the registry's default when `renderMode` is absent, so an
 * undeclared mode is checked too rather than skipped.
 */
const RENDER_CONTRACT = {
	component: ['tab', 'widget'],
	mount: ['mount', 'unmount'],
}

/**
 * Every leaf registrar module in src/integrations.
 *
 * @return {string[]} Absolute paths.
 */
function registrarModules() {
	return fs
		.readdirSync(INTEGRATIONS)
		.filter((name) => /^register.*Leaf\.js$/.test(name))
		.sort()
		.map((name) => path.join(INTEGRATIONS, name))
}

/**
 * The leaf descriptors a module exports: any exported object carrying a
 * non-empty string `id` and `label`, which is what the registry requires
 * before it looks at anything else.
 *
 * @param {object} module The imported module namespace.
 * @return {Array<[string, object]>} Export name and descriptor pairs.
 */
function descriptorsIn(module) {
	return Object.entries(module).filter(
		([, value]) =>
			value !== null
			&& typeof value === 'object'
			&& typeof value.id === 'string'
			&& value.id !== ''
			&& typeof value.label === 'string'
			&& value.label !== '',
	)
}

describe('integration leaf render contract', () => {
	it('finds the leaf registrars, so the checks below are not vacuous', () => {
		// Guard the guard. An empty glob would make every assertion in this
		// file pass over nothing, which is the shape this repo keeps finding.
		expect(
			registrarModules().map((file) => path.basename(file)),
			'src/integrations must hold at least one register*Leaf.js',
		).not.toHaveLength(0)
	})

	it('every exported descriptor carries the pair its declared render mode needs', async () => {
		const files = registrarModules()
		let checked = 0

		for (const file of files) {
			const module = await import(file)
			const descriptors = descriptorsIn(module)

			expect(
				descriptors.length,
				`${path.basename(file)} exports no leaf descriptor, so its leaf is unreadable from here`,
			).toBeGreaterThan(0)

			for (const [name, descriptor] of descriptors) {
				const mode = descriptor.renderMode ?? 'component'

				expect(
					Object.keys(RENDER_CONTRACT),
					`${name} declares renderMode "${mode}", which the registry refuses`,
				).toContain(mode)

				for (const key of RENDER_CONTRACT[mode]) {
					expect(
						descriptor[key],
						`${name} declares renderMode "${mode}" but exposes no \`${key}\`, `
							+ 'so the registry drops the whole registration and the leaf '
							+ 'is absent from every surface with nothing to say so',
					).toBeTruthy()
				}

				if (mode === 'mount') {
					expect(typeof descriptor.mount).toBe('function')
					expect(typeof descriptor.unmount).toBe('function')
				}

				// The mount pair travels together in EITHER mode: the registry
				// rejects a descriptor supplying one half alone, even a
				// component-mode one using mount as a same-major fast path.
				expect(
					typeof descriptor.mount === 'function',
					`${name} supplies only one half of the mount pair`,
				).toBe(typeof descriptor.unmount === 'function')

				checked += 1
			}
		}

		expect(checked, 'no descriptor was checked').toBeGreaterThan(0)
	})
})
