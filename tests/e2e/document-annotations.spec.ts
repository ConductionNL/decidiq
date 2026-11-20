/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a member opens a PDF paper of an agenda item in the
 * annotator (AnnotationView), places a private sticky note, finds it in the
 * panel after a reload, and deletes it (change document-annotations). Who sees
 * which annotation is covered server-side by AnnotationControllerTest; the
 * anchor geometry by tests/vitest/documentAnnotations.spec.js.
 *
 * Needs an agenda item "Vaststelling omgevingsvisie" with at least one PDF
 * paper in its Documents.
 *
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'

/**
 * Open the annotator on the first PDF paper of the agenda item.
 *
 * @param page The Playwright page.
 */
async function openAnnotator(page) {
	await page.goto(`${BASE}/index.php/apps/decidiq/agenda-items`)
	await page.getByText('Vaststelling omgevingsvisie').first().click()
	await page.getByTestId('document-annotations-open').first().click()
	await expect(page).toHaveURL(/\/apps\/decidiq\/annotate\/\d+/)
	await expect(page.getByTestId('annotation-view')).toBeVisible()
	await expect(page.getByTestId('annotation-overlay').first()).toBeVisible()
}

test.describe.configure({ mode: 'serial' })

// The page under test: src/views/AnnotationView.vue (route /annotate/:fileId).
test.describe('AnnotationView', () => {
	// @e2e document-annotations::open-the-annotator-from-an-agenda-item
	test('the annotator opens a PDF paper of an agenda item', async ({ page }) => {
		await openAnnotator(page)
		await expect(page.getByTestId('annotation-panel')).toBeVisible()
	})

	// @e2e document-annotations::sticky-note-carries-body-text-at-a-point-anchor
	test('a sticky note is saved, private, and is there after a reload', async ({
		page,
	}) => {
		await openAnnotator(page)
		await page.getByTestId('annotation-mode-note').click()
		await page
			.getByTestId('annotation-overlay')
			.first()
			.click({ position: { x: 120, y: 160 } })
		await page
			.getByTestId('annotation-composer-text')
			.locator('textarea')
			.fill('Navragen bij wethouder: dekking uit reserve?')
		await page.getByTestId('annotation-composer-save').click()
		await page.reload()
		const entry = page
			.getByTestId('annotation-panel')
			.getByText('Navragen bij wethouder: dekking uit reserve?')
		await expect(entry).toBeVisible()
		await entry.click()
		const remove = page
			.locator('[data-testid^="annotation-panel-delete-"]')
			.first()
		await remove.click()
		await page.getByTestId('annotation-delete-confirm').click()
		await expect(entry).toHaveCount(0)
	})

	// @e2e document-annotations::no-annotate-action-on-non-pdf-files
	test('only PDF papers are offered for annotating', async ({ page }) => {
		await page.goto(`${BASE}/index.php/apps/decidiq/agenda-items`)
		await page.getByText('Vaststelling omgevingsvisie').first().click()
		const rows = page.getByTestId('document-annotations-row')
		for (const name of await rows.allTextContents()) {
			expect(name.toLowerCase()).toContain('.pdf')
		}
	})
})
