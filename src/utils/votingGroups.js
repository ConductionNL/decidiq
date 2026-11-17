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

/**
 * The id a reference names: a uuid string, or an object carrying an id.
 *
 * @param {string|object|null|undefined} value The reference.
 * @return {string} The id, or '' when there is none.
 */
function refOf(value) {
	if (value && typeof value === 'object') {
		return String(value.id ?? value.uuid ?? value?.['@self']?.id ?? '')
	}
	return value ? String(value) : ''
}

/**
 * The body a meeting belongs to, as the meeting object carries it.
 *
 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-002-chair-can-select-a-voting-group-preset-when-opening-a-votinground
 * @param {object|null} meeting The meeting object.
 * @return {string} The body id, or '' when the meeting names none.
 */
export function meetingBodyId(meeting) {
	return refOf(meeting?.governanceBody ?? meeting?.['@self']?.relations?.governanceBody)
}

/**
 * The voting groups the chair can pick when a round opens: the active groups
 * of the meeting's body, by name, each with its member count.
 *
 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-002-chair-can-select-a-voting-group-preset-when-opening-a-votinground
 * @param {Array<object>} groups The voting-group objects.
 * @param {string} bodyId The meeting's body.
 * @return {Array<{id: string, name: string, memberCount: number}>} The choices.
 */
export function votingGroupChoices(groups, bodyId) {
	if (!bodyId) {
		return []
	}
	return (Array.isArray(groups) ? groups : [])
		.filter((group) => group?.active !== false && refOf(group?.governanceBody) === bodyId)
		.map((group) => votingGroupRows([group])[0])
		.filter((row) => row.id !== '')
		.sort((a, b) => a.name.localeCompare(b.name))
}

/**
 * The names of the voting group's members a freshly opened round left out (REQ-VGP-005).
 *
 * @spec openspec/changes/voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-005-the-chair-sees-who-a-voting-group-left-out
 * @param {object|null} round The server's answer to opening the round.
 * @return {Array<string>} The names.
 */
export function excludedMemberNames(round) {
	const members = Array.isArray(round?.excludedGroupMembers) ? round.excludedGroupMembers : []
	return members.map((member) => String(member?.name || member?.id || '')).filter((name) => name !== '')
}
