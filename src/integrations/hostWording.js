// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The words a host page gives a leaf, over the leaf's own. The host's words
 * are already in the reader's language: the leaf cannot translate them.
 *
 * @param {object} given Prop name to the host's text (may be blank).
 * @param {object} own Prop name to the leaf's own translated text.
 * @return {object} Prop name to the text to show.
 * @spec openspec/changes/decisions-leaf-speaks-the-hosts-words/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-009-the-decisions-leaf-speaks-the-hosts-words
 */
export function hostWording(given, own) {
	const out = {}
	for (const [key, fallback] of Object.entries(own)) {
		const text = given ? given[key] : undefined
		out[key] = typeof text === 'string' && text.trim() !== '' ? text : fallback
	}
	return out
}
