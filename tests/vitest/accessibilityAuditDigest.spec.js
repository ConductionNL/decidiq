/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin page's Accessibility section (board DcBeheerSysteem,
 * Toegankelijkheid): the audit report script also writes a digest that
 * ships with the app (lib/Settings/accessibility-audit.json), and the admin
 * page shows its standard, sample, result and findings.
 */

import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { auditDigest } from '../../scripts/wcag-audit-report.mjs'
import { auditView } from '../../src/utils/accessibilityAudit.js'

const root = new URL('../../', import.meta.url).pathname

describe('the audit digest', () => {
	const rows = [
		{
			id: '1.4.3',
			name: 'Contrast (Minimum)',
			outcome: 'fail',
			findings: [
				{ page: 'Voting round', rule: 'color-contrast', owner: 'decidiq' },
			],
		},
		{
			id: '2.4.3',
			name: 'Focus Order',
			outcome: 'fail',
			findings: [
				{ page: 'Agenda item', rule: 'focus-order', owner: 'nextcloud' },
			],
		},
		{ id: '1.1.1', name: 'Non-text Content', outcome: 'pass', findings: [] },
		{
			id: '3.1.2',
			name: 'Language of Parts',
			outcome: 'not-tested',
			findings: [],
		},
	]
	const digest = auditDigest({
		version: '1.2.6',
		date: '2026-10-07',
		sample: { pages: [{}, {}, {}], processPages: [{}] },
		rows,
		scanned: true,
	})

	it('names the standard, the sample, the date and the counts', () => {
		expect(digest).toMatchObject({
			standard: 'WCAG 2.1 AA',
			version: '1.2.6',
			date: '2026-10-07',
			scanned: true,
			sampleSize: 4,
			outcomes: { pass: 1, fail: 2, 'not-applicable': 0, 'not-tested': 1 },
		})
	})

	it('lists every finding with its criterion, page and owner', () => {
		expect(digest.findings).toEqual([
			{
				criterion: '1.4.3',
				name: 'Contrast (Minimum)',
				page: 'Voting round',
				rule: 'color-contrast',
				owner: 'decidiq',
			},
			{
				criterion: '2.4.3',
				name: 'Focus Order',
				page: 'Agenda item',
				rule: 'focus-order',
				owner: 'nextcloud',
			},
		])
	})

	it('ships a digest with the app', () => {
		const shipped = JSON.parse(
			readFileSync(`${root}lib/Settings/accessibility-audit.json`, 'utf8'),
		)
		expect(shipped.standard).toBe('WCAG 2.1 AA')
		expect(Array.isArray(shipped.findings)).toBe(true)
	})

	it('reads as the admin section shows it, also without a digest', () => {
		expect(auditView(digest)).toEqual({
			available: true,
			standard: 'WCAG 2.1 AA',
			sampleSize: 4,
			date: '2026-10-07',
			scanned: true,
			findingCount: 2,
			findings: digest.findings,
		})
		expect(auditView(null).available).toBe(false)
	})
})
