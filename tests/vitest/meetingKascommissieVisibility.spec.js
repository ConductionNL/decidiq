/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure helpers behind MeetingKascommissieTab.vue
 * (src/components/tabs/kascommissieVisibility.js): the assoc-mode
 * visibility gate and the CnObjectListWidget content blob.
 *
 * @spec openspec/changes/meeting-facet-composition/specs/meeting-detail-view/spec.md#requirement-req-mdv-012-kascommissie-verklaringen-facet-assoc-mode-only
 */

import { describe, expect, it } from 'vitest'
import {
	ASSOC_MODE,
	isKascommissieVisible,
	kascommissieContent,
} from '../../src/components/tabs/kascommissieVisibility.js'

describe('isKascommissieVisible (REQ-MDV-012)', () => {
	it('is visible in association ("assoc") mode', () => {
		expect(isKascommissieVisible('assoc')).toBe(true)
	})

	it('is hidden in every other organisatie_modus', () => {
		expect(isKascommissieVisible('gov')).toBe(false)
		expect(isKascommissieVisible('corp')).toBe(false)
		expect(isKascommissieVisible('ops')).toBe(false)
	})

	it('is hidden for an unset/unknown mode (defensive default)', () => {
		expect(isKascommissieVisible('')).toBe(false)
		expect(isKascommissieVisible(undefined)).toBe(false)
		expect(isKascommissieVisible('not-a-real-mode')).toBe(false)
	})

	it('the ASSOC_MODE constant is what the gate checks against', () => {
		expect(isKascommissieVisible(ASSOC_MODE)).toBe(true)
		expect(ASSOC_MODE).toBe('assoc')
	})
})

describe('kascommissieContent (REQ-MDV-012)', () => {
	it('scopes the filter to the current meeting governanceBody via the @object token', () => {
		const content = kascommissieContent()
		expect(content.filter).toEqual({ governanceBody: '@object.governanceBody' })
	})

	it('targets the kascommissie-verklaring schema on the decidesk register', () => {
		const content = kascommissieContent()
		expect(content.register).toBe('decidesk')
		expect(content.schema).toBe('kascommissie-verklaring')
	})

	it('is read-only — no create affordance', () => {
		expect(kascommissieContent().allowCreate).toBe(false)
	})

	it('carries the viewAllRoute/viewAllQuery/rowRoute the top-level register pages declare', () => {
		const content = kascommissieContent()
		expect(content.viewAllRoute).toBe('KascommissieVerklaringen')
		expect(content.viewAllQuery).toEqual({
			governanceBody: '@object.governanceBody',
		})
		expect(content.rowRoute).toBe('KascommissieVerklaringDetail')
	})
})
