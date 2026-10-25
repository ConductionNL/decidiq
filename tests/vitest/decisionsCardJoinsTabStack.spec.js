/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The decisions card on a host's tab (dossiq DqZaakBesluiten) is a board
 * card the host's look styles: it carries the host contract class
 * `cn-case-widget-card`, so as the first card under the tab strip its
 * top-left corner joins the open tab; its title sits on a 1.2 line and its
 * subtitle is a 14px line no theme paragraph rule resizes.
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'

const sfc = fs.readFileSync(path.join(__dirname, '../../src/integrations/CnDecisionsTab.vue'), 'utf8')

/**
 * The body of the rule written for exactly this selector.
 *
 * @param {string} selector The selector.
 * @return {string} The rule body, or ''.
 */
function rule(selector) {
	const at = sfc.indexOf(selector + ' {')
	return at === -1 ? '' : sfc.slice(at, sfc.indexOf('}', at))
}

describe('the decisions card on a host tab', () => {
	it('carries the host contract class for a widget that draws its own card', () => {
		expect(sfc).toContain('<section class="cn-decisions-tab cn-case-widget-card"')
	})

	it('sets the title on a 1.2 line and the subtitle as a span, 14px through the theme token too', () => {
		expect(rule('.cn-decisions-tab__title')).toContain('line-height: 1.2')
		expect(sfc).toContain('<span class="cn-decisions-tab__subtitle">')
		expect(sfc).toContain('--nldesign-component-paragraph-font-size: 14px;')
	})

	it('sets the subtitle and each decision title on a 1.2 line, as the board (header 41px, first row 45px under the title)', () => {
		expect(rule('.cn-decisions-tab__subtitle,\n.cn-decisions-tab__empty')).toContain('line-height: 1.2')
		expect(rule('.cn-decisions-tab__name')).toContain('line-height: 1.2')
	})

	it('draws the mandate notice on the board\'s light success ground', () => {
		expect(rule('.cn-decisions-tab__notice')).toContain('background: var(--nl-color-green-light, #e7f3ea)')
	})
})
