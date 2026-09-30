// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Rights per record type (platform-role-rights-per-record-type, plt-03):
 * the rules OpenRegister enforces, in words an administrator reads.
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
 */

/**
 * Who one action's rules let in, as one line.
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
 * @param {Array<{group: string, conditional: boolean}>} rules The action's rules
 * @param {{everyone: string, signedIn: string, nobody: string, conditional: string}} words Translated words; `conditional` holds {who}
 * @return {string} For example "Signed-in users, decidiq-administrators, Griffie"
 */
export function ruleSummary(rules, words) {
	if (!Array.isArray(rules) || rules.length === 0) {
		return words.nobody
	}
	const names = []
	for (const rule of rules) {
		let who = rule.group
		if (who === 'public') {
			who = words.everyone
		} else if (who === 'authenticated') {
			who = words.signedIn
		}
		if (rule.conditional) {
			who = words.conditional.replace('{who}', who)
		}
		if (!names.includes(who)) {
			names.push(who)
		}
	}
	return names.join(', ')
}

/**
 * The mapping to send: role => group ids, from the page's role rows.
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
 * @param {Array<{role: string, mapped: Array<string|{id: string}>}>} roles The role rows
 * @return {Object<string, string[]>} The mapping
 */
export function mappingOf(roles) {
	const mapping = {}
	for (const row of roles || []) {
		mapping[row.role] = (row.mapped || [])
			.map((group) => (typeof group === 'string' ? group : group?.id))
			.filter((id) => typeof id === 'string' && id.trim() !== '')
	}
	return mapping
}
