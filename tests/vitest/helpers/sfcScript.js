/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Load the `<script>` block of a single-file component as a plain options
 * object, with every import answered from a table of test doubles. The unit
 * environment has no SFC transform, so this is how a component's methods run
 * under test. An import with no double throws, so nothing loads for real by
 * accident.
 *
 * @spec exclude test helper, no product behaviour
 */
import * as fs from 'fs'

/**
 * Turn one import clause into a destructuring of its double.
 *
 * @param {string} clause The text between `import` and `from`.
 * @param {string} spec The module specifier.
 *
 * @return {string} The replacement statement.
 */
function bindImport(clause, spec) {
	const source = `__module(${JSON.stringify(spec)})`
	const named = /\{([\s\S]*)\}/.exec(clause)
	const defaultName = clause
		.replace(/\{[\s\S]*\}/, '')
		.replace(/,/g, '')
		.trim()
	const lines = []
	if (defaultName !== '') {
		lines.push(`const ${defaultName} = ${source}.default;`)
	}
	if (named !== null) {
		const names = named[1]
			.split(',')
			.map((n) => n.trim())
			.filter(Boolean)
		const pattern = names.map((n) => n.replace(/\s+as\s+/, ': ')).join(', ')
		lines.push(`const { ${pattern} } = ${source};`)
	}
	return lines.join(' ') + '\n'
}

/**
 * Evaluate a component's script block.
 *
 * @param {string} file Absolute path of the `.vue` file.
 * @param {Object<string, object>} modules Module specifier to its double.
 *
 * @return {object} The component options object.
 */
export function loadSfcScript(file, modules) {
	const sfc = fs.readFileSync(file, 'utf8')
	const script = /<script>([\s\S]*?)<\/script>/.exec(sfc)[1]
	const body = script
		.replace(
			/^import\s+([\s\S]*?)\s+from\s+'([^']+)'\s*\n/gm,
			(_all, clause, spec) => bindImport(clause, spec),
		)
		.replace(/^export default\s+/m, 'return ')
	const lookup = (spec) => {
		if (Object.hasOwn(modules, spec) === false) {
			throw new Error(`no test double for import "${spec}"`)
		}
		return modules[spec]
	}
	return new Function('__module', body)(lookup)
}

/**
 * Build a component instance stand-in: props, data, a literal `t()`, and every
 * method bound to it.
 *
 * @param {object} component The options object.
 * @param {object} props The prop values.
 *
 * @return {object} The instance.
 */
export function instanceOf(component, props) {
	const vm = { ...props, ...component.data(), t: (_app, text) => text }
	for (const [name, fn] of Object.entries(component.methods || {})) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

/**
 * A recording `ensureRelationType()` double. Each store call is logged by
 * parameter name and answered by `answer(call)`, which may throw.
 *
 * @param {(call: object) => *} answer Returns the payload for one call.
 *
 * @return {{ensureRelationType: Function, calls: Array<object>}} The double and its log.
 */
export function recordingStores(answer) {
	const calls = []
	const store = (type) => ({
		async fetchCollection(schema, params) {
			const call = { fn: 'fetchCollection', type, schema, params }
			calls.push(call)
			return answer(call)
		},
		async fetchObject(schema, id) {
			const call = { fn: 'fetchObject', type, schema, id }
			calls.push(call)
			return answer(call)
		},
	})
	return { ensureRelationType: store, calls }
}
