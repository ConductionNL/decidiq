/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * WCAG 2.1 AA scan (platform-accessibility-audit-report, matrix row plt-22).
 * Visits every page of tests/e2e/a11y/sample.json, runs axe-core with the
 * WCAG 2.1 A and AA rules, gives every finding an owner (owner.js) and writes
 * tests/axe/report.json, which the hydra axe gate and
 * scripts/wcag-audit-report.mjs read. A serious or critical finding owned by
 * decidiq fails the page; findings in Nextcloud's chrome or on a portal page
 * are recorded and do not fail decidiq.
 *
 * Runs as its own Playwright project: `npm run a11y:scan`. It is kept out of
 * the regression project so the regular e2e run stays about behaviour.
 *
 * @spec openspec/changes/platform-accessibility-audit-report/specs/accessibility-baseline/spec.md#requirement-req-aar-002-axe-core-scans-every-sampled-page-and-attributes-each-finding
 */
import { expect, test, type Page } from '@playwright/test'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import { BASE_URL as BASE } from '../base-url.ts'
import { attribute, blocking, NEXTCLOUD_CHROME, ownerOf, reportOf } from './owner.js'

const here = __dirname
const root = path.resolve(here, '../../..')
const sample = JSON.parse(readFileSync(path.join(here, 'sample.json'), 'utf8'))
const axeSource = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8')
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']
const found: Array<Record<string, any>> = []

/**
 * Open a sample entry: its path, or the first row of the index it names.
 */
async function open(page: Page, entry: Record<string, any>): Promise<void> {
	if (entry.path && entry.path.startsWith('/apps/')) {
		await page.goto(`${BASE}/index.php${entry.path}`)
		return
	}
	if (entry.path) {
		await page.goto(`${BASE}/index.php/apps/decidiq${entry.path}`)
		return
	}
	await page.goto(`${BASE}/index.php/apps/decidiq${entry.from}`)
	await page.locator('#content tbody tr').first().click()
	await page.waitForURL(/\/apps\/decidiq\/.+\/.+/)
	if (entry.suffix) {
		await page.goto(`${page.url().replace(/\/$/, '')}${entry.suffix}`)
	}
}

/**
 * Run axe on the open page and give each node its owner.
 */
async function scan(page: Page, entry: Record<string, any>): Promise<Array<Record<string, any>>> {
	await page.waitForLoadState('networkidle')
	await page.addScriptTag({ content: axeSource })
	const violations = await page.evaluate(async ([tags, chrome]) => {
		// @ts-expect-error axe is the script just added.
		const result = await window.axe.run(document, { runOnly: { type: 'tag', values: tags } })
		return result.violations.map((violation: any) => ({
			id: violation.id,
			impact: violation.impact,
			tags: violation.tags,
			help: violation.help,
			helpUrl: violation.helpUrl,
			nodes: violation.nodes.map((node: any) => {
				const element = document.querySelector(node.target[0])
				return {
					target: node.target,
					html: node.html,
					inNextcloudChrome: Boolean(element && element.closest(chrome)),
				}
			}),
		}))
	}, [TAGS, NEXTCLOUD_CHROME] as const)
	for (const violation of violations) {
		for (const node of violation.nodes) {
			node.owner = ownerOf(node, entry.owner)
		}
	}
	return attribute(violations, entry.id)
}

const entries = [...sample.pages, ...sample.processPages]

for (const entry of entries) {
	test(`WCAG 2.1 AA: ${entry.id}`, async ({ page }) => {
		await open(page, entry)
		const attributed = await scan(page, entry)
		found.push(...attributed)
		expect(blocking(attributed).map((violation) => `${violation.id}: ${violation.help}`)).toEqual([])
	})
}

for (const entry of sample.portal.pages) {
	test(`WCAG 2.1 AA, portal: ${entry.id}`, async ({ page }) => {
		const response = await page.request.get(`${BASE}/index.php${entry.path}`)
		test.skip(!response.ok(), 'portaliq is not installed on this instance')
		await open(page, entry)
		found.push(...(await scan(page, entry)))
	})
}

// @e2e accessibility-baseline::a-decidiq-violation-fails-the-scan
test('a button without a name in the app root is a blocking decidiq finding', async ({ page }) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/`)
	await page.locator('#content').evaluate((content) => {
		const button = document.createElement('button')
		button.setAttribute('data-testid', 'wcag-fixture-unnamed')
		content.appendChild(button)
	})
	const attributed = await scan(page, { id: 'fixture', owner: 'decidiq' })
	expect(blocking(attributed).map((violation) => violation.id)).toContain('button-name')
})

test.afterAll(() => {
	mkdirSync(path.join(root, 'tests/axe'), { recursive: true })
	const report = reportOf(found, {
		timestamp: new Date().toISOString(),
		theme: process.env.WCAG_THEME || 'default',
		sample: entries.map((entry) => entry.id),
	})
	writeFileSync(path.join(root, 'tests/axe/report.json'), JSON.stringify(report, null, '\t'))
})
