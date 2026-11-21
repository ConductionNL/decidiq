/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the griffie gathers a long-running topic in a theme page,
 * adds a decision to it from the decision's own page, reads the staff
 * timeline oldest first with the unpublished entries marked, and cannot
 * publish a theme that is not public (change publication-theme-pages, matrix
 * row pub-20).
 *
 * Written, not run: it needs a live instance. Every object it reads is
 * created here and removed afterwards, so it does not depend on an example
 * set. The nightly republication (REQ-THP-004) has no browser surface; it is
 * covered by tests/Unit/BackgroundJob/ThemeRefreshJobTest.php.
 *
 * @spec openspec/changes/publication-theme-pages/specs/public-publication/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	newLedger,
	writeHeaders,
} from './workflows/governance-fixture.ts'

const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const idOf = (o: Record<string, any>) => o.id ?? o['@self']?.id
const APP = `${BASE}/index.php/apps/decidiq`

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e public-publication::a-theme-for-the-swimming-pool
test('the griffier adds a decision to a theme from the decision page and the theme lists it', async ({ page }) => {
	const theme = await createObject(page, ledger, 'governance-theme', {
		title: `${tag}-Nieuw zwembad De Hoge Dijk`,
		introduction: 'The council decides on a new swimming pool.',
		status: 'active',
	})
	const decision = await createObject(page, ledger, 'decision', {
		title: `${tag}-Motie duurzaam zwembad`,
		decisionType: 'motion',
		proposer: 'Anna',
		lifecycle: 'proposed',
	})

	await page.goto(`${APP}/decisions/${idOf(decision)}`)
	await expect(page.getByTestId('theme-links-empty')).toBeVisible()
	await page.getByTestId('theme-links-add').click()

	const dialog = page.getByTestId('add-to-theme-modal')
	await expect(dialog).toBeVisible()
	await dialog.getByTestId('add-to-theme-select').click()
	await page.getByRole('option', { name: `${tag}-Nieuw zwembad De Hoge Dijk` }).click()
	await dialog.getByTestId('add-to-theme-confirm').click()
	await expect(dialog).toHaveCount(0)

	await expect(page.getByTestId('theme-links-entry')).toContainText(`${tag}-Nieuw zwembad De Hoge Dijk`)

	await page.goto(`${APP}/topic-themes/${idOf(theme)}`)
	await expect(page.getByTestId('theme-timeline-entry').filter({ hasText: `${tag}-Motie duurzaam zwembad` })).toBeVisible()
})

// @e2e public-publication::the-story-of-the-pool
test('the staff timeline runs oldest first and marks what is not published', async ({ page }) => {
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag}-Raadsvergadering maart`,
		scheduledDate: '2026-03-12T19:30:00+01:00',
	})
	const decision = await createObject(page, ledger, 'decision', {
		title: `${tag}-Krediet zwembad`,
		decisionType: 'resolution',
		decisionDate: '2026-06-25',
		lifecycle: 'proposed',
	})
	const theme = await createObject(page, ledger, 'governance-theme', {
		title: `${tag}-Zwembad tijdlijn`,
		introduction: 'The story of the pool.',
		status: 'active',
		meetings: [idOf(meeting)],
		decisions: [idOf(decision)],
	})

	await page.goto(`${APP}/topic-themes/${idOf(theme)}`)
	const entries = page.getByTestId('theme-timeline-entry')
	await expect(entries).toHaveCount(2)
	await expect(entries.nth(0)).toContainText(`${tag}-Raadsvergadering maart`)
	await expect(entries.nth(1)).toContainText(`${tag}-Krediet zwembad`)
	// Neither has a publication of its own, so the griffier sees both marked.
	await expect(page.getByTestId('theme-timeline-unpublished')).toHaveCount(2)
})

// @e2e public-publication::residents-read-the-theme
test('a theme that is not public is refused when published, and nothing reaches the public', async ({ page }) => {
	const decision = await createObject(page, ledger, 'decision', {
		title: `${tag}-Bouwbesluit zwembad`,
		decisionType: 'resolution',
		lifecycle: 'proposed',
	})
	const theme = await createObject(page, ledger, 'governance-theme', {
		title: `${tag}-Zwembad besloten`,
		introduction: 'Not public yet.',
		status: 'active',
		isPublic: false,
		decisions: [idOf(decision)],
	})

	// The API refuses it, so the publish button cannot get past the server.
	const response = await page.request.post(`${APP}/api/publications`, {
		headers: await writeHeaders(page),
		data: { sourceType: 'theme', sourceId: idOf(theme) },
	})
	expect(response.status()).toBeGreaterThanOrEqual(400)
	expect(response.status()).toBeLessThan(500)

	await page.goto(`${APP}/topic-themes/${idOf(theme)}`)
	const tab = page.getByTestId('publication-actions-tab')
	await expect(tab).toBeVisible()
	const publish = tab.getByTestId('publication-publish')
	if (await publish.count() > 0) {
		await publish.click()
		await expect(tab.getByText(/publication error/i)).toBeVisible()
	}
	await expect(tab.getByTestId('publication-status')).not.toContainText(/published as/i)
})
