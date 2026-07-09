// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Shared helpers for the "Besluitvorming" (decisions) integration leaf.
//
// The leaf surfaces a host object's decidesk decisions — proposals,
// advice and final decisions — on ANY consuming object's detail page or
// sidebar (ADR-022 / ADR-019). It is deliberately generic: it reads the
// host object identity from the registry-supplied integration context
// ({ register, schema, objectId }) and never hard-codes procest.
//
// Case-linking mechanism: decidesk's Decision schema already carries the
// back-reference fields raised by the contract-decision hub
// (decidesk-contract-decision-hub, REQ-DCDH-001): `subjectRegister`,
// `subjectSchema`, `subjectId`, `subjectLabel`, `sourceApp` and
// `externalReference`. A decision linked to a host object stores that
// object's UUID in `subjectId`; this leaf lists decisions filtered on
// `subjectId == objectId`. No new schema field is needed.

import { useObjectStore } from '../store/store.js'

/**
 * The decidesk logical object type backing the leaf. Registered against
 * the shared object store the first time the leaf renders (the store
 * may not have been booted by decidesk's own main.js when the leaf is
 * mounted inside a foreign app's page, e.g. a procest case detail).
 *
 * @type {string}
 */
export const DECISION_TYPE = 'decision'

/**
 * decidesk `decisionType` discriminator values grouped into the three
 * presentation buckets the leaf renders (Voorstellen / Adviezen /
 * Besluiten). `meeting-outcome` and anything unmatched fall through to
 * "Besluiten" (the catch-all final-decision bucket).
 *
 * @type {{ proposals: string[], advice: string[] }}
 */
export const KIND_GROUPS = {
	proposals: ['motion', 'amendment', 'policy', 'management-point'],
	advice: ['report-adoption', 'appointment'],
}

/**
 * Lifecycle states that read as "still a proposal" (not yet a binding
 * decision) — used to label a decision as a draft proposal in the UI.
 *
 * @type {string[]}
 */
export const PROPOSAL_LIFECYCLE = ['draft', 'proposed', 'deliberating', 'voting']

/**
 * Ensure the `decision` type is registered on decidesk's shared object
 * store, then return the store instance. Idempotent — safe to call on
 * every render. The decidesk register/schema slugs default to
 * `decidesk` / `decision`; a future settings-driven override can be
 * threaded here if a deployment renames them.
 *
 * @return {object} The booted decidesk object store.
 */
export function ensureDecisionStore() {
	const store = useObjectStore()
	// registerObjectType overwrites idempotently with the same payload.
	store.registerObjectType(DECISION_TYPE, 'decision', 'decidesk')
	return store
}

/**
 * Classify a decision into one of the three presentation buckets.
 *
 * @param {object} decision A decidesk Decision object.
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
 * @param {object} decision A decidesk Decision object.
 *
 * @return {boolean} True when lifecycle reads as a draft proposal.
 */
export function isProposal(decision) {
	const lifecycle = String(decision?.lifecycle ?? decision?.data?.lifecycle ?? 'draft')
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
	return String(obj?.id ?? obj?.uuid ?? obj?.['@self']?.id ?? obj?.['@self']?.uuid ?? '')
}
