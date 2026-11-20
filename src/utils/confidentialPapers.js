// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The portal trust setting for confidential papers
 * (portal-voting-record-and-confidential-papers, REQ-PSH-003).
 */

/** The levels an administrator can pick, lowest first. */
export const CONFIDENTIAL_TRUST_LEVELS = ['substantial', 'high']

/**
 * The level the settings hold: `substantial` when unset, `high` for any other value.
 * The server reads it the same way (ConfidentialPapers::trustOf).
 *
 * @param {object} settings The app settings.
 * @return {string} `substantial` or `high`.
 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-003-confidential-papers-reach-only-a-named-reader-at-the-declared-trust-level
 */
export function confidentialTrust(settings) {
	const value = String(settings?.portal_confidential_min_trust ?? '')
		.trim()
		.toLowerCase()
	return value === '' || value === 'substantial' ? 'substantial' : 'high'
}

/**
 * The settings write for one level.
 *
 * @param {string} level `substantial` or `high`.
 * @return {{portal_confidential_min_trust: string}}
 * @spec openspec/changes/portal-voting-record-and-confidential-papers/specs/portal-contribution/spec.md#requirement-req-psh-003-confidential-papers-reach-only-a-named-reader-at-the-declared-trust-level
 */
export function confidentialTrustPayload(level) {
	return { portal_confidential_min_trust: confidentialTrust({ portal_confidential_min_trust: level }) }
}
