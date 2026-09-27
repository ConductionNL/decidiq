/**
 * Pure helpers for the fields an agenda item type declares (#1393).
 *
 * An AgendaItemType carries `fields`: a list of `{ key, label, fieldType,
 * required, enumValues }` (lib/Settings/register.d/70-configurable-types.json).
 * An AgendaItem keeps the answers in `typeFields[key]`. These helpers turn a
 * type into the inputs to render and write one answer back, so the agenda
 * item form and the detail page share one reading of the declaration.
 *
 * - typeFieldInputs(type) → the inputs, in declared order, with the input
 *   kind chosen by fieldType and the caption taken from label.
 * - findItemType(types, ref) → the type an item refers to, by uuid or by the
 *   slug a seeded item stores.
 * - setTypeFieldValue(values, input, raw) → a new typeFields object.
 * - missingRequiredTypeFields(inputs, values) → labels still left empty.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md
 */

/**
 * fieldType → input kind. `choice` is accepted as a synonym of `enum`; any
 * other or missing type, including `reference`, is a single line of text.
 */
const INPUT_BY_FIELD_TYPE = {
	string: 'text',
	text: 'textarea',
	number: 'number',
	boolean: 'checkbox',
	date: 'date',
	enum: 'select',
	choice: 'select',
	reference: 'text',
}

/**
 * The inputs an agenda item of this type offers.
 *
 * @param {?object} type The AgendaItemType object.
 * @return {Array<{key: string, label: string, fieldType: string, input: string, required: boolean, options: string[]}>} The inputs.
 */
export function typeFieldInputs(type) {
	const fields = Array.isArray(type?.fields) ? type.fields : []
	return fields
		.filter((field) => typeof field?.key === 'string' && field.key !== '')
		.map((field) => ({
			key: field.key,
			label: field.label || field.key,
			fieldType: field.fieldType || 'string',
			input: INPUT_BY_FIELD_TYPE[field.fieldType] || 'text',
			required: field.required === true,
			options: Array.isArray(field.enumValues) ? [...field.enumValues] : [],
		}))
}

/**
 * The type an agenda item refers to.
 *
 * A row created through the UI holds the type's uuid; a seeded row holds the
 * slug exactly as the profile wrote it, so both are matched.
 *
 * @param {?Array<object>} types The configured AgendaItemType objects.
 * @param {?(string|object)} ref The item's `type` value, or a picker option carrying it.
 * @return {?object} The type, or null.
 */
export function findItemType(types, ref) {
	// A reference picker may hand over its option object instead of the id.
	if (ref && typeof ref === 'object') ref = ref.id || ref.uuid || ref.value
	if (!ref || !Array.isArray(types)) return null
	return (
		types.find(
			(type) =>
				type?.id === ref
				|| type?.uuid === ref
				|| type?.['@self']?.slug === ref
				|| type?.slug === ref,
		) || null
	)
}

/**
 * Write one answer into a copy of the item's typeFields.
 *
 * An emptied input removes the key, so a cleared answer reads as unanswered
 * rather than as an empty string.
 *
 * @param {?object} values The item's current typeFields.
 * @param {{key: string, input: string}} input The input being written.
 * @param {unknown} raw The value the control produced.
 * @return {object} The new typeFields object.
 */
export function setTypeFieldValue(values, input, raw) {
	const next = { ...(values || {}) }
	if (input.input === 'checkbox') {
		next[input.key] = Boolean(raw)
		return next
	}
	if (raw === null || raw === undefined || raw === '') {
		delete next[input.key]
		return next
	}
	if (input.input === 'number') {
		const number = Number(raw)
		if (Number.isNaN(number)) delete next[input.key]
		else next[input.key] = number
		return next
	}
	next[input.key] = String(raw)
	return next
}

/**
 * The labels of required inputs that are still empty.
 *
 * @param {Array<{key: string, label: string, required: boolean}>} inputs The inputs.
 * @param {?object} values The item's typeFields.
 * @return {string[]} Labels of the missing answers.
 */
export function missingRequiredTypeFields(inputs, values) {
	return inputs
		.filter((input) => input.required)
		.filter((input) => {
			const value = (values || {})[input.key]
			return value === undefined || value === null || value === ''
		})
		.map((input) => input.label)
}
