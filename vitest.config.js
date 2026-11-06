/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidesk Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest configuration for Decidesk frontend unit tests.
 *
 * Decidesk delegates object CRUD to @conduction/nextcloud-vue's shared store,
 * so the app-local offline logic is concentrated in:
 *   • the settings Pinia store (src/store/modules/settings.js) — fetch
 *     envelope-unwrap, hasOpenRegisters/isAdmin flag derivation, loading
 *     lifecycle.
 *   • ensureRelationType (src/components/tabs/useRelationStore.js) — the
 *     logical-type → schema-slug resolution + register fallback used by the
 *     relation tabs.
 *
 * These need no DOM, so the environment is `node`. global fetch is mocked
 * per-test; @nextcloud/auth + @nextcloud/router are aliased to stubs.
 */

const path = require('path')

/**
 * Resolve `.vue` imports to an inert options object.
 *
 * The environment is `node` with no SFC transform, so a module that imports a
 * component cannot be loaded at all, which is why the leaf descriptors, whose
 * whole job is to name components, had no unit coverage of their own. The
 * assertions that need them (tests/vitest/integrationLeafRenderContract.spec.js)
 * read the descriptor's KEYS, never the component's behaviour, so a stub is
 * indistinguishable from the real SFC there. Anything that wants to render a
 * component still needs a real transform and must not rely on this.
 *
 * @type {import('vite').Plugin}
 */
const stubSingleFileComponents = {
	name: 'decidiq-stub-sfc',
	enforce: 'pre',
	resolveId(source) {
		return source.endsWith('.vue') ? '\0decidiq-sfc-stub' : null
	},
	load(id) {
		return id === '\0decidiq-sfc-stub'
			? 'export default { name: "SfcStub", render() { return null } }'
			: null
	},
}

module.exports = {
	plugins: [stubSingleFileComponents],
	test: {
		environment: 'node',
		globals: false,
		include: ['tests/vitest/**/*.spec.{js,ts}'],
		exclude: [
			'tests/e2e/**',
			'tests/integration/**',
			'src/**',
			'node_modules/**',
		],
	},
	resolve: {
		alias: [
			{ find: '@', replacement: path.resolve(__dirname, 'src') },
			{
				find: /^@nextcloud\/router$/,
				replacement: path.resolve(
					__dirname,
					'tests/vitest/stubs/nextcloud-router.js',
				),
			},
			{
				find: /^@nextcloud\/auth$/,
				replacement: path.resolve(
					__dirname,
					'tests/vitest/stubs/nextcloud-auth.js',
				),
			},
		],
	},
}
