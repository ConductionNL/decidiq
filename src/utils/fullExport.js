// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Full data export helpers (platform-full-data-export).
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
 */

const EXPORT_NAME = /^decidiq-export-\d{8}-\d{6}\.zip$/

/**
 * The download path of an export, or '' when the name is not an export.
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
 * @param {string} name The export file name
 * @return {string} App-relative path
 */
export function exportDownloadPath(name) {
	if (typeof name !== 'string' || !EXPORT_NAME.test(name)) {
		return ''
	}
	return `/apps/decidiq/api/export/full/${encodeURIComponent(name)}`
}

/**
 * A file size in units people read: KB, MB or GB.
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-req-pfe-001-an-administrator-exports-all-data
 * @param {number} bytes The size
 * @return {string} The size with its unit
 */
export function exportSize(bytes) {
	const n = Number(bytes) || 0
	if (n >= 1024 ** 3) {
		return `${(n / 1024 ** 3).toFixed(1)} GB`
	}
	if (n >= 1024 ** 2) {
		return `${(n / 1024 ** 2).toFixed(1)} MB`
	}
	return `${Math.max(1, Math.round(n / 1024))} KB`
}
