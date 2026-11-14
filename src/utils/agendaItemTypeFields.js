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
 * - setTypeFieldValue(values, { input, raw }) → a new typeFields object.
 * - missingRequiredTypeFields(inputs, values) → labels still left empty.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/questions-as-agenda-items/spec.md
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
	// A person with an account on this instance, picked by name and stored as
	// their user id (motions-technical-questions-to-officials).
	user: 'user',
}

/**
 * The inputs an agenda item of this type offers.
 *
 * @param {?object} type The AgendaItemType object.
 * @return {Array<{key: string, label: string, fieldType: string, input: string, required: boolean, options: string[]}>} The inputs.
 * @spec openspec/specs/questions-as-agenda-items/spec.md
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
 * @spec openspec/specs/questions-as-agenda-items/spec.md
 */
export function findItemType(types, ref) {
	const id = referenceOf(ref)
	if (!id || !Array.isArray(types)) return null
	return types.find((type) => isTypeReferenced(type, id)) || null
}

/**
 * The id a type reference holds. A reference picker may hand over its option
 * object instead of the id.
 *
 * @param {?(string|object)} ref The item's `type` value, or a picker option carrying it.
 * @return {?string} The id, uuid or slug.
 * @spec openspec/specs/questions-as-agenda-items/spec.md
 */
function referenceOf(ref) {
	return ref && typeof ref === 'object' ? ref.id || ref.uuid || ref.value : ref
}

/**
 * Whether a type is the one a reference names, by id, uuid or slug.
 *
 * @param {?object} type A configured AgendaItemType.
 * @param {string} id The reference.
 * @return {boolean} True when it matches.
 * @spec openspec/specs/questions-as-agenda-items/spec.md
 */
function isTypeReferenced(type, id) {
	return (
		type?.id === id
		|| type?.uuid === id
		|| type?.['@self']?.slug === id
		|| type?.slug === id
	)
}

/** Marks an answer that removes its key. */
const REMOVE = Symbol('remove')

/** Raw values that clear an answer. */
const EMPTY_RAW = new Set([null, undefined, ''])

/**
 * Reads the raw value of a non-empty control, by input kind. Any other kind
 * stores text.
 */
const ANSWER_READERS = new Map([
	['user', userAnswer],
	['number', numberAnswer],
])

/**
 * Write one answer into a copy of the item's typeFields.
 *
 * An emptied input removes the key, so a cleared answer reads as unanswered
 * rather than as an empty string.
 *
 * @param {?object} values The item's current typeFields.
 * @param {{input: {key: string, input: string}, raw: unknown}} answer The input being written and the value the control produced.
 * @return {object} The new typeFields object.
 * @spec openspec/specs/questions-as-agenda-items/spec.md
 */
export function setTypeFieldValue(values, answer) {
	const next = { ...(values || {}) }
	const value = answerValue(answer.input, answer.raw)
	if (value === REMOVE) delete next[answer.input.key]
	else next[answer.input.key] = value
	return next
}

/**
 * The value to store for one answer, or REMOVE.
 *
 * @param {{key: string, input: string}} input The input being written.
 * @param {unknown} raw The value the control produced.
 * @return {unknown} The value, or REMOVE.
 * @spec openspec/specs/questions-as-agenda-items/spec.md
 */
function answerValue(input, raw) {
	if (input.input === 'checkbox') return Boolean(raw)
	if (EMPTY_RAW.has(raw)) return REMOVE
	const read = ANSWER_READERS.get(input.input) ?? String
	return read(raw)
}

/**
 * A person picker's answer: the id of the picked option, or text.
 *
 * @param {unknown} raw The value the control produced.
 * @return {string|symbol} The id as text, or REMOVE for an option without one.
 * @spec openspec/specs/questions-as-agenda-items/spec.md
 */
function userAnswer(raw) {
	if (typeof raw !== 'object') return String(raw)
	const id = raw.id || raw.value || ''
	return id === '' ? REMOVE : String(id)
}

/**
 * A number field's answer.
 *
 * @param {unknown} raw The value the control produced.
 * @return {number|symbol} The number, or REMOVE when it is not one.
 * @spec openspec/specs/questions-as-agenda-items/spec.md
 */
function numberAnswer(raw) {
	const number = Number(raw)
	return Number.isNaN(number) ? REMOVE : number
}

/**
 * The labels of required inputs that are still empty.
 *
 * @param {Array<{key: string, label: string, required: boolean}>} inputs The inputs.
 * @param {?object} values The item's typeFields.
 * @return {string[]} Labels of the missing answers.
 * @spec openspec/specs/questions-as-agenda-items/spec.md
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
