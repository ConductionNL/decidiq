// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Theme pages (publication-theme-pages, matrix row pub-20). Pure helpers for
// the staff timeline and for adding an agenda item or decision to a theme, so
// the components stay thin and vitest can cover the rules. The theme and its
// links live on the OpenRegister object API (ADR-022); only the timeline,
// which reads five schemas and their publications, comes from decidiq.

import { generateUrl } from '@nextcloud/router'

const OBJECTS = '/apps/openregister/api/objects/decidiq/governance-theme'

/** The link arrays a theme carries, by the kind of object they hold. */
export const LINK_PROPERTIES = {
	meeting: 'meetings',
	agendaItem: 'agendaItems',
	decision: 'decisions',
	commitment: 'commitments',
	plannedAgendaItem: 'plannedAgendaItems',
}

/** The kinds a timeline entry can have, with their English label. */
export const KIND_LABELS = {
	meeting: 'Meeting',
	agendaItem: 'Agenda item',
	decision: 'Decision',
	commitment: 'Commitment',
	plannedAgendaItem: 'Long-term agenda item',
}

/**
 * The URL of the staff timeline of one theme.
 *
 * @param {string} themeId The theme.
 * @return {string} The URL.
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-002-a-theme-shows-a-timeline-of-what-belongs-to-it
 */
export function timelineUrl(themeId) {
	return generateUrl(`/apps/decidiq/api/themes/${encodeURIComponent(themeId)}/timeline`)
}

/**
 * The OpenRegister URL of the themes to choose from, or of one theme.
 *
 * @param {string} [themeId] The theme; omitted for the list.
 * @return {string} The URL.
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-001-the-griffie-gathers-a-long-running-topic-in-a-theme
 */
export function themeUrl(themeId = '') {
	if (themeId) {
		return generateUrl(`${OBJECTS}/${encodeURIComponent(themeId)}`)
	}
	return generateUrl(`${OBJECTS}?_limit=200`)
}

/**
 * The ids in a link array, whether it holds ids or resolved objects.
 *
 * @param {Array|undefined} value The link array.
 * @return {string[]} The ids.
 */
export function idsOf(value) {
	if (!Array.isArray(value)) return []
	return value
		.map((item) => (item && typeof item === 'object' ? (item.id || item.uuid || '') : item))
		.filter((id) => typeof id === 'string' && id !== '')
}

/**
 * The theme's own id.
 *
 * @param {object} theme The theme.
 * @return {string} The id, '' when it has none.
 */
export function themeIdOf(theme) {
	return String(theme?.id || theme?.uuid || theme?.['@self']?.id || '')
}

/**
 * Whether the object is already linked to the theme.
 *
 * @param {object} theme The theme.
 * @param {string} kind The object's kind (a key of LINK_PROPERTIES).
 * @param {string} objectId The object.
 * @return {boolean} Whether it is.
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-001-the-griffie-gathers-a-long-running-topic-in-a-theme
 */
export function isLinked(theme, kind, objectId) {
	const property = LINK_PROPERTIES[kind]
	if (!property || !objectId) return false
	return idsOf(theme?.[property]).includes(objectId)
}

/**
 * The themes the object belongs to, out of a list.
 *
 * @param {object[]} themes The themes.
 * @param {string} kind The object's kind.
 * @param {string} objectId The object.
 * @return {object[]} The themes holding it.
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-001-the-griffie-gathers-a-long-running-topic-in-a-theme
 */
export function themesHolding(themes, kind, objectId) {
	return (themes || []).filter((theme) => isLinked(theme, kind, objectId))
}

/**
 * The theme to store with the object added to its link array: every other
 * field kept as it is (the object API's PUT replaces the whole object), the
 * read-only `@self` dropped, and the id not added twice.
 *
 * @param {object} theme The theme as read.
 * @param {string} kind The object's kind (a key of LINK_PROPERTIES).
 * @param {string} objectId The object to add.
 * @return {object} The theme to PUT.
 * @throws {Error} When the kind is not one a theme links.
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-001-the-griffie-gathers-a-long-running-topic-in-a-theme
 */
export function withLink(theme, kind, objectId) {
	const property = LINK_PROPERTIES[kind]
	if (!property) {
		throw new Error(`A theme does not link ${kind}`)
	}
	const body = { ...theme }
	delete body['@self']
	const ids = idsOf(theme?.[property])
	body[property] = ids.includes(objectId) ? ids : [...ids, objectId]
	return body
}

/**
 * The timeline entries as rows for display: the date as given (a year, a
 * quarter or a day), the kind label, and whether staff should see it marked
 * as not published.
 *
 * @param {object[]} entries The entries from the timeline endpoint, oldest first.
 * @return {object[]} The rows, in the same order.
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md#requirement-req-thp-002-a-theme-shows-a-timeline-of-what-belongs-to-it
 */
export function timelineRows(entries) {
	return (entries || []).map((entry) => ({
		key: `${entry.kind}:${entry.objectId}`,
		date: entry.date || '',
		kind: entry.kind,
		kindLabel: KIND_LABELS[entry.kind] || entry.kind,
		title: entry.title || '',
		objectId: entry.objectId,
		published: entry.published === true,
	}))
}
