
/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the document accessibility check (change
 * document-accessibility-check). A clerk sees each paper's badge and the
 * findings with how to fix them, checks a paper that was not checked yet,
 * meets the publication gate dialog when a failing paper would be published,
 * and opens the aggregate report with its CSV export. A user who may not read
 * the agenda item cannot scan its papers.
 *
 * The scanner's checks, the gate matrix and the override record are covered by
 * DocumentAccessibilityScanServiceTest, DocumentPublicationGateTest and
 * AgendaPublicationAccessibilityGateTest; this suite drives the real UI and
 * endpoints over two fixture PDFs (tests/e2e/fixtures): a tiny tagged PDF with
 * language, title and text that passes, and a scanned-image PDF without a
 * text layer or tags that fails.
 *
 * Every write goes to objects this suite creates and deletes again; the
 * enforcement mode and the scan-on-upload switch are restored afterwards.
 *
 * @spec openspec/changes/archive/2026-11-20-document-accessibility-check/specs/document-accessibility-check/spec.md
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { readFileSync } from 'node:fs'
import * as path from 'node:path'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	newLedger,
	writeHeaders,
} from './workflows/governance-fixture.ts'

const ADMIN_USER = process.env.NEXTCLOUD_USER || 'admin'
const ADMIN_PASS = process.env.NEXTCLOUD_PASS || 'admin'
const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const outsider = `${tag}-a11y-outsider`.slice(0, 60)
const password = `Dq-${randomBytes(12).toString('hex')}-Pw1!`

const TAGGED = 'tagged-paper.pdf'
const SCANNED = 'scanned-image-paper.pdf'
const LATE = 'late-paper.pdf'

const APP = `${BASE}/index.php/apps/decidiq`
const FILES = `${BASE}/index.php/apps/openregister/api/objects/decidiq/agenda-item`

// The tests share the fixtures and switch the enforcement mode: run them in order.
test.describe.configure({ mode: 'serial' })

let meetingId = ''
let itemId = ''
const fileIds: Record<string, number> = {}
let savedSettings: Record<string, string> = {}

/**
 * Basic-auth headers for the OCS and app APIs.
 *
 * @param user The user.
 * @param pass The password.
 */
function basic(user: string, pass: string): Record<string, string> {
	return {
		Authorization: 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64'),
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	}
}

/**
 * The bytes of one fixture PDF, as text (both fixtures are plain ASCII).
 *
 * @param name The fixture file name.
 */
function fixture(name: string): string {
	return readFileSync(path.resolve(__dirname, 'fixtures', name), 'latin1')
}

/**
 * Attach one fixture PDF to the agenda item through OpenRegister's files API.
 *
 * @param page    A page with the administrator's session.
 * @param name    The file name to store it under.
 * @param content The fixture to store.
 * @return the Nextcloud file id
 */
async function attach(page: Page, name: string, content: string): Promise<number> {
	const resp = await page.request.post(`${FILES}/${itemId}/files`, {
		headers: await writeHeaders(page),
		data: { name, content },
	})
	expect(resp.status(), await resp.text()).toBeLessThan(300)
	const body = await resp.json()
	const id = Number(body?.id ?? body?.fileId ?? 0)
	expect(id, JSON.stringify(body)).toBeGreaterThan(0)
	return id
}

/**
 * Save the document scan settings as the administrator.
 *
 * @param page     A page with the administrator's session.
 * @param settings The settings to save.
 */
async function saveSettings(page: Page, settings: Record<string, string>): Promise<void> {
	const resp = await page.request.put(`${APP}/api/settings`, {
		headers: await writeHeaders(page),
		data: settings,
	})
	expect(resp.status(), await resp.text()).toBeLessThan(300)
}

/**
 * Scan one paper on demand.
 *
 * @param request The request context to scan as.
 * @param headers Extra headers (CSRF token or basic auth).
 * @param fileId  The file id.
 */
async function scan(
	request: APIRequestContext,
	headers: Record<string, string>,
	fileId: number,
) {
	return request.post(`${APP}/api/accessibility/scan`, {
		headers: { ...headers, 'Content-Type': 'application/json' },
		data: { sourceObject: itemId, fileId },
	})
}

/**
 * Open the agenda item and bring its accessibility block into view.
 *
 * @param page The page.
 */
async function openAccessibility(page: Page) {
	await page.goto(`${APP}/agenda-items/${itemId}`)
	const block = page.getByTestId('document-accessibility')
	if (!(await block.isVisible().catch(() => false))) {
		const tab = page.getByRole('tab', { name: 'Accessibility' })
		if (await tab.isVisible().catch(() => false)) {
			await tab.click()
		}
	}
	await expect(block).toBeVisible()
	return block
}

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage()

	const current = await page.request.get(`${APP}/api/settings`)
	if (current.ok()) {
		const data = await current.json()
		const values = data?.config ?? data ?? {}
		savedSettings = {
			accessibility_enforcement_mode: String(values.accessibility_enforcement_mode ?? 'warn'),
			accessibility_scan_on_upload: String(values.accessibility_scan_on_upload ?? 'true'),
		}
	}
	// No background scan races the on-demand ones below.
	await saveSettings(page, {
		accessibility_enforcement_mode: 'warn',
		accessibility_scan_on_upload: 'false',
	})

	const created = await page.request.post(`${BASE}/ocs/v2.php/cloud/users?format=json`, {
		headers: basic(ADMIN_USER, ADMIN_PASS),
		data: { userid: outsider, password },
	})
	expect(created.ok(), await created.text()).toBe(true)

	const body = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-a11y-body`,
		bodyType: 'legislative',
	})
	const bodyId = body.id ?? body['@self']?.id
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag} Raadsvergadering toegankelijkheid`,
		meetingType: 'regular',
		scheduledDate: '2026-12-09T19:30:00Z',
		governanceBody: bodyId,
	})
	meetingId = meeting.id ?? meeting['@self']?.id
	const item = await createObject(page, ledger, 'agenda-item', {
		title: `${tag} Krediet verlichting`,
		itemType: 'decision',
		orderNumber: 1,
		meeting: meetingId,
	})
	itemId = item.id ?? item['@self']?.id

	fileIds[TAGGED] = await attach(page, TAGGED, fixture(TAGGED))
	fileIds[SCANNED] = await attach(page, SCANNED, fixture(SCANNED))

	const headers = await writeHeaders(page)
	for (const name of [TAGGED, SCANNED]) {
		const resp = await scan(page.request, headers, fileIds[name])
		expect(resp.status(), await resp.text()).toBe(200)
	}
	await page.close()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	if (Object.keys(savedSettings).length > 0) {
		await saveSettings(page, savedSettings).catch(() => undefined)
	}
	await cleanupAll(page, ledger)
	await page.request
		.delete(`${BASE}/ocs/v2.php/cloud/users/${outsider}?format=json`, {
			headers: basic(ADMIN_USER, ADMIN_PASS),
		})
		.catch(() => undefined)
	await page.close()
})

// @e2e document-accessibility-check::req-001-a-tagged-pdf-passes
test('the scan endpoint reports the tagged PDF as passing and the scanned image as failing', async ({
	page,
}) => {
	const headers = await writeHeaders(page)
	const passed = await (await scan(page.request, headers, fileIds[TAGGED])).json()
	expect(passed.status).toBe('pass')
	expect(passed.scannerVersion).toBeTruthy()

	const failed = await (await scan(page.request, headers, fileIds[SCANNED])).json()
	expect(failed.status).toBe('fail')
	const codes = (failed.findings ?? []).map((f: { code: string }) => f.code)
	expect(codes).toEqual(expect.arrayContaining(['no-text-layer', 'not-tagged']))
})

// @e2e document-accessibility-check::req-001-the-caller-must-be-able-to-read-the-source
test('a user who may not read the agenda item cannot scan its papers', async ({ playwright }) => {
	const stranger = await playwright.request.newContext({
		extraHTTPHeaders: basic(outsider, password),
		storageState: { cookies: [], origins: [] },
	})
	const resp = await scan(stranger, {}, fileIds[TAGGED])
	expect(resp.status()).toBe(403)
	await stranger.dispose()
})

// @e2e document-accessibility-check::req-002-each-attachment-shows-its-badge
// @e2e document-accessibility-check::req-004-remediation-guidance-per-finding
test('each paper shows its badge, and the failing one explains how to fix it', async ({ page }) => {
	const block = await openAccessibility(page)
	await expect(block).toContainText('heuristic')
	await expect(block.getByTestId('accessibility-badge-pass')).toBeVisible()
	const failing = block.getByTestId('accessibility-badge-fail')
	await expect(failing).toBeVisible()

	await failing.click()
	await expect(failing).toHaveAttribute('aria-expanded', 'true')
	const detail = block.getByTestId('document-accessibility-detail')
	await expect(detail).toContainText('without a text layer')
	await expect(detail).toContainText('structure tags')
})

// @e2e document-accessibility-check::req-002-an-unscanned-attachment-can-be-checked-now
test('a paper added while scan-on-upload is off shows Not checked, and Check now scans it', async ({
	page,
}) => {
	fileIds[LATE] = await attach(page, LATE, fixture(TAGGED))
	const block = await openAccessibility(page)
	const row = block.getByTestId('document-accessibility-row').filter({ hasText: LATE })
	await expect(row.getByTestId('accessibility-badge-not-scanned')).toBeVisible()

	await row.getByTestId('document-accessibility-scan').click()
	await expect(row.getByTestId('accessibility-badge-pass')).toBeVisible({ timeout: 15_000 })
	await expect(row.getByTestId('document-accessibility-scan')).toHaveCount(0)
})

// @e2e document-accessibility-check::req-003-block-mode-needs-a-reason
test('publishing past a failing paper in block mode asks for a reason first', async ({ page }) => {
	await saveSettings(page, {
		accessibility_enforcement_mode: 'block',
		accessibility_scan_on_upload: 'false',
	})

	// Without the UI: the server holds the publication back on its own.
	const direct = await page.request.post(`${APP}/api/agendas/${meetingId}/publish`, {
		headers: await writeHeaders(page),
	})
	expect(direct.status()).toBe(409)
	const refusal = await direct.json()
	expect(refusal.error).toBe('accessibility-gate')
	expect(refusal.mode).toBe('block')
	expect(JSON.stringify(refusal.documents)).toContain(SCANNED)

	await page.goto(`${APP}/meetings/${meetingId}`)
	const agendaTab = page.getByRole('tab', { name: 'Agenda' })
	if (await agendaTab.isVisible().catch(() => false)) {
		await agendaTab.click()
	}
	await page.getByTestId('agenda-publish').click()

	const dialog = page.getByTestId('accessibility-gate-dialog')
	await expect(dialog).toBeVisible()
	await expect(
		dialog.getByTestId('accessibility-gate-document').filter({ hasText: SCANNED }),
	).toBeVisible()
	const confirm = dialog.getByTestId('accessibility-gate-confirm')
	await expect(confirm).toBeDisabled()

	await dialog.getByTestId('accessibility-gate-reason').locator('textarea').fill(
		'Wettelijke termijn; toegankelijke versie volgt binnen een week',
	)
	await expect(confirm).toBeEnabled()
	await confirm.click()
	await expect(dialog).toHaveCount(0)
	await expect(page.getByTestId('agenda-published-on')).toBeVisible({ timeout: 15_000 })
})

// @e2e document-accessibility-check::req-003-the-dialog-is-keyboard-operable
test('the gate dialog closes with Escape without publishing', async ({ page }) => {
	await saveSettings(page, {
		accessibility_enforcement_mode: 'warn',
		accessibility_scan_on_upload: 'false',
	})
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag} Commissievergadering toegankelijkheid`,
		meetingType: 'regular',
		scheduledDate: '2026-12-16T19:30:00Z',
	})
	const otherMeeting = meeting.id ?? meeting['@self']?.id
	const item = await createObject(page, ledger, 'agenda-item', {
		title: `${tag} Kaart Lindelaan`,
		itemType: 'decision',
		orderNumber: 1,
		meeting: otherMeeting,
	})
	const otherItem = item.id ?? item['@self']?.id
	const stored = await page.request.post(`${FILES}/${otherItem}/files`, {
		headers: await writeHeaders(page),
		data: { name: SCANNED, content: fixture(SCANNED) },
	})
	expect(stored.status(), await stored.text()).toBeLessThan(300)

	await page.goto(`${APP}/meetings/${otherMeeting}`)
	const agendaTab = page.getByRole('tab', { name: 'Agenda' })
	if (await agendaTab.isVisible().catch(() => false)) {
		await agendaTab.click()
	}
	await page.getByTestId('agenda-publish').click()
	const dialog = page.getByTestId('accessibility-gate-dialog')
	await expect(dialog).toBeVisible()
	// Warn mode: acknowledging is enough, no reason field.
	await expect(dialog.getByTestId('accessibility-gate-reason')).toHaveCount(0)
	await expect(dialog.getByTestId('accessibility-gate-confirm')).toBeEnabled()

	await page.keyboard.press('Escape')
	await expect(dialog).toHaveCount(0)
	await expect(page.getByTestId('agenda-published-on')).toHaveCount(0)
})

// @e2e document-accessibility-check::req-005-aggregate-report-per-body-and-period
test('the accessibility report shows the counts and exports them as CSV', async ({ page }) => {
	await page.goto(`${APP}/reports/accessibility`)
	await expect(page.getByText('Passed').first()).toBeVisible()
	await expect(page.getByText('Failed').first()).toBeVisible()
	await expect(page.getByText('Not checked').first()).toBeVisible()

	const exporter = page.getByTestId('accessibility-report-export')
	await expect(exporter).toBeVisible()
	const [download] = await Promise.all([
		page.waitForEvent('download'),
		exporter.getByTestId('accessibility-report-export-button').click(),
	])
	expect(download.suggestedFilename()).toMatch(/\.csv$/)
	const csv = readFileSync(await download.path(), 'utf8')
	expect(csv.toLowerCase()).toContain('heuristic')
})
