// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Who fixes an accessibility finding (platform-accessibility-audit-report,
 * matrix row plt-22). A finding on a portal page is portaliq's; a finding in
 * Nextcloud's own chrome (header, skip links, footer, the header menus) is
 * Nextcloud's; everything else on a decidiq page is decidiq's, including a
 * dialog decidiq mounts on the body.
 *
 * @spec openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-002-axe-core-scans-every-sampled-page-and-attributes-each-finding
 */

/**
 * Selectors of Nextcloud's own chrome around an app.
 */
export const NEXTCLOUD_CHROME =
	'#header, #skip-actions, #body-footer, footer, .header-menu, #unified-search, #notifications, #user-menu, #app-menu'

/**
 * The owner of one node.
 *
 * @param {{inNextcloudChrome: boolean}} place Where the node sits, read in the browser with NEXTCLOUD_CHROME.
 * @param {string} pageOwner The sample entry's owner.
 * @return {string} `decidiq`, `nextcloud` or `portaliq`.
 * @spec openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-002-axe-core-scans-every-sampled-page-and-attributes-each-finding
 */
export function ownerOf(place, pageOwner) {
	if (pageOwner && pageOwner !== 'decidiq') return pageOwner
	return place?.inNextcloudChrome ? 'nextcloud' : 'decidiq'
}

/**
 * Split a page's axe violations per owner. A violation whose nodes sit with
 * more than one owner is recorded once per owner, with that owner's nodes.
 *
 * @param {Array<object>} violations axe-core violations, each node carrying `owner`.
 * @param {string} page The sample entry id.
 * @return {Array<object>} Violations with `page` and `owner`.
 * @spec openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-002-axe-core-scans-every-sampled-page-and-attributes-each-finding
 */
export function attribute(violations, page) {
	const out = []
	for (const violation of violations || []) {
		const owners = new Map()
		for (const node of violation.nodes || []) {
			const owner = node.owner || 'decidiq'
			if (!owners.has(owner)) owners.set(owner, [])
			owners.get(owner).push(node)
		}
		for (const [owner, nodes] of owners) {
			out.push({ ...violation, nodes, page, owner })
		}
	}
	return out
}

/**
 * The report file the hydra axe gate reads. `violations` holds decidiq's own
 * findings, which the gate judges; `others` keeps every other owner's
 * findings for the audit report without failing decidiq on them.
 *
 * @param {Array<object>} attributed Violations from attribute().
 * @param {object} meta Run details (sample, theme, date).
 * @return {{violations: Array, others: Array}} The report.
 * @spec openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-002-axe-core-scans-every-sampled-page-and-attributes-each-finding
 */
export function reportOf(attributed, meta) {
	return {
		...meta,
		violations: attributed.filter((violation) => violation.owner === 'decidiq'),
		others: attributed.filter((violation) => violation.owner !== 'decidiq'),
	}
}

/**
 * The findings that fail decidiq's scan: serious or critical, owned by decidiq.
 *
 * @param {Array<object>} attributed Violations from attribute().
 * @return {Array<object>} The blocking violations.
 * @spec openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-002-axe-core-scans-every-sampled-page-and-attributes-each-finding
 */
export function blocking(attributed) {
	return attributed.filter(
		(violation) =>
			violation.owner === 'decidiq'
			&& ['serious', 'critical'].includes(violation.impact),
	)
}
