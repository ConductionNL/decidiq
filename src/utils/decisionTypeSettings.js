/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The decision type vocabulary as the admin settings page edits it: one type
 * per line in a text field, a list on the wire.
 *
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-009-the-decisiontype-vocabulary-is-configuration-with-one-authority
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The lines of the text field as a list, trimmed, without blanks or repeats.
 *
 * The server checks each entry again; this only shapes what is sent.
 *
 * @param {string} text The text field.
 * @return {string[]} The types.
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-009-the-decisiontype-vocabulary-is-configuration-with-one-authority
 */
export function typesFromText(text) {
	const seen = new Set()
	for (const line of String(text || '').split(/\r?\n/)) {
		const type = line.trim()
		if (type !== '') seen.add(type)
	}
	return [...seen]
}

/**
 * The list as the text field shows it.
 *
 * @param {string[]} types The types.
 * @return {string} One type per line.
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-009-the-decisiontype-vocabulary-is-configuration-with-one-authority
 */
export function textFromTypes(types) {
	return (Array.isArray(types) ? types : []).join('\n')
}

/**
 * Read the vocabulary in force.
 *
 * @return {Promise<string[]>} The types.
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-009-the-decisiontype-vocabulary-is-configuration-with-one-authority
 */
export async function loadDecisionTypes() {
	const { data } = await axios.get(
		generateUrl('/apps/decidiq/api/v1/decision-types'),
	)
	return Array.isArray(data?.types) ? data.types : []
}

/**
 * Save the vocabulary. A refused list rejects with the server's reason.
 *
 * @param {string[]} types The types.
 * @return {Promise<string[]>} The stored types.
 * @spec openspec/changes/decision-types-as-configuration/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-009-the-decisiontype-vocabulary-is-configuration-with-one-authority
 */
export async function saveDecisionTypes(types) {
	const { data } = await axios.put(
		generateUrl('/apps/decidiq/api/settings/decision-types'),
		{ types },
	)
	return Array.isArray(data?.types) ? data.types : types
}
