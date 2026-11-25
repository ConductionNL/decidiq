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
 * Reads the agenda item "Vaststelling omgevingsvisie" of the municipality
 * example set. When that item carries no PDF paper (an install where the
 * example set's files were not attached), the spec attaches the fixture
 * `tagged-paper.pdf` through OpenRegister's files API first and removes it
 * afterwards.
 *
 * @spec openspec/changes/document-annotations/specs/document-annotations/spec.md
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import * as path from 'node:path'
import { BASE_URL as BASE } from './base-url.ts'
import { openRow } from './support/open-row.ts'
import { writeHeaders } from './workflows/governance-fixture.ts'

const ITEM = 'Vaststelling omgevingsvisie'
const FILES = `${BASE}/index.php/apps/openregister/api/objects/decidiq/agenda-item`
const PAPER = 'tagged-paper.pdf'

/** The paper this run attached, to remove afterwards; null when the item had one. */
let attached: { itemId: string, fileId: number } | null = null

/**
 * Make sure the agenda item has a PDF paper: attach the fixture when it has none.
 *
 * @param page A page with the administrator's session.
 */
async function ensurePdfPaper(page: Page): Promise<void> {
	if (attached !== null) {
		return
	}
	const list = await page.request.get(`${FILES}?_search=${encodeURIComponent(ITEM)}&_limit=20`)
	expect(list.ok(), `list agenda items: ${list.status()}`).toBeTruthy()
	const item = ((await list.json()).results ?? []).find((row: any) => row?.title === ITEM)
	expect(item, `the agenda item "${ITEM}" exists (municipality example set)`).toBeTruthy()
	const itemId = String(item.id ?? item['@self']?.id)
	const files = await page.request.get(`${FILES}/${itemId}/files`)
	const existing = files.ok() ? ((await files.json()).results ?? []) : []
	if (existing.some((file: any) => String(file?.mimetype ?? file?.mimeType ?? file?.type ?? '').includes('pdf'))) {
		return
	}
	const resp = await page.request.post(`${FILES}/${itemId}/files`, {
		headers: await writeHeaders(page),
		data: { name: PAPER, content: readFileSync(path.resolve(__dirname, 'fixtures', PAPER), 'latin1') },
	})
	expect(resp.status(), await resp.text()).toBeLessThan(300)
	const body = await resp.json()
	attached = { itemId, fileId: Number(body?.id ?? body?.fileId ?? 0) }
}

/**
 * Open the annotator on the first PDF paper of the agenda item.
 *
 * @param page The Playwright page.
 */
async function openAnnotator(page) {
	await ensurePdfPaper(page)
	await page.goto(`${BASE}/index.php/apps/decidiq/agenda-items`)
	await openRow(page, ITEM, true)
	// The widget sits below the fold and mounts when it scrolls into view.
	await page.getByRole('group', { name: 'agenda-annotations' }).scrollIntoViewIfNeeded()
	await page.getByTestId('document-annotations-open').first().click()
	await expect(page).toHaveURL(/\/apps\/decidiq\/annotate\/\d+/)
	await expect(page.getByTestId('annotation-view')).toBeVisible()
	await expect(page.getByTestId('annotation-overlay').first()).toBeVisible()
}

test.describe.configure({ mode: 'serial' })

test.afterAll(async ({ browser }) => {
	if (attached === null || attached.fileId === 0) {
		return
	}
	const page = await browser.newPage()
	await page.goto(`${BASE}/index.php/apps/decidiq/`)
	await page.request.delete(`${FILES}/${attached.itemId}/files/${attached.fileId}`, { headers: await writeHeaders(page) })
	await page.close()
})

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
		await openRow(page, 'Vaststelling omgevingsvisie', true)
		const rows = page.getByTestId('document-annotations-row')
		for (const name of await rows.allTextContents()) {
			expect(name.toLowerCase()).toContain('.pdf')
		}
	})
})
