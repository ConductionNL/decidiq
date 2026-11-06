/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Voting groups as the admin page lists them (board DcBeheer, Stemgroepen).
 */

/**
 * One row per group: its name and how many members it has.
 *
 * @param {Array<object>} groups The voting-group objects.
 * @return {Array<{id: string, name: string, memberCount: number}>} The rows.
 */
export function votingGroupRows(groups) {
	return (Array.isArray(groups) ? groups : []).map((group) => ({
		id: String(group?.id ?? group?.['@self']?.id ?? ''),
		name: String(group?.name ?? ''),
		memberCount: Array.isArray(group?.members) ? group.members.length : 0,
	}))
}
