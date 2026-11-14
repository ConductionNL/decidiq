// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The calendar subscription in the member's user settings
 * (platform-microsoft-365-calendar-and-teams, matrix rows plt-12 and pla-04).
 * A member mints an OpenRegister feed token over the `meeting` schema,
 * pastes its address into Outlook or a phone, and revokes it. The calls go
 * to OpenRegister's API (`/api/calendar-feeds`); decidiq adds no route.
 * Pure helpers, so the section stays thin and vitest covers the rules.
 *
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-003-a-member-subscribes-from-the-personal-settings
 */
import { generateUrl } from '@nextcloud/router'

/** The schema the feed covers. */
export const MEETING_SCHEMA = 'meeting'

/** The label decidiq gives the tokens it mints, so other feeds of the member are left alone. */
export const FEED_LABEL = 'decidiq meetings'

/**
 * The OpenRegister address that lists and mints the member's feed tokens, or revokes one.
 *
 * @param {number|string} [id] The token row id, to revoke it.
 * @return {string} The URL.
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-003-a-member-subscribes-from-the-personal-settings
 */
export function feedsUrl(id = '') {
	const tail = id === '' ? '' : `/${encodeURIComponent(String(id))}`
	return generateUrl(`/apps/openregister/api/calendar-feeds${tail}`)
}

/**
 * The OpenRegister address of the register with its schemas, to find the meeting schema's id.
 *
 * @param {string} register The register slug.
 * @return {string} The URL.
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-003-a-member-subscribes-from-the-personal-settings
 */
export function registerUrl(register) {
	return generateUrl(
		`/apps/openregister/api/registers/${encodeURIComponent(register)}?_extend[]=schemas`,
	)
}

/**
 * The id of the meeting schema in a register answered with its schemas.
 *
 * @param {object|null} register The register, its `schemas` expanded to objects.
 * @param {string} [slug] The schema slug.
 * @return {string} The schema id, or '' when the register has no such schema.
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-003-a-member-subscribes-from-the-personal-settings
 */
export function schemaIdOf(register, slug = MEETING_SCHEMA) {
	const schemas = Array.isArray(register?.schemas) ? register.schemas : []
	const schema = schemas.find(
		(candidate) =>
			candidate
			&& typeof candidate === 'object'
			&& String(candidate.slug || '').toLowerCase() === slug,
	)
	return schema && schema.id !== undefined && schema.id !== null
		? String(schema.id)
		: ''
}

/**
 * The body that mints a feed token over the meeting schema.
 *
 * @param {string} schemaId The meeting schema id.
 * @return {object} The body for POST /api/calendar-feeds.
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-003-a-member-subscribes-from-the-personal-settings
 */
export function mintBody(schemaId) {
	return { scopeType: 'schema', scopeId: String(schemaId), label: FEED_LABEL }
}

/**
 * The member's live subscriptions over the meeting schema, newest first.
 *
 * @param {Array<object>} tokens The tokens OpenRegister lists for the member.
 * @param {string} schemaId The meeting schema id.
 * @return {Array<object>} The subscriptions.
 * @spec openspec/changes/platform-microsoft-365-calendar-and-teams/specs/microsoft-365-work/spec.md#requirement-req-m365-003-a-member-subscribes-from-the-personal-settings
 */
export function meetingFeeds(tokens, schemaId) {
	if (!Array.isArray(tokens) || schemaId === '') {
		return []
	}
	return tokens
		.filter(
			(token) =>
				token
				&& token.scopeType === 'schema'
				&& String(token.scopeId) === String(schemaId)
				&& token.live !== false
				&& !token.revokedAt,
		)
		.sort((a, b) => String(b.createdAt || '').localeCompare(String(a.createdAt || '')))
}
