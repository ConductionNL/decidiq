/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Scoping a fetched OpenRegister collection to the objects that genuinely
 * reference a given id — the browser-side counterpart of
 * lib/Service/ObjectRelationFilter.php.
 *
 * ── HOW DECIDIQ WRITES A LINK ───────────────────────────────────────────────
 * As a structured relations array on the object payload
 * (VotingRoundPreflight::buildRoundPayload, VoteBallotFactory, and the e2e
 * fixtures that mimic them):
 *
 *     relations: [{ register: 'decidiq', schema: 'motion', id: '<uuid>' }]
 *
 * `relations` is not a declared schema property, so OpenRegister does not keep
 * it on the object body — it keeps the flattening that
 * SaveObject::scanForRelations() produces, in the `_relations` JSONB keyed by
 * the PROPERTY PATH it walked. Measured on a real response:
 *
 *     "@self": { "relations": { "votingMethod": "for-against-abstain",
 *                               "voteThreshold": "…",
 *                               "relations.0.id": "<uuid>" } }
 *
 * ── WHY THE OBVIOUS FILTER KEYS DO NOTHING ──────────────────────────────────
 *  - A BARE key (`relations.motion`) is not `@self`, not `_`-prefixed and not a
 *    reserved context param, so MagicSearchHandler classifies it as an
 *    OBJECT-FIELD filter on a property no schema declares.
 *  - `_relations.<field>`, the key the PHP side uses, CANNOT be sent from a
 *    browser. PHP rewrites `.` to `_` in query-parameter NAMES, so
 *    `_relations.relations` arrives as `_relations_relations`;
 *    `extractRelationFieldFilters()` tests `str_starts_with($key,
 *    '_relations.')` and never sees it, and the `_` prefix keeps it out of the
 *    object-field filters too. The filter is dropped in silence. That dialect
 *    is correct only for in-process `findAll(['filters' => …])` calls.
 *  - `_relations_contains` has no dot, is on MagicSearchHandler's reserved-param
 *    list, and matches any object whose `_relations` holds the value — which is
 *    exactly the question being asked. It is the only one of the three that
 *    survives the trip.
 *
 * A server-side filter an older OpenRegister does not implement is IGNORED, not
 * refused, so the collection can still come back unscoped. `matching()` is
 * therefore the load-bearing half and the filter is an optimisation.
 *
 * @spec openspec/specs/voting-system/spec.md
 */

/**
 * The dot-free `_relations` filter key that survives PHP's parameter-name
 * mangling. See the module docblock for why `_relations.<field>` cannot.
 *
 * @type {string}
 */
export const RELATION_CONTAINS_FILTER = '_relations_contains'

/**
 * Build the OpenRegister collection filter that narrows to objects referencing
 * `targetId`.
 *
 * Returns an empty fragment for a falsy id: an empty value would be stripped by
 * `buildQueryString` anyway, and emitting a key that silently means "no filter"
 * is how the caller comes to believe it asked a question it never asked.
 *
 * @spec openspec/specs/voting-system/spec.md
 * @param {string} targetId The related object UUID that must be referenced
 * @return {Object<string,string>} Filter fragment to merge into fetchCollection params
 */
export function relationFilterFor(targetId) {
	if (!targetId) return {}
	return { [RELATION_CONTAINS_FILTER]: targetId }
}

/**
 * Search a relations structure for an exact id, whatever shape it arrived in.
 *
 * The same link is served in more than one projection depending on endpoint and
 * OpenRegister version — the structured array echoed straight back on a write
 * (`[{ register, schema, id }]`), and the flattened `@self.relations` map keyed
 * by property path (`{ 'relations.0.id': '<uuid>' }`) that a collection read
 * returns. Enumerating the shapes is how this helper would go quietly wrong on
 * the next one, so it walks the structure and matches on the id itself: a UUID
 * occurring anywhere inside an object's relations IS a reference to that
 * object, and nothing else in that structure can collide with one.
 *
 * @spec openspec/specs/voting-system/spec.md
 * @param {object|Array|string|null} node A relations structure or any node within it
 * @param {string} targetId The related object UUID to look for
 * @param {number} depth Recursion guard against deep or cyclic payloads
 * @return {boolean} True when targetId occurs in the structure
 */
function containsId(node, targetId, depth = 0) {
	if (depth > 6 || node === null || node === undefined) return false
	if (typeof node === 'string') return node === targetId
	if (Array.isArray(node)) {
		return node.some((value) => containsId(value, targetId, depth + 1))
	}
	if (typeof node === 'object') {
		return Object.values(node).some((value) =>
			containsId(value, targetId, depth + 1),
		)
	}
	return false
}

/**
 * Determine whether an object genuinely references `targetId`.
 *
 * Only the relation structures are searched — never the whole object — so an
 * unrelated property holding the same UUID cannot make a row match.
 *
 * @spec openspec/specs/voting-system/spec.md
 * @param {object} entity The serialised object to test
 * @param {string} targetId The related object UUID that must be referenced
 * @return {boolean} True when the object references targetId
 */
export function references(entity, targetId) {
	if (!targetId) return false
	return (
		containsId(entity?.relations, targetId)
		|| containsId(entity?.['@self']?.relations, targetId)
	)
}

/**
 * Keep only the objects that genuinely reference `targetId`.
 *
 * A falsy `targetId` yields an EMPTY list rather than the unfiltered page. The
 * caller has no object to scope to, and answering an unanswerable question with
 * every row on the instance is the failure this module exists to end.
 *
 * @spec openspec/specs/voting-system/spec.md
 * @param {Array<object>|null} entities The collection returned by fetchCollection
 * @param {string} targetId The related object UUID that must be referenced
 * @return {Array<object>} The entities that reference targetId
 */
export function matching(entities, targetId) {
	if (!targetId) return []
	return (entities || []).filter((entity) => references(entity, targetId))
}
