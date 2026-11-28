// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Archival dossier disposition: the URLs of the four dossier actions and
 * the state the disposition panel shows for a described dossier. Pure, so
 * the panel's choices are tested without a browser.
 *
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
 */

/**
 * The disposition URL of a dossier (GET describes, POST hands it over).
 *
 * @param {string} id The dossier
 * @return {string}
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
 */
export function dispositionUrl(id) {
	return `/apps/decidiq/api/dossiers/${encodeURIComponent(id)}/disposition`
}

/**
 * The URL that reads OpenRegister's outcome for a dossier.
 *
 * @param {string} id The dossier
 * @return {string}
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-005-destruction-via-openregister-destruction-lists
 */
export function outcomeUrl(id) {
	return `/apps/decidiq/api/dossiers/${encodeURIComponent(id)}/outcome`
}

/**
 * The URL that renders OpenRegister's destruction certificate for a dossier.
 *
 * @param {string} id The dossier
 * @return {string}
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-006-vernietigingsverklaring-rendering
 */
export function certificateUrl(id) {
	return `/apps/decidiq/api/dossiers/${encodeURIComponent(id)}/certificate`
}

/**
 * What the panel shows for a described dossier:
 * - `forming`: not closed yet, nothing to hand over;
 * - `no-category`: the register names no Selectielijst category;
 * - `transfer-unavailable`: a transfer route without an e-depot connection;
 * - `ready`: closed, routed, and OpenRegister can take it;
 * - `on-list`: on an OpenRegister list, waiting for its outcome;
 * - `done`: transferred or destroyed.
 *
 * @param {object} described The disposition description
 * @return {string}
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-004-transfer-via-openregister-transfer-lists-and-e-depot
 */
export function panelState(described) {
	const lifecycle = described?.lifecycle
	if (lifecycle === 'transferred' || lifecycle === 'destroyed') return 'done'
	if (described?.transferList || described?.destructionList) return 'on-list'
	if (lifecycle !== 'closed') return 'forming'
	if (!described?.route) return 'no-category'
	if (described.route === 'transfer' && described.transferAvailable !== true) {
		return 'transfer-unavailable'
	}
	return 'ready'
}

/**
 * Whether the destruction certificate can be asked for: the dossier was
 * destroyed through an OpenRegister destruction list.
 *
 * @param {object} described The disposition description
 * @return {boolean}
 * @spec openspec/changes/records-management-archiving/specs/records-management-archiving/spec.md#requirement-req-rma-006-vernietigingsverklaring-rendering
 */
export function canRenderCertificate(described) {
	return (
		described?.lifecycle === 'destroyed' && Boolean(described?.destructionList)
	)
}
