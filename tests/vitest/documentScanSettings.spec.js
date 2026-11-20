/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin section Document accessibility check: the enforcement mode the
 * publication gate reads and the scan-on-upload switch the upload listener
 * reads, as the section shows and writes them.
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-006-admin-settings-for-enforcement-and-scanning
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	DEFAULT_MODE,
	documentScanPayload,
	documentScanSetting,
	ENFORCEMENT_MODES,
} from '../../src/utils/documentScanSettings.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')

describe('the document accessibility settings', () => {
	it('warn and scan-on-upload until an administrator says otherwise', () => {
		expect(documentScanSetting({})).toEqual({ mode: 'warn', scanOnUpload: true })
		expect(documentScanSetting(undefined)).toEqual({ mode: 'warn', scanOnUpload: true })
		expect(DEFAULT_MODE).toBe('warn')
	})

	it('reads each mode, and an unknown mode as the default', () => {
		for (const mode of ENFORCEMENT_MODES) {
			expect(documentScanSetting({ accessibility_enforcement_mode: mode }).mode).toBe(mode)
		}
		expect(documentScanSetting({ accessibility_enforcement_mode: ' BLOCK ' }).mode).toBe('block')
		expect(documentScanSetting({ accessibility_enforcement_mode: 'strict' }).mode).toBe('warn')
	})

	it('reads scan-on-upload as off only on an explicit off, as the server does', () => {
		for (const off of ['false', '0', 'no', 'off', 'FALSE']) {
			expect(documentScanSetting({ accessibility_scan_on_upload: off }).scanOnUpload).toBe(false)
		}
		for (const on of ['true', '1', 'yes', '']) {
			expect(documentScanSetting({ accessibility_scan_on_upload: on }).scanOnUpload).toBe(true)
		}
	})

	it('writes both settings as the strings the server reads', () => {
		expect(documentScanPayload({ mode: 'block', scanOnUpload: false })).toEqual({
			accessibility_enforcement_mode: 'block',
			accessibility_scan_on_upload: 'false',
		})
		expect(documentScanPayload({ mode: 'nonsense', scanOnUpload: true })).toEqual({
			accessibility_enforcement_mode: 'warn',
			accessibility_scan_on_upload: 'true',
		})
		const service = read('lib', 'Service', 'SettingsService.php')
		expect(service).toContain("'accessibility_enforcement_mode',")
		expect(service).toContain("'accessibility_scan_on_upload',")
	})

	it('is a section of the admin page, not a route', () => {
		const root = read('src', 'views', 'settings', 'AdminRoot.vue')
		expect(root).toContain('<DocumentScanSettings v-if="storesReady" />')
		expect(read('src', 'main.js')).not.toContain('DocumentScanSettings')
	})
})
