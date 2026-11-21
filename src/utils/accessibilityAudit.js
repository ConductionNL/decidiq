/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The accessibility audit digest as the admin page shows it (board
 * DcBeheerSysteem, Toegankelijkheid). The digest is written by
 * scripts/wcag-audit-report.mjs and handed over as initial state.
 */

/**
 * The section's view of a digest; `available: false` when there is none.
 *
 * @param {object|null} digest The digest, or null.
 * @return {object} What the section shows.
 */
export function auditView(digest) {
	if (!digest || typeof digest !== 'object') {
		return { available: false }
	}
	const findings = Array.isArray(digest.findings) ? digest.findings : []
	return {
		available: true,
		standard: String(digest.standard ?? ''),
		sampleSize: Number(digest.sampleSize) || 0,
		date: String(digest.date ?? ''),
		scanned: digest.scanned === true,
		findingCount: findings.length,
		findings,
	}
}
