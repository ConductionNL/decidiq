/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Impose, ratify and lift a confidentiality restriction from the app
 * (embargo-geheimhouding task 8).
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import {
	defaultBodyOf,
	imposePayload,
	liftPayload,
	objectOptions,
	optionsUrl,
	ratifyPayload,
	refusalOf,
	restrictionUrl,
} from '../../src/utils/confidentialityActions.js'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

const json = (path) => JSON.parse(readFileSync(resolve(__dirname, '../..', path), 'utf8'))

describe('confidentiality actions', () => {
	it('posts to the guarded endpoints', () => {
		expect(restrictionUrl('impose')).toBe('/index.php/apps/decidiq/api/confidentiality/restrictions/impose')
		expect(restrictionUrl('ratify', 'r1')).toBe('/index.php/apps/decidiq/api/confidentiality/restrictions/r1/ratify')
		expect(restrictionUrl('lift', 'r 1')).toBe('/index.php/apps/decidiq/api/confidentiality/restrictions/r%201/lift')
		expect(optionsUrl('confidentiality-ground')).toBe('/index.php/apps/openregister/api/objects/decidiq/confidentiality-ground?_limit=200')
	})

	it('labels grounds with their citation, leaves inactive grounds out and sorts', () => {
		const options = objectOptions({
			results: [
				{ id: 'g2', name: 'Privacy', citation: 'art. 5.1 lid 2 sub e Woo' },
				{ id: 'g1', name: 'Economic interest', citation: 'art. 5.1 lid 2 sub b Woo' },
				{ id: 'g3', name: 'Retired', citation: 'old', active: false },
				{ name: 'No id' },
			],
		}, 'confidentiality-ground')
		expect(options).toEqual([
			{ id: 'g1', label: 'Economic interest (art. 5.1 lid 2 sub b Woo)' },
			{ id: 'g2', label: 'Privacy (art. 5.1 lid 2 sub e Woo)' },
		])
	})

	it('labels other rows by name or title', () => {
		expect(objectOptions([{ id: 'd1', title: 'Lift the restriction' }, { '@self': { id: 'b1' }, name: 'Council' }], 'decision'))
			.toEqual([{ id: 'b1', label: 'Council' }, { id: 'd1', label: 'Lift the restriction' }])
		expect(objectOptions(null, 'decision')).toEqual([])
	})

	it('defaults the body to the one the page object names', () => {
		expect(defaultBodyOf({ governanceBody: 'b1' })).toBe('b1')
		expect(defaultBodyOf({ body: { id: 'b2' } })).toBe('b2')
		expect(defaultBodyOf({})).toBe('')
		expect(defaultBodyOf(null)).toBe('')
		expect(defaultBodyOf({ decidingBody: 'b3' })).toBe('b3')
		expect(defaultBodyOf({ governanceBody: 'b1' }, 'b4')).toBe('b4')
		expect(defaultBodyOf({ governanceBody: 'b1' }, '@object.decidingBody')).toBe('b1')
	})

	it('builds the impose body from the page schema, and nothing while incomplete', () => {
		const form = { schema: 'agenda-item', target: 'i1', ground: { id: 'g1', label: 'x' }, organ: 'chair', body: { id: 'b1' } }
		expect(imposePayload(form)).toEqual({ scope: 'item', target: 'i1', ground: 'g1', imposedBy: 'chair', imposedByBody: 'b1' })
		expect(imposePayload({ ...form, schema: 'decision' }).scope).toBe('decision')
		expect(imposePayload({ ...form, schema: 'digital-document' }).scope).toBe('document')
		expect(imposePayload({ ...form, schema: 'meeting' })).toBeNull()
		expect(imposePayload({ ...form, organ: 'mayor' })).toBeNull()
		expect(imposePayload({ ...form, ground: null })).toBeNull()
		expect(imposePayload({ ...form, body: null })).toBeNull()
		expect(imposePayload({ ...form, target: '' })).toBeNull()
	})

	it('builds the ratify and lift bodies, leaving out empty conditions', () => {
		expect(ratifyPayload({ id: 'd1' })).toEqual({ ratificationDecision: 'd1' })
		expect(ratifyPayload(null)).toBeNull()
		expect(liftPayload('d2', '  ')).toEqual({ dissolutionDecision: 'd2' })
		expect(liftPayload({ id: 'd2' }, ' After the sale ')).toEqual({ dissolutionDecision: 'd2', liftingConditions: 'After the sale' })
		expect(liftPayload(null, 'x')).toBeNull()
	})

	it('shows the server refusal, else the fallback', () => {
		expect(refusalOf({ response: { data: { message: 'Only the chair may.' } } }, 'fallback')).toBe('Only the chair may.')
		expect(refusalOf(new Error('x'), 'fallback')).toBe('fallback')
	})

	it('wires the dialogs into the pages and the registry', () => {
		const registry = readFileSync(resolve(__dirname, '../../src/registry.js'), 'utf8')
		for (const name of ['ConfidentialityImposeDialog', 'ConfidentialityRatifyDialog', 'ConfidentialityLiftDialog']) {
			expect(registry).toContain(`${name}: modal(${name})`)
		}

		const fragment = json('src/manifest.d/embargo-geheimhouding.json')
		const detail = fragment.pages.find((page) => page.id === 'ConfidentialityRestrictionDetail')
		const actions = Object.fromEntries(detail.config.headerActions.map((action) => [action.target, action]))
		expect(actions.ConfidentialityRatifyDialog.type).toBe('open-modal')
		expect(actions.ConfidentialityRatifyDialog.visibleWhen).toEqual({ field: 'lifecycle', value: 'imposed' })
		expect(actions.ConfidentialityLiftDialog.visibleWhen.any.map((rule) => rule.value)).toEqual(['imposed', 'ratified'])

		const manifest = json('src/manifest.json')
		for (const id of ['AgendaItemDetail', 'DecisionDetail']) {
			const page = manifest.pages.find((one) => one.id === id)
			const impose = (page.config.headerActions || []).find((action) => action.target === 'ConfidentialityImposeDialog')
			expect(impose, id).toBeDefined()
			expect(impose.type).toBe('open-modal')
			expect(impose.props.schema).toBe(page.config.schema)
		}
	})
})
