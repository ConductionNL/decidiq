/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the long-term agenda (change termijnagenda, task 7). One
 * test per scenario of the termijnagenda-register spec, each tagged with the
 * scenario it covers; the one static-convention scenario and the two that
 * need the scheduled notification trigger carry an `@e2e exclude` in the spec.
 *
 * The schema rules (required fields, the period pattern, the declared
 * transition map, the reason a lapse needs, the portfolio holder rule) are
 * asserted on OpenRegister's object API, because that is where they live:
 * no app code guards them. The board, the dialogs, the detail page and the
 * dashboard KPI are driven in the browser as the administrator, who counts
 * as the griffie. The public reads use a client with no session at all.
 *
 * Every test seeds its own governance body and items under a run id and
 * removes them afterwards, so it runs on any instance with decidiq
 * installed, without an example set.
 *
 * @spec openspec/changes/archive/2026-11-24-termijnagenda/specs/termijnagenda-register/spec.md
 */
import type { Page } from '@playwright/test'
import type { Actor } from './support/api-actors.ts'

import { expect, test } from '@playwright/test'
import {
	adminActor,
	anonymousActor,
	BASE,
	createObject,
	listObjects,
	newRunId,
	ObjectLedger,
	OR,
	readObject,
	replaceObject,
	uuidOf,
} from './support/api-actors.ts'

const SCHEMA = 'planned-agenda-item'

let admin: Actor
let ledger: ObjectLedger
let run: string

test.beforeEach(async ({ playwright }) => {
	admin = await adminActor(playwright)
	ledger = new ObjectLedger([SCHEMA, 'agenda-item', 'decision', 'meeting', 'governance-commitment', 'person', 'governance-body'])
	run = newRunId()
})

test.afterEach(async () => {
	await ledger.cleanup(admin)
	await admin.ctx.dispose()
})

/**
 * Seed a governance body for this run.
 *
 * @param name The body's name suffix.
 * @return The body's id.
 */
async function seedBody(name = 'gemeenteraad'): Promise<string> {
	const body = await createObject(admin, ledger, 'governance-body', {
		name: `${run}-${name}`,
		bodyType: 'legislative',
		domain: 'municipality',
	})
	return uuidOf(body)
}

/**
 * The fields of a valid planned item.
 *
 * @param governanceBody The body's id.
 * @param extra          Fields to add or replace.
 * @return The item.
 */
function plannedItem(governanceBody: string, extra: Record<string, unknown> = {}): Record<string, unknown> {
	return {
		subject: `${run} Herziening parkeerbeleid`,
		governanceBody,
		plannedPeriod: '2026-Q4',
		expectedType: 'council-proposal',
		ownerType: 'executive-board',
		lifecycle: 'planned',
		...extra,
	}
}

/**
 * Seed one planned item.
 *
 * @param governanceBody The body's id.
 * @param extra          Fields to add or replace.
 * @return The created item.
 */
function seedItem(governanceBody: string, extra: Record<string, unknown> = {}): Promise<any> {
	return createObject(admin, ledger, SCHEMA, plannedItem(governanceBody, extra))
}

/**
 * Save an item with some fields changed, carrying every other field forward.
 *
 * @param item    The item as read.
 * @param changes The fields to change.
 * @return The response.
 */
async function saveWith(item: any, changes: Record<string, unknown>) {
	const id = uuidOf(item)
	const current = await (await readObject(admin, SCHEMA, id)).json()
	const { '@self': _self, id: _id, ...fields } = current
	return replaceObject(admin, SCHEMA, id, { ...fields, ...changes })
}

/**
 * Read an item back through the API.
 *
 * @param item The item.
 * @return Its stored fields.
 */
async function reread(item: any): Promise<any> {
	const resp = await readObject(admin, SCHEMA, uuidOf(item))
	expect(resp.status()).toBe(200)
	return resp.json()
}

/**
 * Open the board on one governance body.
 *
 * @param page     The page.
 * @param bodyName The body's name.
 * @return The board.
 */
async function openBoard(page: Page, bodyName: string) {
	await page.goto(`${BASE}/index.php/apps/decidiq/planned-agenda-board`)
	const board = page.getByTestId('planned-agenda-board')
	await expect(board).toBeVisible()
	const select = board.getByTestId('planned-agenda-board-body')
	await select.click()
	await page.keyboard.type(bodyName)
	await page.getByRole('option', { name: bodyName }).click()
	await expect(board.getByTestId('planned-agenda-board-column').first()).toBeVisible()
	return board
}

/**
 * A board column by its period.
 *
 * @param board  The board.
 * @param period The period.
 * @return The column.
 */
function column(board, period: string) {
	return board.locator(`[data-testid="planned-agenda-board-column"][data-period="${period}"]`)
}

/**
 * Fill and confirm the reschedule dialog.
 *
 * @param page   The page.
 * @param period The new period, or null to keep what the dialog proposes.
 * @param reason The reason.
 * @return Resolves when the dialog closed.
 */
async function confirmReschedule(page: Page, period: string | null, reason: string) {
	const dialog = page.getByTestId('planned-agenda-reschedule-dialog')
	await expect(dialog).toBeVisible()
	if (period !== null) {
		await dialog.getByTestId('planned-agenda-reschedule-period').locator('input').fill(period)
	}
	await dialog.getByTestId('planned-agenda-reschedule-reason').locator('textarea').fill(reason)
	await dialog.getByTestId('planned-agenda-reschedule-confirm').click()
	await expect(dialog).toBeHidden()
}

// @e2e termijnagenda-register::griffier-plans-an-expected-raadsvoorstel
test('a planned item is created linked to its body, and a missing required field is refused', async () => {
	const body = await seedBody()
	const item = await seedItem(body)
	const stored = await reread(item)
	expect(stored.governanceBody).toBe(body)
	expect(stored.plannedPeriod).toBe('2026-Q4')
	expect(stored.lifecycle).toBe('planned')

	for (const field of ['subject', 'governanceBody', 'plannedPeriod', 'expectedType']) {
		const { [field]: _dropped, ...rest } = plannedItem(body)
		const resp = await admin.ctx.post(`${OR}/${SCHEMA}`, { data: rest })
		expect(resp.status(), `an item without ${field} is refused`).toBeGreaterThanOrEqual(400)
		expect(resp.status()).toBeLessThan(500)
	}
})

// @e2e termijnagenda-register::portefeuillehouder-owner-requires-a-person-reference
test('a portfolio holder as owner needs the person who holds the portfolio', async () => {
	const body = await seedBody()
	const refused = await admin.ctx.post(`${OR}/${SCHEMA}`, {
		data: plannedItem(body, { ownerType: 'portfolio-holder', owner: null }),
	})
	expect(refused.status()).toBeGreaterThanOrEqual(400)
	expect(refused.status()).toBeLessThan(500)

	const person = await createObject(admin, ledger, 'person', { name: `${run} Wethouder de Boer` })
	const item = await seedItem(body, { ownerType: 'portfolio-holder', owner: uuidOf(person) })
	expect((await reread(item)).owner).toBe(uuidOf(person))
})

// @e2e termijnagenda-register::register-fragment-is-additive
test('the planned-agenda schema is served next to the base register schemas', async () => {
	const planned = await listObjects(admin, SCHEMA)
	expect(planned.status).toBe(200)
	// A base schema of the register still answers: the fragment added a
	// schema and replaced none.
	const bodies = await listObjects(admin, 'governance-body')
	expect(bodies.status).toBe(200)
})

// @e2e termijnagenda-register::month-and-quarter-items-sort-chronologically-together
test('month and quarter columns stand in chronological order on the board', async ({ page }) => {
	const body = await seedBody()
	for (const period of ['2027-Q1', '2026-11', '2026-Q4']) {
		await seedItem(body, { subject: `${run} ${period}`, plannedPeriod: period })
	}
	const board = await openBoard(page, `${run}-gemeenteraad`)
	const periods = await board.getByTestId('planned-agenda-board-column').evaluateAll(
		(columns) => columns.map((c) => c.getAttribute('data-period')),
	)
	const at = (p: string) => periods.indexOf(p)
	expect(at('2026-11')).toBeGreaterThanOrEqual(0)
	expect(at('2026-11')).toBeLessThan(at('2026-Q4'))
	expect(at('2026-Q4')).toBeLessThan(at('2027-Q1'))
})

// @e2e termijnagenda-register::invalid-period-rejected
test('a free-form period is refused by the schema pattern', async () => {
	const body = await seedBody()
	const resp = await admin.ctx.post(`${OR}/${SCHEMA}`, {
		data: plannedItem(body, { plannedPeriod: 'november 2026' }),
	})
	expect(resp.status()).toBeGreaterThanOrEqual(400)
	expect(resp.status()).toBeLessThan(500)
})

// @e2e termijnagenda-register::overdue-definition
test('a past-period open item is marked overdue on the board, a realised one is not', async ({ page }) => {
	const body = await seedBody()
	await seedItem(body, { subject: `${run} open past`, plannedPeriod: '2024-Q4' })
	const settled = await seedItem(body, { subject: `${run} realised past`, plannedPeriod: '2024-Q4' })
	expect((await saveWith(settled, { lifecycle: 'realised' })).status()).toBe(200)

	const board = await openBoard(page, `${run}-gemeenteraad`)
	const card = (subject: string) => board.getByTestId('planned-agenda-board-card').filter({ hasText: subject })
	await expect(card(`${run} open past`)).toHaveClass(/decidiq-planned-board__card--overdue/)
	await expect(card(`${run} realised past`)).not.toHaveClass(/decidiq-planned-board__card--overdue/)
})

// @e2e termijnagenda-register::item-is-shifted-and-later-realised
test('a planned item is postponed and later realised, ending terminal', async () => {
	const body = await seedBody()
	const item = await seedItem(body)
	expect((await saveWith(item, { lifecycle: 'postponed', plannedPeriod: '2027-Q1' })).status()).toBe(200)
	expect((await saveWith(item, { lifecycle: 'realised' })).status()).toBe(200)
	expect((await reread(item)).lifecycle).toBe('realised')
})

// @e2e termijnagenda-register::dropping-an-item-requires-a-reason
test('a lapse without a reason is refused, with a reason it is accepted', async () => {
	const body = await seedBody()
	const item = await seedItem(body)
	const refused = await saveWith(item, { lifecycle: 'lapsed', reasonExpired: '' })
	expect(refused.status()).toBeGreaterThanOrEqual(400)
	expect(refused.status()).toBeLessThan(500)
	expect((await reread(item)).lifecycle).toBe('planned')

	const accepted = await saveWith(item, { lifecycle: 'lapsed', reasonExpired: 'Onderwerp opgegaan in omgevingsvisie' })
	expect(accepted.status()).toBe(200)
	expect((await reread(item)).lifecycle).toBe('lapsed')
})

// @e2e termijnagenda-register::terminal-states-are-final
test('a realised item cannot be set back to planned', async () => {
	const body = await seedBody()
	const item = await seedItem(body)
	expect((await saveWith(item, { lifecycle: 'realised' })).status()).toBe(200)
	const refused = await saveWith(item, { lifecycle: 'planned' })
	expect(refused.status()).toBeGreaterThanOrEqual(400)
	expect(refused.status()).toBeLessThan(500)
	expect((await reread(item)).lifecycle).toBe('realised')
})

// @e2e termijnagenda-register::reschedule-appends-to-history
// @e2e termijnagenda-register::second-shift-preserves-the-first
test('each reschedule appends to the shift history and keeps the earlier entries', async ({ page }) => {
	const body = await seedBody()
	const item = await seedItem(body)
	const board = await openBoard(page, `${run}-gemeenteraad`)

	await column(board, '2026-Q4').getByTestId('planned-agenda-board-reschedule').click()
	await confirmReschedule(page, '2027-Q1', 'Wacht op uitkomst regionale samenwerking')
	const once = await reread(item)
	expect(once.lifecycle).toBe('postponed')
	expect(once.plannedPeriod).toBe('2027-Q1')
	expect(once.shiftHistory).toHaveLength(1)
	expect(once.shiftHistory[0]).toMatchObject({
		van: '2026-Q4',
		toPeriod: '2027-Q1',
		reason: 'Wacht op uitkomst regionale samenwerking',
	})
	expect(String(once.shiftHistory[0].door)).not.toBe('')
	expect(Number.isNaN(Date.parse(once.shiftHistory[0].op))).toBe(false)

	await column(board, '2027-Q1').getByTestId('planned-agenda-board-reschedule').click()
	await confirmReschedule(page, '2027-Q2', 'Raadsvoorstel later aangeleverd')
	const twice = await reread(item)
	expect(twice.shiftHistory).toHaveLength(2)
	expect(twice.shiftHistory[0]).toEqual(once.shiftHistory[0])
	expect(twice.shiftHistory[1]).toMatchObject({ van: '2027-Q1', toPeriod: '2027-Q2' })
})

// @e2e termijnagenda-register::drag-to-a-later-quarter
test('dragging a card to a later quarter and confirming the reason reschedules it', async ({ page }) => {
	const body = await seedBody()
	const item = await seedItem(body)
	const board = await openBoard(page, `${run}-gemeenteraad`)
	const card = board.getByTestId('planned-agenda-board-card').filter({ hasText: `${run} Herziening parkeerbeleid` })

	await card.dragTo(column(board, '2027-Q1'))
	await confirmReschedule(page, null, 'Wacht op uitkomst regionale samenwerking')

	await expect(column(board, '2027-Q1').getByTestId('planned-agenda-board-card')).toContainText(`${run} Herziening parkeerbeleid`)
	const stored = await reread(item)
	expect(stored.lifecycle).toBe('postponed')
	expect(stored.plannedPeriod).toBe('2027-Q1')
	expect(stored.shiftHistory).toHaveLength(1)
})

// @e2e termijnagenda-register::cancelled-drag-writes-nothing
test('a cancelled drag leaves the card where it was and saves nothing', async ({ page }) => {
	const body = await seedBody()
	const item = await seedItem(body)
	const board = await openBoard(page, `${run}-gemeenteraad`)
	const writes: string[] = []
	page.on('request', (request) => {
		if (['PUT', 'PATCH', 'POST'].includes(request.method()) && request.url().includes(`/${SCHEMA}`)) {
			writes.push(`${request.method()} ${request.url()}`)
		}
	})

	const card = board.getByTestId('planned-agenda-board-card').filter({ hasText: `${run} Herziening parkeerbeleid` })
	await card.dragTo(column(board, '2027-Q1'))
	const dialog = page.getByTestId('planned-agenda-reschedule-dialog')
	await expect(dialog).toBeVisible()
	await dialog.getByTestId('planned-agenda-reschedule-cancel').click()
	await expect(dialog).toBeHidden()

	await expect(column(board, '2026-Q4').getByTestId('planned-agenda-board-card')).toContainText(`${run} Herziening parkeerbeleid`)
	expect(writes).toEqual([])
	const stored = await reread(item)
	expect(stored.plannedPeriod).toBe('2026-Q4')
	expect(stored.lifecycle).toBe('planned')
})

// @e2e termijnagenda-register::reschedule-without-dragging
test('the keyboard reschedule action does what a drag does', async ({ page }) => {
	const body = await seedBody()
	const item = await seedItem(body)
	const board = await openBoard(page, `${run}-gemeenteraad`)

	await column(board, '2026-Q4').getByTestId('planned-agenda-board-reschedule').focus()
	await page.keyboard.press('Enter')
	await confirmReschedule(page, '2027-Q1', 'Toegezegd voor het eerste kwartaal')

	const stored = await reread(item)
	expect(stored.lifecycle).toBe('postponed')
	expect(stored.plannedPeriod).toBe('2027-Q1')
	expect(stored.shiftHistory).toHaveLength(1)
})

// @e2e termijnagenda-register::realise-with-an-assistive-suggestion
test('realising an item suggests the matching agenda item and links it, changing nothing else', async ({ page }) => {
	const body = await seedBody()
	const meeting = await createObject(admin, ledger, 'meeting', {
		title: `${run} raadsvergadering`,
		meetingType: 'regular',
		scheduledDate: new Date(Date.now() + 14 * 86_400_000).toISOString(),
		governanceBody: body,
	})
	const agendaItem = await createObject(admin, ledger, 'agenda-item', {
		title: `${run} Raadsvoorstel herziening parkeerbeleid`,
		itemType: 'decision',
		orderNumber: 1,
		meeting: uuidOf(meeting),
	})
	const before = await (await readObject(admin, 'agenda-item', uuidOf(agendaItem))).json()
	const meetingBefore = await (await readObject(admin, 'meeting', uuidOf(meeting))).json()
	const item = await seedItem(body)

	await page.goto(`${BASE}/index.php/apps/decidiq/planned-agenda/${uuidOf(item)}`)
	await page.getByTestId('planned-agenda-action-realise').click()
	const dialog = page.getByTestId('planned-agenda-realise-dialog')
	await expect(dialog).toBeVisible()
	const suggestion = dialog.getByTestId('planned-agenda-realise-agenda-item').first()
	await expect(suggestion).toContainText(`${run} Raadsvoorstel herziening parkeerbeleid`)
	await suggestion.click()
	await dialog.getByTestId('planned-agenda-realise-confirm').click()
	await expect(dialog).toBeHidden()

	const stored = await reread(item)
	expect(stored.lifecycle).toBe('realised')
	expect(stored.realisedAgendaItem).toBe(uuidOf(agendaItem))

	const after = await (await readObject(admin, 'agenda-item', uuidOf(agendaItem))).json()
	const meetingAfter = await (await readObject(admin, 'meeting', uuidOf(meeting))).json()
	expect(after.title).toBe(before.title)
	expect(after.meeting).toBe(before.meeting)
	expect(after['@self']?.updated).toBe(before['@self']?.updated)
	expect(meetingAfter['@self']?.updated).toBe(meetingBefore['@self']?.updated)
	const meetings = await listObjects(admin, 'meeting', { governanceBody: body })
	expect(meetings.results).toHaveLength(1)
})

// @e2e termijnagenda-register::origin-links-navigate-without-duplication
test('the origin commitment and motion show as references on the detail page', async ({ page }) => {
	const body = await seedBody()
	const person = await createObject(admin, ledger, 'person', { name: `${run} Wethouder Jansen` })
	const commitment = await createObject(admin, ledger, 'governance-commitment', {
		text: `${run} Toezegging parkeernota`,
		madeBy: uuidOf(person),
		directedTo: body,
		lifecycle: 'in-execution',
		deadline: '2026-12-01',
	})
	const motion = await createObject(admin, ledger, 'decision', {
		title: `${run} Motie betaald parkeren`,
		decisionType: 'motion',
		governanceBody: body,
	})
	const item = await seedItem(body, {
		originCommitment: uuidOf(commitment),
		originMotion: uuidOf(motion),
	})

	await page.goto(`${BASE}/index.php/apps/decidiq/planned-agenda/${uuidOf(item)}`)
	await expect(page.getByText(`${run} Toezegging parkeernota`).first()).toBeVisible()
	await expect(page.getByText(`${run} Motie betaald parkeren`).first()).toBeVisible()

	// The item keeps no execution narrative of its own: it stores the two
	// references and nothing copied from the motion.
	const stored = await reread(item)
	expect(stored.originCommitment).toBe(uuidOf(commitment))
	expect(stored.originMotion).toBe(uuidOf(motion))
	expect(JSON.stringify(stored)).not.toContain('Motie betaald parkeren')
})

// @e2e termijnagenda-register::filter-and-export-the-termijnagenda
test('the index filters on body and open lifecycle, exports those rows and opens an item', async ({ page }) => {
	const raad = await seedBody('gemeenteraad')
	const commissie = await seedBody('commissie')
	const open = await seedItem(raad, { subject: `${run} open raad` })
	const postponed = await seedItem(raad, { subject: `${run} verschoven raad` })
	expect((await saveWith(postponed, { lifecycle: 'postponed', plannedPeriod: '2027-Q1', shiftHistory: [{ van: '2026-Q4', toPeriod: '2027-Q1', reason: `${run} uitgesteld`, door: 'admin', op: new Date().toISOString() }] })).status()).toBe(200)
	const realised = await seedItem(raad, { subject: `${run} gerealiseerd raad` })
	expect((await saveWith(realised, { lifecycle: 'realised' })).status()).toBe(200)
	await seedItem(commissie, { subject: `${run} open commissie` })

	const query = new URLSearchParams([['governanceBody', raad], ['lifecycle', 'planned'], ['lifecycle', 'postponed']])
	await page.goto(`${BASE}/index.php/apps/decidiq/planned-agenda?${query}`)
	const table = page.locator('table')
	await expect(table.getByText(`${run} open raad`)).toBeVisible()
	await expect(table.getByText(`${run} verschoven raad`)).toBeVisible()
	await expect(table.getByText(`${run} gerealiseerd raad`)).toHaveCount(0)
	await expect(table.getByText(`${run} open commissie`)).toHaveCount(0)

	// The export is OpenRegister's, over the same filters.
	const csv = await admin.ctx.get(`${OR}/${SCHEMA}/export`, {
		params: { type: 'csv', governanceBody: raad, 'lifecycle[]': 'planned' },
	})
	expect(csv.status()).toBe(200)
	const text = await csv.text()
	expect(text).toContain(`${run} open raad`)
	expect(text).not.toContain(`${run} open commissie`)
	expect(text).toContain('plannedPeriod')
	expect(text).toContain('lifecycle')

	await table.getByText(`${run} verschoven raad`).click()
	await expect(page).toHaveURL(new RegExp(`/planned-agenda/${uuidOf(postponed)}`))
	await expect(page.getByText(`${run} uitgesteld`).first()).toBeVisible()
	expect(uuidOf(open)).not.toBe('')
})

// @e2e termijnagenda-register::published-item-is-anonymously-readable-and-live
test('a published item is read anonymously, rescheduled state included, without republishing', async ({ playwright }) => {
	const body = await seedBody()
	const item = await seedItem(body, { publicationDate: new Date(Date.now() - 86_400_000).toISOString() })
	const entry = { van: '2026-Q4', toPeriod: '2027-Q1', reason: 'Wacht op uitkomst regionale samenwerking', door: 'admin', op: new Date().toISOString() }
	expect((await saveWith(item, { lifecycle: 'postponed', plannedPeriod: '2027-Q1', shiftHistory: [entry] })).status()).toBe(200)

	const anonymous = await anonymousActor(playwright)
	try {
		const resp = await anonymous.ctx.get(`${OR}/${SCHEMA}/${uuidOf(item)}`)
		expect(resp.status()).toBe(200)
		const read = await resp.json()
		expect(read.plannedPeriod).toBe('2027-Q1')
		expect(read.lifecycle).toBe('postponed')
		expect(read.shiftHistory).toEqual([entry])
	} finally {
		await anonymous.ctx.dispose()
	}
})

// @e2e termijnagenda-register::unpublished-item-is-not-public
test('an item without a publication date is not on the public surface', async ({ playwright }) => {
	const body = await seedBody()
	const unpublished = await seedItem(body, { subject: `${run} niet gepubliceerd` })
	const published = await seedItem(body, {
		subject: `${run} gepubliceerd`,
		publicationDate: new Date(Date.now() - 86_400_000).toISOString(),
	})

	const anonymous = await anonymousActor(playwright)
	try {
		const list = await anonymous.ctx.get(`${OR}/${SCHEMA}`, { params: { governanceBody: body, _limit: 50 } })
		expect(list.status()).toBe(200)
		const rows = (await list.json()).results ?? []
		const ids = rows.map((row: any) => uuidOf(row))
		expect(ids).toContain(uuidOf(published))
		expect(ids).not.toContain(uuidOf(unpublished))
		expect([403, 404]).toContain((await anonymous.ctx.get(`${OR}/${SCHEMA}/${uuidOf(unpublished)}`)).status())
	} finally {
		await anonymous.ctx.dispose()
	}
})

// @e2e termijnagenda-register::kpi-counts-only-overdue-open-items
test('the dashboard KPI counts the overdue open items only and opens the filtered index', async ({ page }) => {
	/**
	 * The KPI's number once it has loaded.
	 *
	 * @return The count.
	 */
	const kpiCount = async (): Promise<number> => {
		await page.goto(`${BASE}/index.php/apps/decidiq/`)
		const kpi = page.getByTestId('overdue-planned-agenda-kpi')
		await expect(kpi).toBeVisible()
		await expect(kpi).toContainText(/\d/)
		return Number(((await kpi.innerText()).match(/\d+/) ?? ['NaN'])[0])
	}

	const before = await kpiCount()
	const body = await seedBody()
	await seedItem(body, { subject: `${run} overdue planned`, plannedPeriod: '2024-Q4' })
	const postponed = await seedItem(body, { subject: `${run} overdue postponed`, plannedPeriod: '2024-Q3' })
	expect((await saveWith(postponed, { lifecycle: 'postponed', plannedPeriod: '2024-Q4' })).status()).toBe(200)
	const realised = await seedItem(body, { subject: `${run} past realised`, plannedPeriod: '2024-Q4' })
	expect((await saveWith(realised, { lifecycle: 'realised' })).status()).toBe(200)
	await seedItem(body, { subject: `${run} future planned`, plannedPeriod: '2099-Q1' })

	expect(await kpiCount()).toBe(before + 2)
	await page.getByTestId('overdue-planned-agenda-kpi').click()
	await expect(page).toHaveURL(/\/planned-agenda\?.*lifecycle/)
})
