// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Validate an object the frontend writes against the schema the register
// serves: lib/Settings/decidesk_register.json merged with every register.d
// fragment, reduced to the keywords a JSON-schema validator reads.

import Ajv from 'ajv'
import addFormats from 'ajv-formats'
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../../')
const KEEP = ['type', 'properties', 'items', 'enum', 'format', 'required']

/**
 * Reduce a schema node to validator keywords; objects refuse unknown keys.
 *
 * @param {object} node A schema node
 * @return {object} The reduced node
 */
function clean(node) {
	if (!node || typeof node !== 'object') return node
	const out = {}
	for (const k of KEEP) {
		if (!(k in node)) continue
		if (k === 'properties') {
			out.properties = Object.fromEntries(
				Object.entries(node.properties).map(([p, v]) => [p, clean(v)]),
			)
		} else if (k === 'items') {
			out.items = clean(node.items)
		} else {
			out[k] = node[k]
		}
	}
	if (out.type === 'object' && out.properties) out.additionalProperties = false
	return out
}

/**
 * A validator for the merged schema with this slug.
 *
 * @param {string} slug The schema slug
 * @return {Function} An ajv validate function
 */
export function validatorFor(slug) {
	const files = [
		'lib/Settings/decidesk_register.json',
		...readdirSync(resolve(root, 'lib/Settings/register.d'))
			.filter((f) => f.endsWith('.json'))
			.sort()
			.map((f) => `lib/Settings/register.d/${f}`),
	]
	const props = {}
	let required = []
	for (const file of files) {
		const schemas =
			JSON.parse(readFileSync(resolve(root, file), 'utf8'))?.components
				?.schemas || {}
		for (const [name, schema] of Object.entries(schemas)) {
			if ((schema.slug || name) !== slug) continue
			for (const [p, v] of Object.entries(schema.properties || {})) {
				props[p] = { ...(props[p] || {}), ...v }
			}
			required = schema.required || required
		}
	}
	const ajv = addFormats(new Ajv({ allErrors: true, strict: false }))
	return ajv.compile(clean({ type: 'object', properties: props, required }))
}
