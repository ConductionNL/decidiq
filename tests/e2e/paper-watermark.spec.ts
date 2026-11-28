// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e: papers of a closed session carry the reader's name
 * (agenda-paper-watermark, REQ-WMK-001 to REQ-WMK-003).
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * `PaperWatermarkPolicyTest`, `PaperStamperTest` and `PaperDeliveryTest` pin
 * the decision to stamp and the call to filinq against doubles. Only a real
 * instance shows that a paper opened through
 * `GET /api/papers/{schema}/{objectId}/{fileId}/view` really comes back as a
 * PDF whose text layer holds the reader's display name, the date and the
 * meeting's watermark text, that a switched-off meeting serves the paper
 * unstamped, and that the meeting package a member assembles holds stamped
 * copies in that member's own Files. The text layer is read with pdfjs-dist,
 * so the assertion is on what a reader sees, not on response headers.
 *
 * WITH AND WITHOUT FILINQ. When filinq's stamping service is not installed the
 * instance must refuse a paper that has to be stamped (503 with the reason)
 * and send no file; that scenario runs then, and the stamping ones skip with
 * the reason. With filinq installed it is the other way around.
 *
 * WHAT IT CREATES AND REMOVES. One governance body, a closed and a public
 * meeting, an agenda item on each with one fixture paper, a confidentiality
 * restriction on the public meeting's item, and one member account, all
 * removed in afterAll. The package folder in the member's Files goes with the
 * account.
 *
 * Written, not yet run: it needs a live instance.
 *
 * @spec openspec/changes/archive/2026-11-24-agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-001-a-meeting-says-whether-its-papers-are-watermarked
 * @spec openspec/changes/archive/2026-11-24-agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-002-a-paper-opened-through-decidiq-carries-the-readers-name-and-the-date
 * @spec openspec/changes/archive/2026-11-24-agenda-paper-watermark/specs/agenda-paper-watermark/spec.md#requirement-req-wmk-003-the-meeting-package-is-stamped-for-the-person-who-takes-it
 */

import type { APIRequestContext, Page } from '@playwright/test'
import type { Actor } from './support/api-actors.ts'

import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import * as path from 'node:path'
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs'
import { BASE_URL as BASE } from './base-url.ts'
import {
	adminActor,
	disposeActors,
	newRunId,
	provisionAccount,
	removeAccounts,
} from './support/api-actors.ts'
import { cleanupAll, createObject, listObjects, newLedger, objId, writeHeaders } from './workflows/governance-fixture.ts'

const ledger = newLedger()
const run = newRunId()
const tag = `e2e-${ledger.runId}`
const memberUid = `wmk-${run}`.slice(0, 60)

const APP = `${BASE}/index.php/apps/decidiq`
const FILES = `${BASE}/index.php/apps/openregister/api/objects/decidiq/agenda-item`
const PAPER = 'tagged-paper.pdf'
const NO_SERVICE = 'can only be shown with a watermark'

let admin: Actor | undefined
let member: Actor | undefined
let memberName = ''
let closedMeeting = ''
let closedItem = ''
let closedPaper = 0
let bodyId = ''
let publicMeeting = ''
let restrictedItem = ''
let restrictedPaper = 0
let stamping = false

/**
 * The bytes of the fixture paper, as text (the fixture is plain ASCII).
 *
 * @return The PDF source.
 */
function fixture(): string {
	return readFileSync(path.resolve(__dirname, 'fixtures', PAPER), 'latin1')
}

/**
 * Attach the fixture paper to an agenda item through OpenRegister's files API.
 *
 * @param page   A page with the administrator's session.
 * @param itemId The agenda item.
 * @return The Nextcloud file id.
 */
async function attach(page: Page, itemId: string): Promise<number> {
	const resp = await page.request.post(`${FILES}/${itemId}/files`, {
		headers: await writeHeaders(page),
		data: { name: PAPER, content: fixture() },
	})
	expect(resp.status(), await resp.text()).toBeLessThan(300)
	const body = await resp.json()
	return Number(body?.id ?? body?.fileId ?? 0)
}

/**
 * The text of every page of a PDF, joined.
 *
 * @param bytes The PDF.
 * @return The text layer.
 */
async function pdfText(bytes: Buffer): Promise<string> {
	const pdf = await getDocument({ data: new Uint8Array(bytes) }).promise
	const pages: string[] = []
	for (let n = 1; n <= pdf.numPages; n++) {
		const content = await (await pdf.getPage(n)).getTextContent()
		pages.push(content.items.map((item: any) => ('str' in item ? item.str : '')).join(' '))
	}

	return pages.join('\n')
}

/**
 * Open a paper through decidiq as an actor.
 *
 * @param ctx    The actor's request context.
 * @param itemId The agenda item.
 * @param fileId The paper.
 * @return The response.
 */
function view(ctx: APIRequestContext, itemId: string, fileId: number) {
	return ctx.get(`${APP}/api/papers/agenda-item/${itemId}/${fileId}/view`)
}

test.describe('Papers of a closed session carry the reader\'s name', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(120_000)

	test.beforeAll(async ({ browser, playwright }) => {
		const page = await browser.newPage()
		admin = await adminActor(playwright)
		member = await provisionAccount(playwright, admin, memberUid, ['decidesk-members'])
		memberName = memberUid

		const body = await createObject(page, ledger, 'governance-body', { name: `${tag}-wmk-body`, bodyType: 'legislative' })
		bodyId = objId(body)
		const meeting = await createObject(page, ledger, 'meeting', {
			title: `${tag} Besloten vergadering grondzaken`,
			meetingType: 'regular',
			meetingMode: 'in-person',
			scheduledDate: '2026-12-09T19:30:00Z',
			governanceBody: bodyId,
			isPublic: false,
			watermarkText: 'Vertrouwelijk',
			lifecycle: 'scheduled',
		})
		closedMeeting = objId(meeting)
		closedItem = objId(await createObject(page, ledger, 'agenda-item', {
			title: `${tag} Grondtransactie Noord`, itemType: 'decision', orderNumber: 1, meeting: closedMeeting,
		}))
		closedPaper = await attach(page, closedItem)

		const open = await createObject(page, ledger, 'meeting', {
			title: `${tag} Openbare raadsvergadering`,
			meetingType: 'regular',
			meetingMode: 'in-person',
			scheduledDate: '2026-12-10T19:30:00Z',
			governanceBody: bodyId,
			isPublic: true,
			watermarkPapers: 'off',
			lifecycle: 'scheduled',
		})
		publicMeeting = objId(open)
		restrictedItem = objId(await createObject(page, ledger, 'agenda-item', {
			title: `${tag} Grondtransactie Zuid`, itemType: 'decision', orderNumber: 1, meeting: publicMeeting,
		}))
		restrictedPaper = await attach(page, restrictedItem)

		// Whether filinq's stamping service is installed decides which half runs.
		const probe = await view(admin.ctx, closedItem, closedPaper)
		stamping = probe.status() === 200
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await cleanupAll(page, ledger)
		await page.close()
		if (admin !== undefined) {
			await removeAccounts(admin, [memberUid])
		}
		await disposeActors(member, admin)
	})

	test('a closed session stamps its papers by default with the reader\'s name, the date and the text', async () => {
		test.skip(!stamping, 'filinq\'s stamping service is not installed on this instance')

		const resp = await view(member!.ctx, closedItem, closedPaper)
		expect(resp.status(), await resp.text()).toBe(200)
		expect(resp.headers()['content-type']).toContain('application/pdf')
		const text = await pdfText(await resp.body())
		expect(text).toContain(memberName)
		expect(text).toContain('Vertrouwelijk')
		expect(text).toMatch(/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/)
	})

	test('a meeting switched off serves the restricted item\'s paper unstamped', async ({ page }) => {
		test.skip(!stamping, 'filinq\'s stamping service is not installed on this instance')

		const ground = (await listObjects(page, 'confidentiality-ground', 100)).find((row) => row.active !== false)
		test.skip(ground === undefined, 'no confidentiality ground is configured on this instance')
		await createObject(page, ledger, 'confidentiality-restriction', {
			scope: 'item',
			targetAgendaItem: restrictedItem,
			ground: objId(ground),
			imposedBy: 'chair',
			imposedByBody: bodyId,
			imposedAt: new Date().toISOString(),
			lifecycle: 'imposed',
		})

		const resp = await view(admin!.ctx, restrictedItem, restrictedPaper)
		expect(resp.status(), await resp.text()).toBe(200)
		const text = await pdfText(await resp.body())
		expect(text).not.toContain(admin!.uid)
	})

	test('without a stamping service the paper is refused with the reason and no file is sent', async () => {
		test.skip(stamping, 'filinq\'s stamping service is installed on this instance')

		const resp = await view(member!.ctx, closedItem, closedPaper)
		expect(resp.status()).toBe(503)
		expect(resp.headers()['content-type'] ?? '').not.toContain('application/pdf')
		expect(await resp.text()).toContain(NO_SERVICE)
	})

	test('a reader who may not read the item gets 404, not the paper', async ({ playwright }) => {
		const outsider = await provisionAccount(playwright, admin!, `${memberUid}-out`.slice(0, 60))
		try {
			const resp = await view(outsider.ctx, closedItem, closedPaper)
			expect([403, 404]).toContain(resp.status())
		} finally {
			await removeAccounts(admin!, [outsider.uid])
			await disposeActors(outsider)
		}
	})

	test('the meeting package a member takes holds papers stamped for that member', async () => {
		test.skip(!stamping, 'filinq\'s stamping service is not installed on this instance')

		const assembled = await member!.ctx.post(`${APP}/api/meetings/${closedMeeting}/package`, { data: {} })
		expect(assembled.status(), await assembled.text()).toBe(200)
		const result = await assembled.json()
		expect(result.files).toBeGreaterThan(0)

		const listing = await member!.ctx.fetch(`${BASE}/remote.php/dav/files/${memberUid}/${result.path}`, {
			method: 'PROPFIND',
			headers: { Depth: 'infinity' },
		})
		expect(listing.status()).toBe(207)
		const hrefs = [...(await listing.text()).matchAll(/<d:href>([^<]+\.pdf)<\/d:href>/g)].map((m) => m[1])
		expect(hrefs.length).toBeGreaterThan(0)
		for (const href of hrefs) {
			const file = await member!.ctx.get(`${BASE}${href}`)
			expect(file.status()).toBe(200)
			expect(await pdfText(await file.body())).toContain(memberName)
		}
	})
})
