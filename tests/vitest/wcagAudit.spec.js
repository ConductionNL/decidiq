// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * platform-accessibility-audit-report (matrix row plt-22): the sample check,
 * the owner of a finding, the manual checklist and the audit report.
 *
 * @spec openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md
 */
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import {
	auditRows,
	renderReport,
	summary,
} from '../../scripts/wcag-audit-report.mjs'
import { manifestPages, sampleProblems } from '../../scripts/wcag-sample-check.mjs'
import { attribute, blocking, ownerOf, reportOf } from '../e2e/a11y/owner.js'

const root = new URL('../../', import.meta.url).pathname
const json = (path) => JSON.parse(readFileSync(`${root}${path}`, 'utf8'))
const sample = json('tests/e2e/a11y/sample.json')
const criteria = json('docs/compliance/wcag-2.1-criteria.json')
const checklist = json('docs/compliance/wcag-manual-checks.json')

describe('the structured sample (REQ-AAR-001)', () => {
	it('covers every page type the manifests declare today', () => {
		expect(sampleProblems(manifestPages(root), sample)).toEqual([])
	})

	it('fails and names the page when a new page type has no entry', () => {
		const pages = [
			...manifestPages(root),
			{
				id: 'MeetingPreferences',
				type: 'settings',
				route: '/meetings/preferences',
				file: 'src/manifest.d/new-area.json',
			},
		]
		const problems = sampleProblems(pages, sample)
		expect(problems).toHaveLength(1)
		expect(problems[0]).toContain('MeetingPreferences')
	})

	it('fails on an entry whose page is gone', () => {
		const stale = {
			pages: [...sample.pages, { id: 'gone', pageId: 'NoSuchPage' }],
		}
		expect(sampleProblems(manifestPages(root), stale).join()).toContain(
			'NoSuchPage',
		)
	})
})

describe('the owner of a finding (REQ-AAR-002)', () => {
	const violation = (nodes) => [
		{ id: 'button-name', impact: 'serious', tags: ['wcag412'], nodes },
	]

	it('attributes the header, the app root and a portal page', () => {
		expect(ownerOf({ inNextcloudChrome: true }, 'decidiq')).toBe('nextcloud')
		expect(ownerOf({ inNextcloudChrome: false }, 'decidiq')).toBe('decidiq')
		expect(ownerOf({ inNextcloudChrome: false }, 'portaliq')).toBe('portaliq')
	})

	it('fails decidiq on its own serious finding only', () => {
		const header = attribute(
			violation([{ target: ['#header button'], owner: 'nextcloud' }]),
			'dashboard',
		)
		expect(blocking(header)).toEqual([])
		const app = attribute(
			violation([{ target: ['#content button'], owner: 'decidiq' }]),
			'meeting',
		)
		expect(blocking(app)).toHaveLength(1)
		expect(blocking(app)[0]).toMatchObject({ owner: 'decidiq', page: 'meeting' })
	})

	it('writes the shape the hydra axe gate reads, with decidiq findings under violations', () => {
		const mixed = attribute(
			violation([
				{ target: ['#header a'], owner: 'nextcloud' },
				{ target: ['#content a'], owner: 'decidiq' },
			]),
			'dashboard',
		)
		const report = reportOf(mixed, { timestamp: '2026-09-30T08:00:00Z' })
		expect(Array.isArray(report.violations)).toBe(true)
		expect(report.violations.map((v) => v.owner)).toEqual(['decidiq'])
		expect(report.others.map((v) => v.owner)).toEqual(['nextcloud'])
	})
})

describe('the manual checklist (REQ-AAR-003)', () => {
	it('has one plain check for every A and AA criterion axe does not decide', () => {
		const manual = criteria.criteria
			.filter((c) => !criteria.axe.includes(c.id))
			.map((c) => c.id)
		expect(checklist.entries.map((e) => e.criterion).sort()).toEqual(
			[...manual].sort(),
		)
		for (const entry of checklist.entries) {
			expect(entry.check.length).toBeGreaterThan(20)
			expect(entry.check).not.toMatch(/—/)
		}
	})

	it('holds all 50 WCAG 2.1 A and AA criteria', () => {
		expect(criteria.criteria).toHaveLength(50)
		expect(criteria.criteria.filter((c) => c.level === 'AA')).toHaveLength(20)
	})

	it('reads an unfilled entry as not tested, not as a pass', () => {
		const rows = auditRows({ criteria, scan: { violations: [] }, checklist })
		expect(rows.find((row) => row.id === '1.4.10').outcome).toBe('not-tested')
	})
})

describe('the audit report (REQ-AAR-004)', () => {
	it('shows twelve empty entries as twelve not tested', () => {
		const twelve = {
			entries: checklist.entries
				.slice(0, 12)
				.map((e) => ({ ...e, result: '' })),
		}
		const rest = checklist.entries.slice(12).map((e) => ({
			...e,
			result: 'pass',
			tester: 'Anna',
			date: '2026-09-30',
		}))
		const rows = auditRows({
			criteria,
			scan: { violations: [] },
			checklist: { entries: [...twelve.entries, ...rest] },
		})
		expect(summary(rows)['not-tested']).toBe(12)
		expect(summary(rows).pass).toBe(50 - 12)
	})

	it('has one row per criterion and names the page, rule and owner of a failure', () => {
		const scan = {
			violations: [
				{
					id: 'color-contrast',
					impact: 'serious',
					tags: ['wcag2aa', 'wcag143'],
					page: 'meeting',
					owner: 'decidiq',
				},
			],
			others: [
				{
					id: 'link-name',
					impact: 'serious',
					tags: ['wcag244'],
					page: 'dashboard',
					owner: 'nextcloud',
				},
			],
		}
		const rows = auditRows({ criteria, scan, checklist })
		expect(rows).toHaveLength(50)
		const markdown = renderReport({
			version: '1.9.0',
			date: '2026-09-30',
			sample,
			rows,
			scanned: true,
			theme: 'default',
		})
		expect(markdown).toContain('decidiq version 1.9.0, evaluated on 2026-09-30')
		expect(markdown).toContain('supplier self-evaluation following WCAG-EM')
		expect(markdown).toContain('meeting: color-contrast (serious, decidiq)')
		expect(markdown).toContain('dashboard: link-name (serious, nextcloud)')
		expect(markdown).toMatch(/Not tested: \d+/)
	})

	it('writes Markdown the docs site can build: no bare braces or angle brackets', () => {
		const rows = auditRows({ criteria, scan: null, checklist })
		const withBraces = {
			...sample,
			notApplicable: [
				{
					id: 'projection',
					reason: 'GET /api/voting-rounds/{id}/public-state <json>',
				},
			],
		}
		const markdown = renderReport({
			version: '1',
			date: 'd',
			sample: withBraces,
			rows,
			scanned: false,
		})
		expect(markdown).toContain('/api/voting-rounds/\\{id\\}/public-state')
		expect(markdown).not.toMatch(/(^|[^\\])[{}]/)
		expect(markdown).not.toMatch(/<(?!br>)/)
	})

	it('says so when no scan ran', () => {
		const rows = auditRows({ criteria, scan: null, checklist })
		expect(rows.find((row) => row.id === '1.4.3').outcome).toBe('not-tested')
		expect(
			renderReport({ version: '1', date: 'd', sample, rows, scanned: false }),
		).toContain('did not run')
	})
})
