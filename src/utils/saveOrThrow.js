// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Save an object through the nextcloud-vue object store and throw when the
 * server refuses it.
 *
 * `saveObject` does not throw on a refused save: it returns null and records
 * the refusal on `errors[type]`. A caller that only catches would report
 * success and show nothing, so this turns the refusal into an Error carrying
 * the server's message.
 *
 * @param {object} store The object store.
 * @param {string} type The registered type slug.
 * @param {object} data The object to save.
 * @return {Promise<object>} The saved object.
 * @spec openspec/specs/meeting-management/spec.md#requirement-req-pps-002-the-evenings-page-shows-its-sessions-side-by-side
 */
export async function saveOrThrow(store, type, data) {
	const saved = await store.saveObject(type, data)
	if (saved === null || saved === undefined) {
		const failure = store.getError?.(type) ?? store.errors?.[type] ?? null
		throw new Error(failure?.message || '')
	}
	return saved
}
