// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Shared helpers for the "Besluitvorming" (decisions) integration leaf.
//
// The leaf surfaces a host object's decidiq decisions — proposals,
// advice and final decisions — on ANY consuming object's detail page or
// sidebar (ADR-022 / ADR-019). It is deliberately generic: it reads the
// host object identity from the registry-supplied integration context
// ({ register, schema, objectId }) and never hard-codes procest.
//
// Case-linking mechanism: decidiq's Decision schema already carries the
// back-reference fields raised by the contract-decision hub
// (decidesk-contract-decision-hub, REQ-DCDH-001): `subjectRegister`,
// `subjectSchema`, `subjectId`, `subjectLabel`, `sourceApp` and
// `externalReference`. A decision linked to a host object stores that
// object's UUID in `subjectId`; this leaf lists decisions filtered on
// `subjectId == objectId`. No new schema field is needed.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The decidiq logical object type backing the leaf. Registered against
 * the shared object store the first time the leaf renders (the store
 * may not have been booted by decidiq's own main.js when the leaf is
 * mounted inside a foreign app's page, e.g. a procest case detail).
 *
 * @type {string}
 */
export const DECISION_TYPE = 'decision'

/**
 * decidiq `decisionType` discriminator values grouped into the three
 * presentation buckets the leaf renders (Voorstellen / Adviezen /
 * Besluiten). `meeting-outcome` and anything unmatched fall through to
 * "Besluiten" (the catch-all final-decision bucket).
 *
 * @type {{ proposals: string[], advice: string[] }}
 */
export const KIND_GROUPS = {
	proposals: ['motion', 'amendment', 'policy', 'management-point'],
	advice: ['report-adoption', 'appointment', 'advice'],
}

/**
 * Lifecycle states that read as "still a proposal" (not yet a binding
 * decision) — used to label a decision as a draft proposal in the UI.
 *
 * @type {string[]}
 */
export const PROPOSAL_LIFECYCLE = ['draft', 'proposed', 'deliberating', 'voting']

/**
 * OpenRegister objects endpoint for decidiq `decision` objects.
 *
 * @return {string} The resolved `/apps/openregister/api/objects/decidiq/decision` URL.
 */
function decisionsUrl() {
	return generateUrl('/apps/openregister/api/objects/{register}/{schema}', {
		register: 'decidiq',
		schema: 'decision',
	})
}

/**
 * List decidiq decisions linked to a host object, via the shared
 * OpenRegister objects API — NOT a Pinia store.
 *
 * The leaf is rendered inline on ANY app's detail page (a pipelinq lead, a
 * procest case, …). It therefore must not depend on decidiq's own Pinia
 * object store: that store lives in decidiq's bundle and its `getActivePinia`
 * is not the host app's active Pinia, so `useObjectStore()` threw
 * `reading '_s' of undefined` when hosted in a foreign app (ADR-019: an
 * integration leaf must be host-agnostic). A direct API call needs no store.
 *
 * @param {string} subjectId The host object's UUID (`subjectId` filter).
 * @param {number} [limit]   Max rows (default 100).
 *
 * @return {Promise<object[]>} The decision objects, or `[]`.
 */
export async function listHostDecisions(subjectId, limit = 100) {
	if (!subjectId) return []
	const res = await axios.get(decisionsUrl(), {
		params: { subjectId, _limit: limit },
	})
	const data = res && res.data
	if (Array.isArray(data)) return data
	if (data && Array.isArray(data.results)) return data.results
	return []
}

/**
 * Create a decidiq decision pre-linked to a host object, via the shared
 * OpenRegister objects API (see {@link listHostDecisions} for why not a store).
 *
 * @param {object} payload The decision object to persist (already carrying the
 *                         `subject*` back-reference fields).
 *
 * @return {Promise<object|null>} The created object, or null.
 */
export async function createHostDecision(payload) {
	const res = await axios.post(decisionsUrl(), payload)
	return (res && res.data) || null
}

/**
 * Classify a decision into one of the three presentation buckets.
 *
 * @param {object} decision A decidiq Decision object.
 *
 * @return {'proposals'|'advice'|'decisions'} The bucket key.
 */
export function decisionBucket(decision) {
	const type = String(decision?.decisionType ?? decision?.data?.decisionType ?? '')
	if (KIND_GROUPS.proposals.includes(type)) {
		return 'proposals'
	}
	if (KIND_GROUPS.advice.includes(type)) {
		return 'advice'
	}
	return 'decisions'
}

/**
 * Whether the decision is still in a proposal lifecycle state.
 *
 * @param {object} decision A decidiq Decision object.
 *
 * @return {boolean} True when lifecycle reads as a draft proposal.
 */
export function isProposal(decision) {
	const lifecycle = String(
		decision?.lifecycle ?? decision?.data?.lifecycle ?? 'draft',
	)
	return PROPOSAL_LIFECYCLE.includes(lifecycle)
}

/**
 * Resolve a stable object id from an OR object (id or @self.uuid).
 *
 * @param {object} obj An OR object.
 *
 * @return {string} The id, or '' when absent.
 */
export function objId(obj) {
	return String(
		obj?.id ?? obj?.uuid ?? obj?.['@self']?.id ?? obj?.['@self']?.uuid ?? '',
	)
}
