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

module.exports = {
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
