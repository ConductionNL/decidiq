/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The query and the result filter behind ConsultationReactionsTab.
 *
 * A reaction links to its consultation through a `relations` array, which
 * OpenRegister keeps only as the flattened `@self.relations` map. The tab
 * used to scope with `_relations.public-consultation`. PHP rewrites the dot in
 * a query parameter name to an underscore, so OpenRegister received
 * `_relations_public-consultation`, did not know it, and answered every
 * pending reaction on the instance. The consultation page then offered to
 * moderate reactions that belong to other consultations.
 *
 * The scoped query now uses `_relations_contains` through relationFilterFor,
 * and the result is filtered again with `matching`, because a filter an
 * OpenRegister does not know is dropped in silence rather than refused.
 *
 * @spec openspec/specs/p3-citizen-participation/spec.md
 */

import { matching, relationFilterFor } from '../../utils/objectRelations.js'

/**
 * The collection query for the pending reactions of one consultation, or of
 * every consultation when no id is given.
 *
 * @spec openspec/specs/p3-citizen-participation/spec.md
 * @param {string|number} consultationId The consultation to scope to, or empty for the hub-wide queue
 * @return {object} The fetchCollection params
 */
export function pendingReactionsQuery(consultationId) {
	const query = { moderationStatus: 'pending', _limit: 200 }
	if (!consultationId) {
		return query
	}
	return { ...query, ...relationFilterFor(String(consultationId)) }
}

/**
 * The pending reactions in a fetchCollection result, kept to one consultation
 * when an id is given.
 *
 * @spec openspec/specs/p3-citizen-participation/spec.md
 * @param {Array<object>|{results: Array<object>}|null} result What fetchCollection returned
 * @param {string|number} consultationId The consultation to scope to, or empty for the hub-wide queue
 * @return {Array<object>} The pending reactions
 */
export function pendingReactionsFrom(result, consultationId) {
	let list = []
	if (Array.isArray(result)) {
		list = result
	} else if (result && Array.isArray(result.results)) {
		list = result.results
	}
	if (consultationId) {
		list = matching(list, String(consultationId))
	}
	return list.filter(
		(reaction) => (reaction.moderationStatus || 'pending') === 'pending',
	)
}
