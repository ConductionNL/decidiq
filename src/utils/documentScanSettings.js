/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The document accessibility admin settings: how the publication gate treats
 * papers that fail the scan (off, warn or block; DocumentPublicationGate reads
 * it), and whether a paper is scanned when it is added (scan-on-upload;
 * DocumentScanOnUploadListener reads it).
 *
 * @spec openspec/changes/document-accessibility-check/specs/document-accessibility-check/spec.md#requirement-req-006-admin-settings-for-enforcement-and-scanning
 */

/** The enforcement modes, in the order the section offers them. */
export const ENFORCEMENT_MODES = ['off', 'warn', 'block']

/** The mode until an administrator picks one. */
export const DEFAULT_MODE = 'warn'

/**
 * The two settings as the admin section shows them.
 *
 * An unknown mode reads as the default; scan-on-upload is on unless the
 * stored value is an explicit off, as the server reads it.
 *
 * @param {object} settings The GET /api/settings answer.
 * @return {{mode: string, scanOnUpload: boolean}} The state.
 */
export function documentScanSetting(settings) {
	const mode = String(settings?.accessibility_enforcement_mode ?? DEFAULT_MODE)
		.trim()
		.toLowerCase()
	const scan = String(settings?.accessibility_scan_on_upload ?? 'true')
		.trim()
		.toLowerCase()
	return {
		mode: ENFORCEMENT_MODES.includes(mode) ? mode : DEFAULT_MODE,
		scanOnUpload: !['false', '0', 'no', 'off'].includes(scan),
	}
}

/**
 * The PUT /api/settings body for the two settings.
 *
 * @param {{mode: string, scanOnUpload: boolean}} state The state to store.
 * @return {object} The payload.
 */
export function documentScanPayload(state) {
	const mode = ENFORCEMENT_MODES.includes(state?.mode) ? state.mode : DEFAULT_MODE
	return {
		accessibility_enforcement_mode: mode,
		accessibility_scan_on_upload: state?.scanOnUpload === false ? 'false' : 'true',
	}
}
