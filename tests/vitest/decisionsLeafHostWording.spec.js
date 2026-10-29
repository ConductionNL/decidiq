/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The decisions leaf speaks the host's words: the wording helper, and the
 * widget's empty-state panel as the template declares it (the vitest
 * environment has no SFC transform, so the template is read as source).
 *
 * @spec openspec/changes/decisions-leaf-speaks-the-hosts-words/specs/decidesk-contract-decision-hub/spec.md#requirement-req-dcdh-009-the-decisions-leaf-speaks-the-hosts-words
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { hostWording } from '../../src/integrations/hostWording.js'

const OWN = { emptyTitle: '', emptyText: 'No decision-making linked to this object yet.', createLabel: 'Create proposal for this object' }

const widget = fs.readFileSync(path.resolve(__dirname, '../../src/integrations/CnDecisionsWidget.vue'), 'utf8')
const template = widget.slice(0, widget.indexOf('<script'))

describe('hostWording', () => {
	it('takes the host words where given', () => {
		const words = hostWording({ emptyTitle: 'Geen besluit gekoppeld', createLabel: 'Voorstel maken voor dit ticket' }, OWN)
		expect(words).toEqual({ emptyTitle: 'Geen besluit gekoppeld', emptyText: OWN.emptyText, createLabel: 'Voorstel maken voor dit ticket' })
	})

	it('keeps the leaf words for a missing or blank host word', () => {
		expect(hostWording({ createLabel: '  ' }, OWN)).toEqual(OWN)
		expect(hostWording(undefined, OWN)).toEqual(OWN)
	})
})

describe('CnDecisionsWidget empty state', () => {
	it('declares the three host props', () => {
		for (const prop of ['emptyTitle', 'emptyText', 'createLabel']) {
			expect(widget).toMatch(new RegExp(`\\n\\t\\t${prop}: \\{ type: String`))
		}
	})

	it('draws a named empty state as a panel with the create button and a decidiq link', () => {
		const panel = template.slice(template.indexOf('cn-decisions-widget__empty-panel'))
		expect(panel).toContain('{{ wording.emptyTitle }}')
		expect(panel).toContain('{{ wording.emptyText }}')
		expect(panel).toContain('{{ createProposalLabel }}')
		expect(panel).toMatch(/<a\s[^>]*:href="appUrl"[^>]*>[\s\S]*?\{\{ openInDecidiqLabel \}\}/)
	})
})
