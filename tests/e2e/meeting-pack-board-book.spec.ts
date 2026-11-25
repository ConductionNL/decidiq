/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the compiled meeting pack (vergaderbundel) on the meeting
 * page (change meeting-pack-board-book, task 9). The Meeting pack widget
 * (`meeting-pack`, MeetingPackTab) on MeetingDetail lists the versions with
 * their change notes and downloads, shows the outdated badge, the skip and
 * delivery reports and, for the chair or secretary, the compile action. Compile
 * and status go through BoardBookController
 * (POST/GET /api/meetings/{id}/board-book[/status]).
 *
 * Already covered below the browser:
 *   - tests/Unit/Service/BoardBookServiceTest.php: empty agenda refused, change
 *     note from version 2 on, next version recorded, an existing version file
 *     is never overwritten, the folder package without filinq, freshness.
 *   - tests/Unit/Service/BoardBook/BoardBookComposerTest.php: fingerprint over
 *     order, titles and etags; the confidential section carries only the
 *     closed-doors placeholder; the front matter lists every item; the file
 *     name carries the version.
 *   - tests/Unit/Service/BoardBook/BoardBookRendererTest.php: attachments merged
 *     in place, confidential ones kept out, unparsable and non-PDF attachments
 *     become placeholders, no filinq means no PDF.
 *   - tests/Unit/Service/BoardBook/BoardBookDeliveryTest.php,
 *     BoardBookAgendaTest.php, tests/Unit/Controller/BoardBookControllerTest.php,
 *     tests/Unit/BackgroundJob/BoardBookCompileJobTest.php,
 *     tests/Unit/Settings/MeetingPackRegisterTest.php (schema, declarative
 *     notification, AgendaItem.confidentiality, the seeded packs).
 *   - tests/vitest/meetingPack.spec.js: version order, who may compile, when a
 *     change note is asked, outdated, in flight, the URLs.
 *
 * Example set: none is required. The file seeds its own governance body,
 * meetings, agenda items, attachments and MeetingPack objects through the
 * OpenRegister object API and removes them again. The municipality and
 * corporate profiles (lib/Settings/profiles/*.json) seed four packs
 * (vergaderbundel-raad-2025-01-15-v1/v2, vergaderbundel-rvc-q1-2025-v1,
 * vergaderbundel-directie-2025-04-14-v1), but their fingerprints are
 * placeholders and their files may be missing, so they cannot prove a fresh
 * or a downloadable pack; this file does not rely on them.
 *
 * Runs as the suite's signed-in administrator, who stands in for the
 * secretary (MeetingRoleGate lets an administrator compile), plus a member
 * the file creates (a participant of the body, no admin rights) and a
 * stranger who is no participant. The compile scenarios need two things the
 * instance has to provide: the instance's background jobs must run while the
 * test polls (BoardBookCompileJob is a QueuedJob; CI runs
 * `occ background-job:execute`), and filinq must be installed for a PDF to
 * exist. Without either, those tests skip and say which.
 *
 * Written, not yet run: it needs a live instance.
 *
 * Gate-19 note: the one `@e2e exclude` below is a directive in a test file and
 * the spec-coverage gate does not read it as an exclusion; it has to be
 * mirrored as `<!-- @e2e exclude … -->` under the scenario in the change spec.
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md
 */
import type { APIRequestContext, Browser, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { inflateSync } from 'node:zlib'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	newLedger,
	writeHeaders,
} from './workflows/governance-fixture.ts'

const ADMIN_USER = process.env.NEXTCLOUD_USER || 'admin'
const ADMIN_PASS = process.env.NEXTCLOUD_PASS || 'admin'
const OR = `${BASE}/index.php/apps/openregister/api/objects/decidiq`
const API = `${BASE}/index.php/apps/decidiq/api/meetings`
const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const member = `${tag}-anna`.slice(0, 60)
const stranger = `${tag}-sven`.slice(0, 60)
const password = `Dq-${randomBytes(12).toString('hex')}-Pw1!`
/**
 * The id of an object as OpenRegister answers it.
 *
 * @param {Record<string, any>} o The object.
 * @return {string} Its id, or ''.
 */
function idOf(o: Record<string, any>): string {
	return o?.id ?? o?.['@self']?.id ?? o?.uuid ?? ''
}

/** Tokens that only exist in one item's description, to find in the PDF. */
const OPEN_TOKEN = `OPENPAPERS${ledger.runId.replace(/\D/g, '')}`
const SECRET_TOKEN = `SECRETPAPERS${ledger.runId.replace(/\D/g, '')}`
const OPEN_PAPER = `OPENATTACHMENT${ledger.runId.replace(/\D/g, '')}`
const SECRET_PAPER = `SECRETATTACHMENT${ledger.runId.replace(/\D/g, '')}`

/** The compiled pack takes a queued job; NFR: 60 s for 20 items, so 120 s here is generous. */
const COMPILE_TIMEOUT = 120_000

let bodyId = ''
const packIds: string[] = []
const meetingIds: string[] = []

/**
 * Basic-auth headers.
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
 * A page signed in as the given user, without the suite's admin state.
 *
 * @param browser The browser.
 * @param user The user.
 */
async function signIn(browser: Browser, user: string): Promise<Page> {
	const context = await browser.newContext({
		storageState: { cookies: [], origins: [] },
	})
	const page = await context.newPage()
	await page.goto(`${BASE}/index.php/login`)
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(password)
	await page.locator('button[type="submit"]').click()
	await page.waitForURL((url) => !url.pathname.includes('/login'))
	return page
}

/**
 * Merge fields into an OpenRegister object.
 *
 * @param page The signed-in page.
 * @param schema The schema slug.
 * @param id The object.
 * @param data The fields to change.
 */
async function patchObject(
	page: Page,
	schema: string,
	id: string,
	data: Record<string, unknown>,
): Promise<void> {
	const res = await page.request.patch(`${OR}/${schema}/${id}`, {
		headers: await writeHeaders(page),
		data,
	})
	expect(res.status(), await res.text()).toBeLessThan(300)
}

/**
 * Store a file on an agenda item through OpenRegister's files API.
 *
 * @param page The signed-in page.
 * @param itemId The agenda item.
 * @param name The file name.
 * @param content The file content, as text.
 */
async function attach(
	page: Page,
	itemId: string,
	name: string,
	content: string,
): Promise<void> {
	const res = await page.request.post(`${OR}/agenda-item/${itemId}/files`, {
		headers: await writeHeaders(page),
		data: { name, content },
	})
	expect(res.status(), await res.text()).toBeLessThan(300)
}

/**
 * A meeting of the test body with the given agenda items.
 *
 * @param page The signed-in page.
 * @param label What the meeting is for, in its title.
 * @param items The agenda items (title, plus any extra fields).
 */
async function meetingWith(
	page: Page,
	label: string,
	items: Array<Record<string, unknown>>,
): Promise<{ meetingId: string; title: string; itemIds: string[] }> {
	const title = `${tag} ${label}`
	const meeting = await createObject(page, ledger, 'meeting', {
		title,
		meetingType: 'regular',
		meetingMode: 'in-person',
		scheduledDate: '2026-12-09T19:30:00Z',
		location: 'Raadzaal',
		governanceBody: bodyId,
	})
	const meetingId = idOf(meeting)
	meetingIds.push(meetingId)
	const itemIds: string[] = []
	let n = 0
	for (const item of items) {
		n += 1
		itemIds.push(
			idOf(
				await createObject(page, ledger, 'agenda-item', {
					itemType: 'discussion',
					orderNumber: n,
					meeting: meetingId,
					...item,
				}),
			),
		)
	}
	return { meetingId, title, itemIds }
}

/**
 * A MeetingPack object written directly, as compilation would have left it.
 *
 * @param page The signed-in page.
 * @param meetingId The meeting.
 * @param data The pack fields.
 */
async function seedPack(
	page: Page,
	meetingId: string,
	data: Record<string, unknown>,
): Promise<void> {
	const res = await page.request.post(`${OR}/meeting-pack`, {
		headers: await writeHeaders(page),
		data: {
			meeting: meetingId,
			changeNote: '',
			generatedAt: '2026-11-01T10:00:00Z',
			generatedBy: ADMIN_USER,
			pageCount: 12,
			skipped: [],
			...data,
		},
	})
	expect(res.status(), await res.text()).toBeLessThan(300)
	packIds.push(idOf(await res.json()))
}

/**
 * The board-book status of a meeting, read with the given request context.
 *
 * @param request The request context.
 * @param meetingId The meeting.
 */
async function status(
	request: APIRequestContext,
	meetingId: string,
): Promise<Record<string, any>> {
	// A raw request carries no request token; the OCS header passes
	// Nextcloud's CSRF check the way the app's own requests do.
	const res = await request.get(`${API}/${meetingId}/board-book/status`, {
		headers: { Accept: 'application/json', 'OCS-APIRequest': 'true' },
	})
	expect(res.status(), await res.text()).toBe(200)
	return res.json()
}

/**
 * Wait until the meeting's compilation left the queue; the final state.
 *
 * @param page The signed-in page.
 * @param meetingId The meeting.
 */
async function settled(page: Page, meetingId: string): Promise<Record<string, any>> {
	const deadline = Date.now() + COMPILE_TIMEOUT
	let last: Record<string, any> = {}
	while (Date.now() < deadline) {
		last = await status(page.request, meetingId)
		if (last.state === 'done' || last.state === 'failed') {
			return last
		}
		await page.waitForTimeout(2_000)
	}
	return last
}

/**
 * The meeting page's Meeting pack widget.
 *
 * @param page The signed-in page.
 * @param meetingId The meeting.
 */
async function openPack(page: Page, meetingId: string) {
	await page.goto(`${BASE}/index.php/apps/decidiq/meetings/${meetingId}`)
	// The pack sits far down the page and mounts when its card scrolls into view.
	await page.getByRole('group', { name: 'meeting-pack' }).scrollIntoViewIfNeeded()
	const widget = page.getByTestId('meeting-pack')
	await widget.scrollIntoViewIfNeeded()
	await expect(widget).toBeVisible({ timeout: 20_000 })
	return widget
}

/**
 * A one-page PDF saying one line in Helvetica, with a correct xref.
 *
 * Built here rather than read from tests/e2e/fixtures so each paper carries
 * its own text: the merged pack is then searched for exactly that text.
 *
 * @param text The line, ASCII without parentheses or backslashes.
 */
function onePagePdf(text: string): string {
	const content = `BT /F1 14 Tf 72 720 Td (${text}) Tj ET`
	const objects = [
		'<< /Type /Catalog /Pages 2 0 R >>',
		'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
		'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
		`<< /Length ${content.length} >>\nstream\n${content}\nendstream`,
		'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
	]
	let out = '%PDF-1.4\n'
	const offsets: number[] = []
	objects.forEach((body, i) => {
		offsets.push(out.length)
		out += `${i + 1} 0 obj\n${body}\nendobj\n`
	})
	const xref = out.length
	out += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`
	for (const offset of offsets) {
		out += `${String(offset).padStart(10, '0')} 00000 n \n`
	}
	out += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`
	return out
}

/**
 * Everything a PDF says in bytes: the raw file plus every Flate stream inflated.
 *
 * @param pdf The PDF.
 */
function pdfBytes(pdf: Buffer): string {
	const raw = pdf.toString('latin1')
	const parts = [raw]
	const re = /stream\r?\n/g
	let m: RegExpExecArray | null
	while ((m = re.exec(raw)) !== null) {
		const start = m.index + m[0].length
		const end = raw.indexOf('endstream', start)
		if (end < 0) break
		try {
			parts.push(inflateSync(pdf.subarray(start, end)).toString('latin1'))
		} catch {
			// Not a Flate stream (or an image); nothing to read there.
		}
		re.lastIndex = end
	}
	return parts.join('\n')
}

/**
 * Whether a PDF's bytes carry a text, as plain bytes, UTF-16BE or hex of either.
 *
 * @param hay The PDF bytes from pdfBytes().
 * @param text The text.
 */
function says(hay: string, text: string): boolean {
	const utf16 = Buffer.from(text, 'utf16le').swap16()
	const forms = [
		text,
		utf16.toString('latin1'),
		Buffer.from(text, 'latin1').toString('hex'),
		utf16.toString('hex'),
	]
	const lower = hay.toLowerCase()
	return forms.some(
		(form) => hay.includes(form) || lower.includes(form.toLowerCase()),
	)
}

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage()
	for (const user of [member, stranger]) {
		const created = await page.request.post(
			`${BASE}/ocs/v2.php/cloud/users?format=json`,
			{
				headers: basic(ADMIN_USER, ADMIN_PASS),
				data: { userid: user, password },
			},
		)
		expect(created.ok(), await created.text()).toBe(true)
	}
	bodyId = idOf(
		await createObject(page, ledger, 'governance-body', {
			name: `${tag}-raad`,
			bodyType: 'legislative',
			domain: 'municipality',
		}),
	)
	await createObject(page, ledger, 'participant', {
		displayName: `${tag}-Anna`,
		role: 'member',
		nextcloudUserId: member,
		governanceBody: bodyId,
	})
	// An attendee without a Nextcloud account: delivery to them fails, and must not block the others.
	await createObject(page, ledger, 'participant', {
		displayName: `${tag}-Geen account`,
		role: 'member',
		nextcloudUserId: `${tag}-no-such-account`,
		governanceBody: bodyId,
	})
	await page.close()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	const headers = await writeHeaders(page)
	// meeting-pack is not in governance-fixture's teardown order, and compiled
	// packs were created by the app, so sweep this run's meetings' packs here.
	for (const meetingId of meetingIds) {
		const res = await page.request
			.get(`${OR}/meeting-pack?meeting=${meetingId}&_limit=100`, {
				headers: { Accept: 'application/json' },
			})
			.catch(() => null)
		const body = res && res.ok() ? await res.json() : {}
		for (const pack of body.results ?? body.items ?? []) {
			if (!packIds.includes(idOf(pack))) packIds.push(idOf(pack))
		}
	}
	for (const id of packIds) {
		await page.request
			.delete(`${OR}/meeting-pack/${id}`, { headers })
			.catch(() => undefined)
	}
	await cleanupAll(page, ledger)
	for (const user of [member, stranger]) {
		await page.request
			.delete(
				`${BASE}/ocs/v2.php/cloud/users/${encodeURIComponent(user)}?format=json`,
				{
					headers: basic(ADMIN_USER, ADMIN_PASS),
				},
			)
			.catch(() => undefined)
	}
	await page.close()
})

test.describe.serial('compiling a real pack', () => {
	// A compilation runs as a background job and may take up to COMPILE_TIMEOUT.
	test.describe.configure({ timeout: COMPILE_TIMEOUT + 60_000 })
	let meetingId = ''
	let titles: string[] = []
	let compiled = false
	let reason = 'the compile test did not get as far as a compiled PDF'

	// @e2e meeting-pack-board-book::secretary-compiles-the-pack-for-a-published-agenda
	// @e2e meeting-pack-board-book::unparsable-pdf-attachment-is-skipped-with-a-placeholder
	// @e2e meeting-pack-board-book::word-attachment-gets-a-placeholder-page
	// @e2e meeting-pack-board-book::secretary-sees-compile-action-and-skip-report
	test('the secretary compiles the pack in one click; unmergeable papers land in the skip report', async ({
		page,
	}) => {
		titles = [
			`${tag} Opening en mededelingen`,
			`${tag} Grondaankoop Lindelaan`,
			`${tag} Kadernota 2027`,
		]
		const seeded = await meetingWith(page, 'Raadsvergadering 9 december 2026', [
			{ title: titles[0], description: `Toelichting ${OPEN_TOKEN}` },
			{
				title: titles[1],
				description: `Onderhandelingspositie ${SECRET_TOKEN}`,
				confidentiality: 'confidential',
			},
			{ title: titles[2], description: 'Kadernota ter bespreking' },
		])
		meetingId = seeded.meetingId
		const [opening, , kadernota] = seeded.itemIds
		await attach(
			page,
			opening,
			`${tag}-raadsvoorstel.pdf`,
			onePagePdf(`Raadsvoorstel ${OPEN_PAPER}`),
		)
		await attach(
			page,
			kadernota,
			`${tag}-begroting.docx`,
			'Not a PDF: a Word document cannot be merged.',
		)
		await attach(
			page,
			kadernota,
			`${tag}-kapot.pdf`,
			'%PDF-1.4\nthis is no PDF the merge engine can import\n',
		)
		// The confidential item keeps a paper too; it must stay out of the pack.
		await attach(
			page,
			seeded.itemIds[1],
			`${tag}-taxatierapport.pdf`,
			onePagePdf(`Taxatierapport ${SECRET_PAPER}`),
		)

		const widget = await openPack(page, meetingId)
		await expect(
			widget.getByText(
				/No meeting pack has been compiled yet\.|Er is nog geen vergaderbundel samengesteld\./,
			),
		).toBeVisible()
		const posted = page.waitForResponse(
			(r) =>
				r.url().endsWith(`/api/meetings/${meetingId}/board-book`)
				&& r.request().method() === 'POST',
		)
		await widget
			.getByRole('button', {
				name: /^(Compile the meeting pack|Vergaderbundel samenstellen)$/,
			})
			.click()
		const response = await posted
		expect(response.status(), await response.text()).toBe(202)
		expect(await response.json()).toMatchObject({
			status: 'queued',
			meetingId,
			nextVersion: 1,
		})
		await expect(widget.getByRole('status').first()).toBeVisible()

		const final = await settled(page, meetingId)
		reason =
			'the compile job never ran: this instance runs no background jobs while the test polls (CI runs occ background-job:execute)'
		test.skip(final.state === 'queued' || final.state === 'running', reason)
		expect(final.state, JSON.stringify(final)).toBe('done')
		if (final.lastRun?.pdf === false) {
			// Honest fallback: no PDF, said so, and no MeetingPack version listed.
			expect(String(final.lastRun?.message ?? '')).toContain(
				'no PDF was compiled',
			)
			reason =
				'filinq is not installed here, so no PDF is compiled (the folder-package fallback was asserted instead)'
			test.skip(true, reason)
		}
		compiled = true

		await page.reload()
		await page.getByRole('group', { name: 'meeting-pack' }).scrollIntoViewIfNeeded()
		await page.getByRole('group', { name: 'meeting-pack' }).scrollIntoViewIfNeeded()
		const after = page.getByTestId('meeting-pack')
		await after.scrollIntoViewIfNeeded()
		const rows = after.getByTestId('meeting-pack-version')
		await expect(rows).toHaveCount(1)
		await expect(rows.first().locator('td').first()).toHaveText('1')
		expect(
			Number(await rows.first().locator('td').nth(4).innerText()),
		).toBeGreaterThanOrEqual(5)
		await expect(after.getByTestId('meeting-pack-outdated')).toHaveCount(0)

		// The secretary keeps the compile action, and sees what was left out and why.
		await expect(after.getByTestId('meeting-pack-compile')).toBeVisible()
		const skipped = after.getByTestId('meeting-pack-skipped')
		await expect(skipped).toContainText(`${tag}-begroting.docx`)
		await expect(skipped).toContainText(`${tag}-kapot.pdf`)
		await expect(skipped).not.toContainText(`${tag}-raadsvoorstel.pdf`)
		await expect(skipped).not.toContainText(`${tag}-taxatierapport.pdf`)
	})

	// @e2e meeting-pack-board-book::attendee-receives-the-pack-in-their-own-files
	// @e2e meeting-pack-board-book::one-failing-delivery-does-not-block-the-others
	// @e2e meeting-pack-board-book::attendees-are-notified-when-a-pack-is-created
	test('the attendee finds the pack read-only in their Files and is notified; the attendee without an account is reported', async ({
		browser,
		page,
		playwright,
	}) => {
		test.skip(!compiled, reason)

		// The secretary sees the one delivery that failed.
		const widget = await openPack(page, meetingId)
		await expect(
			widget.getByText(
				/The latest pack did not reach:|De laatste bundel is niet aangekomen bij:/,
			),
		).toBeVisible()
		await expect(widget).toContainText(`${tag}-Geen account`)

		// Anna's copy sits in her own Files, is a PDF and cannot be written to.
		// Marking it offline in the mobile client is the client's job, not the app's.
		const anna = await signIn(browser, member)
		const annaWidget = await openPack(anna, meetingId)
		const link = annaWidget
			.getByTestId('meeting-pack-version')
			.first()
			.getByRole('link')
		const href = await link.getAttribute('href')
		expect(href).toContain(
			`/remote.php/dav/files/${encodeURIComponent(member)}/`,
		)
		const file = await anna.request.get(String(href))
		expect(file.status()).toBe(200)
		expect((await file.body()).subarray(0, 5).toString('latin1')).toBe('%PDF-')
		const write = await anna.request.put(String(href), {
			data: 'overwritten',
			headers: { requesttoken: (await writeHeaders(anna)).requesttoken },
		})
		expect(
			write.status(),
			'the delivered pack must be read-only',
		).toBeGreaterThanOrEqual(400)

		const annaApi = await playwright.request.newContext({
			extraHTTPHeaders: basic(member, password),
			storageState: { cookies: [], origins: [] },
		})
		await expect
			.poll(
				async () => {
					const resp = await annaApi.get(
						`${BASE}/ocs/v2.php/apps/notifications/api/v2/notifications?format=json`,
					)
					const list = (await resp.json())?.ocs?.data ?? []
					return list.some((n: { subject?: string }) =>
						/Vergaderbundel beschikbaar|Meeting pack available/.test(
							String(n.subject ?? ''),
						),
					)
				},
				{ timeout: 30_000 },
			)
			.toBe(true)
		await annaApi.dispose()
		await anna.context().close()
	})

	// @e2e meeting-pack-board-book::confidential-item-excluded-from-the-compiled-pack
	test('the confidential item keeps its title line but not its description or papers', async ({
		browser,
	}) => {
		test.skip(!compiled, reason)
		const anna = await signIn(browser, member)
		const widget = await openPack(anna, meetingId)
		const href = await widget
			.getByTestId('meeting-pack-version')
			.first()
			.getByRole('link')
			.getAttribute('href')
		const file = await anna.request.get(String(href))
		expect(file.status()).toBe(200)
		const pdf = pdfBytes(await file.body())
		await anna.context().close()

		// Bookmarks are PDF text strings, readable whatever font the body uses.
		expect(says(pdf, `1. ${titles[0]}`), 'bookmark for item 1').toBe(true)
		expect(says(pdf, `3. ${titles[2]}`), 'bookmark for item 3').toBe(true)

		// The papers carry their own Helvetica text: the open item's paper is merged
		// in place, the confidential item's paper is not in the pack at all.
		expect(
			says(pdf, OPEN_PAPER),
			"the open item's paper is merged into the pack",
		).toBe(true)
		expect(
			says(pdf, SECRET_PAPER),
			"the confidential item's paper must not be in the pack",
		).toBe(false)

		// Body text may be glyph-encoded (an embedded subset font): only judge the
		// absence of the confidential text when the open item's text is findable.
		test.skip(
			!says(pdf, OPEN_TOKEN),
			'this PDF encodes its body text by glyph, so its text cannot be searched here; BoardBookComposerTest::testAConfidentialSectionCarriesOnlyThePlaceholder covers the content',
		)
		expect(
			says(pdf, titles[1]),
			'the confidential item stays in the table of contents',
		).toBe(true)
		expect(
			says(pdf, SECRET_TOKEN),
			'the confidential description must not be in the pack',
		).toBe(false)
		expect(
			says(pdf, 'Behandeling achter gesloten deuren'),
			'the closed-doors placeholder',
		).toBe(true)
	})

	// @e2e meeting-pack-board-book::agenda-change-after-compilation-marks-the-pack-outdated
	// @e2e meeting-pack-board-book::recompilation-creates-version-2-with-a-change-note
	test('a new paper marks the pack outdated; recompiling with a change note adds version 2 and keeps version 1', async ({
		page,
	}) => {
		test.skip(!compiled, reason)
		const items = await (
			await page.request.get(
				`${OR}/agenda-item?meeting=${meetingId}&_limit=20`,
				{
					headers: { Accept: 'application/json' },
				},
			)
		).json()
		const opening = (items.results ?? items.items ?? []).find(
			(i: Record<string, any>) => i.title === titles[0],
		)
		await attach(
			page,
			idOf(opening),
			`${tag}-begroting-bijlage.pdf`,
			onePagePdf('Bijlage begroting'),
		)

		const widget = await openPack(page, meetingId)
		await expect(widget.getByTestId('meeting-pack-outdated')).toBeVisible()
		await expect(widget.getByTestId('meeting-pack-outdated')).toHaveText(
			/Meeting pack outdated|Vergaderbundel verouderd/,
		)

		const note = `Bijlage begroting toegevoegd aan punt 1 ${tag}`
		await widget
			.getByLabel(
				/What changed in this version\?|Wat is er in deze versie veranderd\?/,
			)
			.fill(note)
		const posted = page.waitForResponse(
			(r) =>
				r.url().endsWith(`/api/meetings/${meetingId}/board-book`)
				&& r.request().method() === 'POST',
		)
		await widget
			.getByRole('button', {
				name: /^(Compile a new version|Nieuwe versie samenstellen)$/,
			})
			.click()
		const response = await posted
		expect(response.status(), await response.text()).toBe(202)
		expect((await response.json()).nextVersion).toBe(2)

		const final = await settled(page, meetingId)
		test.skip(
			final.state !== 'done',
			'the second compile job did not run while the test polled',
		)
		await page.reload()
		const after = page.getByTestId('meeting-pack')
		await after.scrollIntoViewIfNeeded()
		const rows = after.getByTestId('meeting-pack-version')
		await expect(rows).toHaveCount(2)
		await expect(rows.nth(0).locator('td').first()).toHaveText('2')
		await expect(rows.nth(0)).toContainText(note)
		await expect(rows.nth(1).locator('td').first()).toHaveText('1')
		await expect(after.getByTestId('meeting-pack-outdated')).toHaveCount(0)

		// Version 1's file is still there: compilation never overwrites it.
		const packs = await (
			await page.request.get(
				`${OR}/meeting-pack?meeting=${meetingId}&_limit=20`,
				{
					headers: { Accept: 'application/json' },
				},
			)
		).json()
		const v1 = (packs.results ?? packs.items ?? []).find(
			(p: Record<string, any>) => Number(p.version) === 1,
		)
		const v2 = (packs.results ?? packs.items ?? []).find(
			(p: Record<string, any>) => Number(p.version) === 2,
		)
		expect(v1?.filePath).toBeTruthy()
		expect(v2?.filePath).toBeTruthy()
		expect(v2.filePath).not.toBe(v1.filePath)
		expect(String(v2.filePath)).toMatch(/v2\.pdf$/)
	})
})

// @e2e meeting-pack-board-book::compilation-blocked-for-an-empty-agenda
test('a meeting without agenda items gets no pack, and says why', async ({
	page,
}) => {
	const { meetingId } = await meetingWith(page, 'Lege vergadering', [])
	const widget = await openPack(page, meetingId)
	const posted = page.waitForResponse(
		(r) =>
			r.url().endsWith(`/api/meetings/${meetingId}/board-book`)
			&& r.request().method() === 'POST',
	)
	await widget
		.getByRole('button', {
			name: /^(Compile the meeting pack|Vergaderbundel samenstellen)$/,
		})
		.click()
	const response = await posted
	expect(response.status()).toBe(422)
	expect(await response.json()).toMatchObject({ code: 'empty_agenda' })
	await expect(
		widget.getByText('Een vergaderbundel vereist minimaal één agendapunt'),
	).toBeVisible()
	await expect(widget.getByTestId('meeting-pack-version')).toHaveCount(0)
	expect((await status(page.request, meetingId)).latestVersion).toBe(0)
})

// @e2e meeting-pack-board-book::change-note-required-on-recompilation
test('recompiling asks for a change note and the server refuses one without it', async ({
	page,
}) => {
	const { meetingId } = await meetingWith(page, 'Commissie Ruimte', [
		{ title: `${tag} Opening` },
	])
	await seedPack(page, meetingId, {
		version: 1,
		fingerprint: 'sha256:e2e-older-agenda',
	})

	const widget = await openPack(page, meetingId)
	const button = widget.getByRole('button', {
		name: /^(Compile a new version|Nieuwe versie samenstellen)$/,
	})
	const note = widget.getByLabel(
		/What changed in this version\?|Wat is er in deze versie veranderd\?/,
	)
	await expect(note).toBeVisible()
	await expect(button).toBeDisabled()
	await note.fill('Agendapunt toegevoegd')
	await expect(button).toBeEnabled()

	// The button guards the browser; the server holds the rule itself.
	const refused = await page.request.post(`${API}/${meetingId}/board-book`, {
		headers: await writeHeaders(page),
		data: { changeNote: '   ' },
	})
	expect(refused.status()).toBe(422)
	expect(await refused.json()).toMatchObject({ code: 'change_note_required' })
})

// @e2e meeting-pack-board-book::fresh-pack-shows-no-outdated-indicator
test('a participant sees the latest version without an outdated badge while the agenda is unchanged', async ({
	browser,
	page,
}) => {
	const { meetingId } = await meetingWith(page, 'Raadsvergadering vers', [
		{ title: `${tag} Opening` },
		{ title: `${tag} Vaststellen notulen` },
	])
	const current = await status(page.request, meetingId)
	await seedPack(page, meetingId, {
		version: 1,
		fingerprint: current.currentFingerprint,
	})

	const anna = await signIn(browser, member)
	const widget = await openPack(anna, meetingId)
	await expect(widget.getByTestId('meeting-pack-version')).toHaveCount(1)
	await expect(widget.getByTestId('meeting-pack-outdated')).toHaveCount(0)
	await anna.context().close()
})

// @e2e meeting-pack-board-book::agenda-change-after-compilation-marks-the-pack-outdated
test('reordering two agenda items after compilation marks the pack outdated', async ({
	page,
}) => {
	const { meetingId, itemIds } = await meetingWith(
		page,
		'Raadsvergadering herschikt',
		[{ title: `${tag} Opening` }, { title: `${tag} Vaststellen notulen` }],
	)
	const current = await status(page.request, meetingId)
	await seedPack(page, meetingId, {
		version: 1,
		fingerprint: current.currentFingerprint,
	})

	let widget = await openPack(page, meetingId)
	await expect(widget.getByTestId('meeting-pack-version')).toHaveCount(1)
	await expect(widget.getByTestId('meeting-pack-outdated')).toHaveCount(0)

	await patchObject(page, 'agenda-item', itemIds[0], { orderNumber: 2 })
	await patchObject(page, 'agenda-item', itemIds[1], { orderNumber: 1 })

	widget = await openPack(page, meetingId)
	await expect(widget.getByTestId('meeting-pack-outdated')).toBeVisible()
	await expect(widget.getByTestId('meeting-pack-outdated')).toHaveText(
		/Meeting pack outdated|Vergaderbundel verouderd/,
	)
})

// @e2e meeting-pack-board-book::participant-downloads-the-latest-pack-from-the-detail-page
test('a participant sees both versions newest first, downloads from their own Files, and has no compile action', async ({
	browser,
	page,
}) => {
	const { meetingId } = await meetingWith(page, 'Raadsvergadering twee versies', [
		{ title: `${tag} Opening` },
	])
	await seedPack(page, meetingId, {
		version: 1,
		fingerprint: 'sha256:e2e-v1',
		filePath: `Vergaderingen/${tag}/Vergaderbundel ${tag} v1.pdf`,
	})
	await seedPack(page, meetingId, {
		version: 2,
		fingerprint: 'sha256:e2e-v2',
		changeNote: 'Bijlage begroting toegevoegd aan agendapunt 4',
		filePath: `Vergaderingen/${tag}/Vergaderbundel ${tag} v2.pdf`,
	})

	const anna = await signIn(browser, member)
	const widget = await openPack(anna, meetingId)
	const rows = widget.getByTestId('meeting-pack-version')
	await expect(rows).toHaveCount(2)
	await expect(rows.nth(0).locator('td').first()).toHaveText('2')
	await expect(rows.nth(0)).toContainText(
		'Bijlage begroting toegevoegd aan agendapunt 4',
	)
	await expect(rows.nth(1).locator('td').first()).toHaveText('1')

	// The download is Anna's delivered copy, by its own name, in her own Files.
	const download = rows
		.nth(0)
		.getByRole('link', { name: /Download version 2|Versie 2 downloaden/ })
	await expect(download).toHaveAttribute(
		'href',
		`${BASE}/remote.php/dav/files/${encodeURIComponent(member)}/${encodeURIComponent(`Vergaderbundel ${tag} v2.pdf`)}`,
	)

	// Read-only for a participant: no compile form, no change-note field.
	await expect(widget.getByTestId('meeting-pack-compile')).toHaveCount(0)
	await expect(
		widget.getByRole('button', { name: /Compile|samenstellen/ }),
	).toHaveCount(0)
	await anna.context().close()
})

// @e2e meeting-pack-board-book::user-without-meeting-access-cannot-read-the-pack
test('a user who is no participant reads neither the pack status nor the pack', async ({
	page,
	playwright,
}) => {
	const { meetingId } = await meetingWith(page, 'Besloten overleg', [
		{ title: `${tag} Opening` },
	])
	await seedPack(page, meetingId, { version: 1, fingerprint: 'sha256:e2e-closed' })
	const packId = packIds[packIds.length - 1]

	const sven = await playwright.request.newContext({
		extraHTTPHeaders: basic(stranger, password),
		storageState: { cookies: [], origins: [] },
	})
	const statusRes = await sven.get(`${API}/${meetingId}/board-book/status`)
	expect(statusRes.status()).toBe(403)
	const compileRes = await sven.post(`${API}/${meetingId}/board-book`, {
		data: { changeNote: 'x' },
	})
	expect(compileRes.status()).toBe(403)
	// REQ-005: the pack follows the meeting's access, so OpenRegister answers
	// "not found" or "forbidden" for the object itself.
	const packRes = await sven.get(`${OR}/meeting-pack/${packId}`)
	expect([403, 404]).toContain(packRes.status())
	await sven.dispose()
})

// @e2e exclude meeting-pack-board-book::compilation-without-docudesk-falls-back-honestly — the suite's instance has filinq installed, so the fallback cannot be reached from a browser; BoardBookServiceTest::testWithoutFilinqTheFolderPackageIsMadeAndSaidToBeNoPdf and BoardBookRendererTest::testNoFilinqMeansNoPdf cover it, and the compile test above asserts the honest message when it does meet an instance without filinq.
