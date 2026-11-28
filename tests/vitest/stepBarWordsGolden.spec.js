/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the word under each step-bar tile: the amendment, decision
 * and meeting bars each say Done, Now or Next, whichever file the method
 * lives in.
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { loadSfcScript } from './helpers/sfcScript.js'

const WIDGETS = path.resolve(__dirname, '../../src/components/widgets')

/**
 * A bar's methods with its mixins merged as Vue merges them.
 *
 * @param {string} file The component file.
 * @param {object} words The words module, when the bar imports it.
 * @return {object} The methods.
 */
function methodsOf(file, words) {
	const stub = new Proxy({}, { get: (_t, key) => (key === 'default' ? {} : () => ({})) })
	const full = path.join(WIDGETS, file)
	const modules = {}
	for (const [, spec] of fs.readFileSync(full, 'utf8').matchAll(/^import\s+[\s\S]*?\s+from\s+'([^']+)'/gm)) {
		modules[spec] = spec === './stepBarWords.js' ? words : stub
	}
	const component = loadSfcScript(full, modules)
	return Object.assign({}, ...[...(component.mixins || []), component].map((part) => part.methods || {}))
}

describe('the word under a step-bar tile', () => {
	it('is Done, Now or Next on all three bars', async () => {
		let words
		try {
			words = await import('../../src/components/widgets/stepBarWords.js')
		} catch (_) {
			// Before the bars shared their words there was no such module.
			words = {}
		}
		const vm = { t: (_app, text) => `t:${text}` }
		for (const file of ['AmendmentStepBar.vue', 'DecisionStepBar.vue', 'MeetingStepBar.vue']) {
			const { stateWord } = methodsOf(file, words)
			expect(['done', 'current', 'upcoming', 'anything'].map((s) => stateWord.call(vm, s)), file).toEqual(['t:Done', 't:Now', 't:Next', 't:Next'])
		}
	})
})
