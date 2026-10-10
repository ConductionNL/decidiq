// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * platform-full-data-export (matrix row pub-15): the admin settings page
 * offers Export all data and a download link to the newest export only.
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { exportDownloadPath, exportSize } from '../../src/utils/fullExport.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

describe('an administrator exports all data (REQ-PFE-001)', () => {
	it('links only to an export file', () => {
		expect(exportDownloadPath('decidiq-export-20261014-201500.zip')).toBe(
			'/apps/decidiq/api/export/full/decidiq-export-20261014-201500.zip',
		)
		expect(exportDownloadPath('../../config/config.php')).toBe('')
		expect(exportDownloadPath(undefined)).toBe('')
	})

	it('names the size in units people read', () => {
		expect(exportSize(500)).toBe('1 KB')
		expect(exportSize(5 * 1024 * 1024)).toBe('5.0 MB')
		expect(exportSize(2.5 * 1024 ** 3)).toBe('2.5 GB')
	})

	it('shows the panel on the admin settings page', () => {
		expect(read('src/views/settings/AdminRoot.vue')).toContain(
			'<FullExportSettings',
		)
		expect(read('src/views/settings/FullExportSettings.vue')).toContain(
			"generateUrl('/apps/decidiq/api/export/full')",
		)
	})

	it('routes the three export endpoints', () => {
		const routes = read('appinfo/routes.php')
		expect(routes).toContain("'fullExport#start'")
		expect(routes).toContain("'fullExport#latest'")
		expect(routes).toContain("'fullExport#download'")
	})
})
