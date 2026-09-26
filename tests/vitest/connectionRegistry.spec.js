/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The Integrations page over integriq's connection registry
 * (adopt-connection-registry, hydra connection-registry D8 and D9).
 *
 * The page is declared in JSON and resolves two formatters and one handler by
 * NAME. A misspelled name renders a raw enum or an Add integration that does
 * nothing, and neither logs a thing. So this spec reads the real fragment and
 * checks every name against the module that has to answer it.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-adm-conn-003-an-admin-reads-decidiqs-connections-on-an-integrations-page
 */

import { BUILT_IN_FORMATTERS } from '@conduction/nextcloud-vue/src/utils/builtInFormatters.js'
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import cellFormatters from '../../src/utils/cellFormatters.js'
import {
	createConnectionHandlers,
	INTEGRIQ_CONNECTIONS_PATH,
} from '../../src/utils/connectionRegistry.js'

const ROOT = path.resolve(__dirname, '../..')
const fragment = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'src/manifest.d/connection-registry.json'),
		'utf8',
	),
)
const page = fragment.pages.find((p) => p.id === 'ConnectionRegistry')
const menu = fragment.menu.find((m) => m.id === 'ConnectionRegistryMenu')

/**
 * The registry the Integrations page resolves a formatter name against,
 * merged the way CnAppRoot merges it: the app's own formatters win over the
 * built-ins, so a stale local copy would shadow the library's.
 */
const registry = { ...BUILT_IN_FORMATTERS, ...cellFormatters }

describe('connection formatters', () => {
	it('labels a switched-off connection through the nextcloud-vue built-in', () => {
		expect(registry.connectionStatus('disabled')).toBe('Switched off')
	})

	it('ships the Add integration label in English and Dutch', () => {
		const en = JSON.parse(
			fs.readFileSync(path.join(ROOT, 'l10n/en.json'), 'utf8'),
		).translations
		const nl = JSON.parse(
			fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8'),
		).translations
		expect(en['Add integration']).toBe('Add integration')
		expect(nl['Add integration']).toBeTruthy()
	})
})

describe('Add integration handler', () => {
	it('opens integriq on the link dialog, preset to decidiq', () => {
		const opened = []
		const handlers = createConnectionHandlers({
			generateUrl: (p) => `/index.php${p}`,
			assign: (url) => opened.push(url),
		})

		handlers.openIntegriqConnections()

		expect(INTEGRIQ_CONNECTIONS_PATH).toBe(
			'/apps/integriq/connections?app=decidiq&link=1',
		)
		expect(opened).toEqual([
			'/index.php/apps/integriq/connections?app=decidiq&link=1',
		])
	})
})

describe('the Integrations page declaration', () => {
	it('lists integriq app_connection rows and requires integriq', () => {
		expect(page.type).toBe('index')
		expect(page.route).toBe('/settings/integrations')
		expect(page.permission).toBe('admin')
		expect(page.requiresApp.id).toBe('integriq')
		expect(page.config.register).toBe('integriq')
		expect(page.config.schema).toBe('app_connection')
		expect(page.config.showAdd).toBe(false)
	})

	it('scopes the rows to decidiq through the menu preset, admin only, in the gear', () => {
		expect(menu.route).toBe(page.id)
		expect(menu.query).toEqual({ app: 'decidiq' })
		expect(menu.section).toBe('settings')
		expect(menu.permission).toBe('admin')
		expect(menu.visibleIf).toEqual({ appInstalled: 'integriq' })
	})

	it('names only formatters and handlers that exist', () => {
		const handlers = createConnectionHandlers({
			generateUrl: (p) => p,
			assign: () => {},
		})

		for (const column of page.config.columns.filter((c) => c.formatter)) {
			expect(typeof registry[column.formatter], column.formatter).toBe(
				'function',
			)
		}
		for (const action of page.config.headerActions) {
			expect(typeof handlers[action.handler], action.handler).toBe('function')
		}
	})

	it('keeps its own id apart from the per-object integration pages', () => {
		const base = JSON.parse(
			fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'),
		)
		const ids = base.pages.map((p) => p.id)
		expect(ids).toContain('MotionIntegrations')
		expect(ids).not.toContain(page.id)
		expect(base.pages.map((p) => p.route)).not.toContain(page.route)
	})
})
