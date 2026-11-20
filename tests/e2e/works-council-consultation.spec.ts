/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: WOR-trajecten, works-council consultation requests (change
 * works-council-consultation, task 7). One advice request is walked through the
 * whole statutory traject: registered, taken into treatment, put on an
 * overlegvergadering, its advice adopted, the response document written, sent,
 * a decision contrary to the advice recorded (the suspension term shows) and
 * closed. Around it: the list and its filters, the two dashboard tiles and
 * where they lead, the constituency-consultation link with and without a
 * consultation, the other two decision outcomes, and the works council as an
 * ordinary governance body.
 *
 * Covered elsewhere, as configuration and as code:
 * - tests/Unit/Service/WorksCouncilConsultationRegisterTest.php: the schema in
 *   the merged register, the statutory lifecycle, the declared notifications
 *   (rappels before and after the requested response date, suspension expiry,
 *   decision contrary to the advice) and the suspensionTo calculation.
 * - tests/Unit/Service/ConsultationResponseDocumentServiceTest.php: markdown,
 *   PDF through filinq, the honest fallback without it, the refusals, and the
 *   controller's 401/403/404/201.
 * - tests/Unit/Settings/WorksCouncilSeedTest.php: the works-council example set.
 * - tests/vitest/worksCouncilPages.spec.js, worksCouncilKpis.spec.js and
 *   worksCouncilResponse.spec.js: the list and traject pages, the two tiles,
 *   the next-step widget and the director-decision dialog.
 *
 * Needs the works-council example set (lib/Settings/profiles/works-council.json):
 * the body "Ondernemingsraad ACME B.V.", the director Hilde Jansen, the
 * overlegvergadering of 18 February 2026 with its agenda items, and three
 * trajecten: the logistics reorganisation (an advice request in
 * consultationMeeting), the working-hours scheme (a consent request in
 * constituencyConsultation, linked to a constituency consultation) and the ICT
 * outsourcing (an advice request, completed, with a decision contrary to the
 * advice and a related decision). Runs as the administrator, who may write the
 * response document. Every traject this file creates carries the run tag in its
 * subject and is deleted afterwards.
 *
 * Lifecycle moves go through the OpenRegister object API, which is how the
 * page saves them: the traject page declares no transition buttons, and the
 * point under test is that OpenRegister applies the declared transition map.
 * The two steps that have their own UI, writing the response document and
 * recording the director's decision, are driven through the page.
 *
 * Not driven here, and why (an exclusion in this file is a note; gate-19 reads
 * exclusions from the spec, where the two marked scenarios already carry one):
 *
 * @e2e exclude works-council-consultation::rappel-before-the-requested-response-date — a scheduled notification trigger fires from OpenRegister's cron, not from a page; declared and checked in WorksCouncilConsultationRegisterTest::testTheNotificationsAreDeclared
 * @e2e exclude works-council-consultation::overdue-rappel — a scheduled notification trigger, as above; WorksCouncilConsultationRegisterTest::testTheNotificationsAreDeclared checks its non-terminal filter
 * @e2e exclude works-council-consultation::no-imperative-dispatch — static convention, excluded in the spec and enforced by the notification-dialect hydra gate
 * @e2e exclude works-council-consultation::no-parallel-works-council-schema — register-schema-structure invariant, excluded in the spec; WorksCouncilConsultationRegisterTest::testTheRequestIsInTheMergedRegister
 * @e2e exclude works-council-consultation::register-fragment-is-additive — "no existing schema is modified" is a property of the merged register file, not of a page; WorksCouncilConsultationRegisterTest::testTheRequestIsInTheMergedRegister (the carried-schema half is asserted below)
 *
 * Written, not yet run: it needs a live instance with the example set.
 *
 * @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import { openRow } from './support/open-row.ts'
import {
	deleteObject,
	getObject,
	listObjects,
	objId,
	writeHeaders,
} from './workflows/governance-fixture.ts'

const APP = `${BASE}/index.php/apps/decidiq`
const OR = `${BASE}/index.php/apps/openregister/api/objects/decidiq`
const SCHEMA = 'consultation-request'
const TAG = `e2e-wor-${Date.now()}`

/** Seeded names from the works-council example set. */
const BODY = 'Ondernemingsraad ACME B.V.'
const DIRECTOR = 'Hilde Jansen'
const MEETING = 'Overlegvergadering 18 februari 2026'
const AGENDA_ITEM = 'Voorgenomen reorganisatie logistiek'
const SEEDED_REORGANISATION = 'Voorgenomen reorganisatie afdeling logistiek'
const SEEDED_WORKING_HOURS = 'Wijziging werktijdenregeling'
const SEEDED_ICT = 'Uitbesteding ICT-beheer aan externe dienstverlener'
const RELATED_DECISION = 'Advies OR over uitbesteding ICT-beheer'

/** The states the Reply overdue tile counts: open and not yet sent. */
const OVERDUE_STATES = [
	'received',
	'in-progress',
	'constituencyConsultation',
	'consultationMeeting',
	'adopted',
]

/** Every traject this file created, deleted in afterAll. */
const created: string[] = []
const documents: string[] = []

/**
 * The first object of a schema whose field holds the value.
 *
 * @param page   The page, for its request context.
 * @param schema The schema slug.
 * @param field  The field to match.
 * @param value  The value.
 * @return The object, or null.
 */
async function findOne(
	page: Page,
	schema: string,
	field: string,
	value: string,
): Promise<any | null> {
	const response = await page.request.get(`${OR}/${schema}`, {
		params: { [field]: value, _limit: 20 },
		headers: { Accept: 'application/json' },
	})
	const filtered = response.ok()
		? (((await response.json()).results ?? []) as any[])
		: []
	const rows = filtered.length > 0 ? filtered : await listObjects(page, schema)
	return rows.find((row) => String(row?.[field] ?? '').includes(value)) ?? null
}

/** The seeded references a new traject points at. */
interface Seed {
	body: string
	director: string
	meeting: string
	agendaItem: string
}

/**
 * Read the seeded body, director, meeting and agenda item, or skip when the
 * example set is not loaded.
 *
 * @param page The page.
 * @return The ids.
 */
async function seed(page: Page): Promise<Seed> {
	const body = await findOne(page, 'governance-body', 'name', BODY)
	const director = await findOne(page, 'person', 'name', DIRECTOR)
	const meeting = await findOne(page, 'meeting', 'title', MEETING)
	const agendaItem = await findOne(page, 'agenda-item', 'title', AGENDA_ITEM)
	test.skip(
		!body || !director || !meeting || !agendaItem,
		'the works-council example set is not loaded on this instance',
	)
	return {
		body: objId(body),
		director: objId(director),
		meeting: objId(meeting),
		agendaItem: objId(agendaItem),
	}
}

/**
 * Register a traject through the object API, as the create form saves it.
 *
 * @param page   The page.
 * @param s      The seeded references.
 * @param fields The fields that differ from a plain advice request.
 * @return The new traject's id.
 */
async function register(
	page: Page,
	s: Seed,
	fields: Record<string, unknown> = {},
): Promise<string> {
	const response = await page.request.post(`${OR}/${SCHEMA}`, {
		headers: await writeHeaders(page),
		data: {
			type: 'adviceRequest',
			worArticle: 'art. 25 lid 1 sub d WOR',
			subject: `${TAG} Voorgenomen sluiting vestiging Zwolle`,
			director: s.director,
			governanceBody: s.body,
			receivedDate: '2026-08-03',
			requestedResponseDate: '2026-10-15',
			lifecycle: 'received',
			...fields,
		},
	})
	expect(response.status(), await response.text()).toBeLessThan(300)
	const id = objId(await response.json())
	created.push(id)
	return id
}

/**
 * Save part of a traject, as the page does.
 *
 * @param page   The page.
 * @param id     The traject.
 * @param fields The fields to change.
 * @return The response status.
 */
async function patch(
	page: Page,
	id: string,
	fields: Record<string, unknown>,
): Promise<number> {
	const response = await page.request.patch(`${OR}/${SCHEMA}/${id}`, {
		headers: await writeHeaders(page),
		data: fields,
	})
	return response.status()
}

/**
 * Move a traject through states, each move one the transition map allows.
 *
 * @param page   The page.
 * @param id     The traject.
 * @param states The states in order.
 * @param extra  Fields to save with a given state.
 */
async function walk(
	page: Page,
	id: string,
	states: string[],
	extra: Record<string, Record<string, unknown>> = {},
): Promise<void> {
	for (const state of states) {
		const status = await patch(page, id, {
			lifecycle: state,
			...(extra[state] ?? {}),
		})
		expect(
			status,
			`the move to ${state} is in the declared transition map`,
		).toBeLessThan(300)
	}
}

/**
 * Open a traject's page and wait for its next-step widget.
 *
 * @param page The page.
 * @param id   The traject.
 */
async function openTraject(page: Page, id: string): Promise<void> {
	await page.goto(`${APP}/works-council-consultations/${id}`)
	await expect(page.getByTestId('works-council-response-tab')).toBeVisible({
		timeout: 30_000,
	})
}

/**
 * Record the director's decision through the dialog.
 *
 * @param page    The page, on a traject in sent.
 * @param outcome The outcome label.
 * @param text    What the director decided.
 * @param date    The decision date, YYYY-MM-DD.
 * @param suspension The suspension end the dialog should show, or null for none.
 */
async function recordDecision(
	page: Page,
	outcome: string,
	text: string,
	date: string,
	suspension: string | null,
): Promise<void> {
	const tab = page.getByTestId('works-council-response-tab')
	await tab.getByTestId('works-council-record-decision').click()
	const dialog = page.getByTestId('record-director-decision-dialog')
	await expect(dialog).toBeVisible()

	await dialog.getByTestId('director-decision-outcome').click()
	await page.getByRole('option', { name: outcome }).click()
	await dialog.getByTestId('director-decision-text').locator('textarea').fill(text)
	await dialog.getByTestId('director-decision-date').fill(date)

	if (suspension === null) {
		await expect(dialog.getByTestId('director-decision-suspension')).toHaveCount(
			0,
		)
	} else {
		await expect(
			dialog.getByTestId('director-decision-suspension'),
		).toContainText(suspension)
	}

	await dialog.getByTestId('director-decision-confirm').click()
	await expect(dialog).toBeHidden()
	await expect(tab.getByRole('status')).toHaveText('The decision is recorded.')
}

/**
 * A row of the index by object id.
 *
 * @param page The page.
 * @param id   The object id.
 * @return The row locator.
 */
function row(page: Page, id: string) {
	return page.locator(`[data-testid="cn-object-row"][data-testid-row-id="${id}"]`)
}

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	for (const id of documents) {
		await deleteObject(page, 'digital-document', id)
	}
	for (const id of created) {
		await deleteObject(page, SCHEMA, id)
	}
	await page.close()
})

test.describe.serial('one advice request, walked from receipt to completed', () => {
	let s: Seed
	let id = ''
	let written: {
		docudesk: boolean
		format: string
		path: string
		note?: string
		responseDocument: string
	} | null = null
	let shownMessage = ''

	// @e2e works-council-consultation::ambtelijk-secretaris-registers-an-adviesaanvraag
	test('a registered advice request is linked to the works council and the director, and a request without its required fields is refused', async ({
		page,
	}) => {
		s = await seed(page)
		id = await register(page, s)

		const stored = await getObject(page, SCHEMA, id)
		expect(stored?.lifecycle).toBe('received')
		expect(stored?.governanceBody).toBe(s.body)
		expect(stored?.director).toBe(s.director)

		for (const missing of ['type', 'subject', 'director', 'receivedDate']) {
			const data: Record<string, unknown> = {
				type: 'adviceRequest',
				worArticle: 'art. 25 lid 1 sub d WOR',
				subject: `${TAG} zonder ${missing}`,
				director: s.director,
				governanceBody: s.body,
				receivedDate: '2026-08-03',
				lifecycle: 'received',
			}
			delete data[missing]
			const refused = await page.request.post(`${OR}/${SCHEMA}`, {
				headers: await writeHeaders(page),
				data,
			})
			if (refused.ok()) {
				created.push(objId(await refused.json()))
			}
			expect(
				refused.status(),
				`a request without ${missing} is refused by schema validation`,
			).toBeGreaterThanOrEqual(400)
		}

		await page.goto(`${APP}/works-council-consultations`)
		await expect(row(page, id)).toBeVisible({ timeout: 30_000 })
		await expect(row(page, id)).toContainText(BODY)
		await expect(row(page, id)).toContainText(DIRECTOR)
	})

	// @e2e works-council-consultation::adviesaanvraag-agendized-on-an-overlegvergadering
	// @e2e works-council-consultation::achterbanraadpleging-is-optional
	test('taken into treatment and put on the overlegvergadering, the traject shows the meeting and moves on without a constituency consultation', async ({
		page,
	}) => {
		test.skip(id === '', 'needs the traject from the previous test')

		await walk(page, id, ['in-progress'])
		expect(
			await patch(page, id, {
				consultationMeeting: s.meeting,
				agendaItem: s.agendaItem,
			}),
		).toBeLessThan(300)

		await openTraject(page, id)
		await expect(
			page.getByRole('link', { name: new RegExp(MEETING) }).first(),
		).toBeVisible()
		await expect(page.getByText(AGENDA_ITEM).first()).toBeVisible()

		// in-progress -> consultationMeeting directly: the constituency step is skipped.
		await walk(page, id, ['consultationMeeting'])
		expect((await getObject(page, SCHEMA, id))?.lifecycle).toBe(
			'consultationMeeting',
		)
	})

	// @e2e works-council-consultation::formal-advies-document-generated-with-docudesk-present
	// @e2e works-council-consultation::honest-fallback-without-docudesk
	test('once the advice is adopted, Write the response document stores it on the traject and links it', async ({
		page,
	}) => {
		test.skip(id === '', 'needs the traject from the previous test')

		await walk(page, id, ['adopted'], {
			adopted: {
				responseOutcome: 'advice-with-conditions',
				responseText:
					'De OR adviseert positief onder voorwaarde van een sociaal plan voor de 14 betrokken medewerkers.',
				responseDate: '2026-08-28',
			},
		})

		await openTraject(page, id)
		const tab = page.getByTestId('works-council-response-tab')
		const answer = page.waitForResponse(
			(response) =>
				response.request().method() === 'POST'
				&& response.url().includes('/response-document'),
		)
		await tab.getByTestId('works-council-write-document').click()
		const response = await answer
		expect(response.status(), await response.text()).toBe(201)
		written = await response.json()
		if (written?.responseDocument) {
			documents.push(written.responseDocument)
		}

		const status = tab.getByRole('status')
		await expect(status).toBeVisible()
		shownMessage = (await status.innerText()).trim()

		const stored = await getObject(page, SCHEMA, id)
		expect(
			stored?.responseDocument,
			'the document is linked as responseDocument',
		).toBe(written?.responseDocument)

		// Whichever renderer this instance has, the page says what it stored.
		if (written?.docudesk) {
			expect(written.format).toBe('pdf')
			expect(shownMessage).toBe(
				'The response document is stored with this consultation.',
			)
		} else {
			expect(written?.format).toBe('markdown')
			expect(shownMessage).toContain(
				'a markdown document was stored instead of a PDF',
			)
		}

		await page.reload()
		await expect(page.getByText(/\.(pdf|md)$/).first()).toBeVisible({
			timeout: 30_000,
		})
	})

	// @e2e works-council-consultation::afwijkend-besluit-derives-the-opschortingstermijn
	test('sent, a decision contrary to the advice is recorded through the dialog and the one-month suspension term shows', async ({
		page,
	}) => {
		test.skip(id === '', 'needs the traject from the previous test')

		// The previous step adopted a conditional advice; the director now
		// receives a negative one, so deviating from it starts the term.
		expect(
			await patch(page, id, { responseOutcome: 'negative-advice' }),
		).toBeLessThan(300)
		await walk(page, id, ['sent'])

		await openTraject(page, id)
		await recordDecision(
			page,
			'Contrary to the advice',
			'De bestuurder sluit de vestiging Zwolle per 1 januari, zonder het gevraagde sociaal plan.',
			'2026-09-01',
			'2026-10-01',
		)

		const stored = await getObject(page, SCHEMA, id)
		expect(stored?.lifecycle).toBe('decision-received')
		expect(stored?.decisionOutcome).toBe('contrary-to-advice')
		expect(
			String(stored?.suspensionTo ?? '').slice(0, 10),
			'OpenRegister derives decisionDate + 1 month',
		).toBe('2026-10-01')

		await page.reload()
		await expect(
			page.getByText('Suspension term (art. 25 lid 6 WOR)'),
		).toBeVisible({ timeout: 30_000 })
	})

	// @e2e works-council-consultation::traject-runs-the-full-statutory-flow
	// @e2e works-council-consultation::invalid-transition-rejected-declaratively
	test('the traject closes as completed, and a completed traject cannot be moved back into treatment', async ({
		page,
	}) => {
		test.skip(id === '', 'needs the traject from the previous test')

		await walk(page, id, ['completed'])
		await page.goto(`${APP}/works-council-consultations`)
		await page.locator('.cn-quick-filter-bar').getByRole('combobox').click()
		await page.getByRole('option', { name: 'Completed' }).click()
		await expect(row(page, id)).toBeVisible({ timeout: 30_000 })

		const refused = await patch(page, id, { lifecycle: 'in-progress' })
		expect(
			refused,
			'completed is terminal in the declared transition map',
		).toBeGreaterThanOrEqual(400)
		expect((await getObject(page, SCHEMA, id))?.lifecycle).toBe('completed')
	})
})

// @e2e works-council-consultation::secretaris-browses-and-filters-the-trajecten
test('WorksCouncilConsultations: filtered on Consent request and In treatment, only that traject is listed, and its row opens the traject', async ({
	page,
}) => {
	const s = await seed(page)
	const consent = await register(page, s, {
		type: 'consent-request',
		worArticle: 'art. 27 lid 1 sub d WOR',
		subject: `${TAG} Nieuwe regeling persoonlijk opleidingsbudget`,
	})
	await walk(page, consent, ['in-progress'])
	const advice = await register(page, s, {
		subject: `${TAG} Fusie met Bakker Logistiek`,
	})
	await walk(page, advice, ['in-progress'])

	await page.goto(`${APP}/works-council-consultations`)
	await expect(row(page, consent)).toBeVisible({ timeout: 30_000 })

	const filter = page.locator('.cn-quick-filter-bar').getByRole('combobox')
	await filter.click()
	await page.getByRole('option', { name: 'Consent request' }).click()
	await page.getByRole('option', { name: 'In treatment' }).click()
	await page.keyboard.press('Escape')

	await expect(row(page, consent)).toBeVisible({ timeout: 30_000 })
	await expect(row(page, advice)).toHaveCount(0)
	await expect(page.getByText(SEEDED_WORKING_HOURS)).toHaveCount(0)
	await expect(page.getByText(SEEDED_REORGANISATION)).toHaveCount(0)

	await openRow(page, 'Nieuwe regeling persoonlijk opleidingsbudget')
	await expect(page).toHaveURL(
		new RegExp(`/works-council-consultations/${consent}`),
	)
	await expect(page.getByText('art. 27 lid 1 sub d WOR').first()).toBeVisible()
	await expect(page.getByText(BODY).first()).toBeVisible()
	await expect(page.getByText(DIRECTOR).first()).toBeVisible()
})

// @e2e works-council-consultation::kpis-count-the-right-sets
test('Dashboard: Open WOR cases and Reply overdue count what their filtered lists show, and open those lists', async ({
	page,
}) => {
	for (const label of ['Open WOR cases', 'Reply overdue']) {
		await page.goto(`${APP}/`)
		const tile = page
			.locator('.cn-stats-block, .cn-kpi-card')
			.filter({ hasText: label })
		await expect(tile).toBeVisible({ timeout: 30_000 })
		const count = Number(
			(await tile.locator('.cn-stats-block__count-value').innerText()).trim(),
		)
		expect(count, `${label} is not zero on the example set`).toBeGreaterThan(0)

		await tile.click()
		await expect(page).toHaveURL(/\/works-council-consultations/)
		await expect(page).toHaveURL(/lifecycle/)
		await expect(
			page.locator('[data-testid="cn-object-row"]').first(),
		).toBeVisible({ timeout: 30_000 })

		// The tile counts exactly what the list it opens shows, and the
		// completed ICT outsourcing is in neither set.
		await expect(page.locator('[data-testid="cn-object-row"]')).toHaveCount(
			count,
		)
		await expect(page.getByText(SEEDED_ICT)).toHaveCount(0)
	}

	// The last list opened is the overdue one: filtered on the requested date
	// as well, and nothing already sent is on it.
	await expect(page).toHaveURL(/requestedResponseDate/)
	const today = new Date().toISOString().slice(0, 10)
	const response = await page.request.get(`${OR}/${SCHEMA}`, {
		params: { _limit: 200 },
		headers: { Accept: 'application/json' },
	})
	const overdue = (((await response.json()).results ?? []) as any[]).filter(
		(t) =>
			OVERDUE_STATES.includes(t.lifecycle)
			&& String(t.requestedResponseDate ?? '') !== ''
			&& String(t.requestedResponseDate) < today,
	)
	await expect(page.locator('[data-testid="cn-object-row"]')).toHaveCount(
		overdue.length,
	)
})

// @e2e works-council-consultation::traject-links-its-achterbanraadpleging
test('WorksCouncilConsultationDetail: the consent request in constituency consultation opens its consultation, and the traject stores no poll', async ({
	page,
}) => {
	const traject = await findOne(page, SCHEMA, 'subject', SEEDED_WORKING_HOURS)
	test.skip(
		!traject,
		'the works-council example set is not loaded on this instance',
	)
	const trajectId = objId(traject)
	expect(traject.lifecycle).toBe('constituencyConsultation')

	for (const pollField of [
		'questions',
		'responses',
		'tally',
		'tallies',
		'participants',
	]) {
		expect(
			Object.keys(traject),
			`the traject carries no ${pollField}`,
		).not.toContain(pollField)
	}

	await openTraject(page, trajectId)
	const link = page.getByTestId('works-council-constituency-link')
	await expect(link).toBeVisible()
	await link.click()
	await expect(page).toHaveURL(
		new RegExp(`/governance-consultations/${traject.constituencyConsultation}`),
	)
})

// @e2e works-council-consultation::step-degrades-without-the-sibling-capability
test('WorksCouncilConsultationDetail: a traject in constituency consultation with no consultation linked renders without one', async ({
	page,
}) => {
	const s = await seed(page)
	const id = await register(page, s, {
		subject: `${TAG} Invoering cameratoezicht magazijn`,
		worArticle: 'art. 27 lid 1 sub l WOR',
		type: 'consent-request',
	})
	await walk(page, id, ['in-progress', 'constituencyConsultation'])

	await openTraject(page, id)
	await expect(
		page.getByText('Invoering cameratoezicht magazijn').first(),
	).toBeVisible()
	await expect(page.getByTestId('works-council-constituency-link')).toHaveCount(0)
	await expect(page.getByTestId('works-council-response-tab')).toContainText(
		'Nothing to do on this consultation right now.',
	)
})

// @e2e works-council-consultation::response-feeds-a-routed-decisions-advisory-stage
test('WorksCouncilConsultationDetail: the completed ICT outsourcing opens its related decision, where the advisory stage is recorded', async ({
	page,
}) => {
	// The stage outcome itself (advised) is set in the decision's own stage
	// flow by decision-methods; the traject only links to it. This test proves
	// the hand-off is reachable and that the traject records nothing of it.
	const traject = await findOne(page, SCHEMA, 'subject', SEEDED_ICT)
	test.skip(
		!traject,
		'the works-council example set is not loaded on this instance',
	)
	expect(traject.relatedDecision).toBeTruthy()
	expect(Object.keys(traject)).not.toContain('stageOutcome')

	await openTraject(page, objId(traject))
	const link = page.getByTestId('works-council-decision-link')
	await expect(link).toBeVisible()
	await link.click()
	await expect(page).toHaveURL(new RegExp(`/decisions/${traject.relatedDecision}`))
	await expect(page.getByText(RELATED_DECISION).first()).toBeVisible({
		timeout: 30_000,
	})
})

// @e2e works-council-consultation::conform-besluit-has-no-opschorting
test('RecordDirectorDecisionDialog: a decision in line with a positive advice starts no suspension term and the traject closes', async ({
	page,
}) => {
	const s = await seed(page)
	const id = await register(page, s, {
		subject: `${TAG} Nieuw beoordelingssysteem`,
		worArticle: 'art. 25 lid 1 sub k WOR',
	})
	await walk(page, id, ['in-progress', 'consultationMeeting', 'adopted', 'sent'], {
		adopted: {
			responseOutcome: 'positive-advice',
			responseText: 'De OR adviseert positief.',
			responseDate: '2026-09-10',
		},
	})

	await openTraject(page, id)
	await recordDecision(
		page,
		'In line with the advice',
		'De bestuurder volgt het advies.',
		'2026-09-20',
		null,
	)

	const stored = await getObject(page, SCHEMA, id)
	expect(stored?.lifecycle).toBe('decision-received')
	expect(stored?.suspensionTo ?? null).toBeFalsy()
	await page.reload()
	await expect(page.getByTestId('works-council-response-tab')).toBeVisible({
		timeout: 30_000,
	})
	await expect(page.getByText('Suspension term (art. 25 lid 6 WOR)')).toHaveCount(
		0,
	)

	await walk(page, id, ['completed'])
})

// @e2e works-council-consultation::instemmingsverzoek-records-refusal-without-opschorting
test('RecordDirectorDecisionDialog: a refused consent request offers only the consent outcomes, and Not pursued starts no suspension term', async ({
	page,
}) => {
	const s = await seed(page)
	const id = await register(page, s, {
		type: 'consent-request',
		worArticle: 'art. 27 lid 1 sub a WOR',
		subject: `${TAG} Wijziging pensioenregeling`,
	})
	await walk(page, id, ['in-progress', 'consultationMeeting', 'adopted', 'sent'], {
		adopted: {
			responseOutcome: 'consent-refused',
			responseText: 'De OR weigert instemming.',
			responseDate: '2026-09-10',
		},
	})

	await openTraject(page, id)
	const tab = page.getByTestId('works-council-response-tab')
	await tab.getByTestId('works-council-record-decision').click()
	const dialog = page.getByTestId('record-director-decision-dialog')
	await expect(dialog).toBeVisible()
	await dialog.getByTestId('director-decision-outcome').click()
	await expect(
		page.getByRole('option', { name: 'In line with the consent' }),
	).toBeVisible()
	await expect(
		page.getByRole('option', { name: 'Contrary to the advice' }),
	).toHaveCount(0)
	await expect(
		page.getByRole('option', { name: 'In line with the advice' }),
	).toHaveCount(0)
	await page.getByRole('option', { name: 'Not pursued' }).click()
	await dialog
		.getByTestId('director-decision-text')
		.locator('textarea')
		.fill('De bestuurder ziet af van de wijziging.')
	await dialog.getByTestId('director-decision-date').fill('2026-09-20')
	await expect(dialog.getByTestId('director-decision-suspension')).toHaveCount(0)
	await dialog.getByTestId('director-decision-confirm').click()
	await expect(dialog).toBeHidden()
	await expect(tab.getByRole('status')).toHaveText('The decision is recorded.')

	const stored = await getObject(page, SCHEMA, id)
	expect(stored?.lifecycle).toBe('decision-received')
	expect(stored?.decisionOutcome).toBe('not-pursued')
	expect(stored?.suspensionTo ?? null).toBeFalsy()
})

// @e2e works-council-consultation::ondernemingsraad-is-a-universal-governance-body
test('GovernanceBodies: the works council is an ordinary governance body of type works-council, with members', async ({
	page,
}) => {
	const body = await findOne(page, 'governance-body', 'name', BODY)
	test.skip(!body, 'the works-council example set is not loaded on this instance')
	expect(body.bodyType).toBe('works-council')

	const memberships = await page.request.get(`${OR}/membership`, {
		params: { governanceBody: objId(body), _limit: 50 },
		headers: { Accept: 'application/json' },
	})
	expect(
		((await memberships.json()).results ?? []).length,
		'its members are Person + Membership records',
	).toBeGreaterThan(0)

	await page.goto(`${APP}/governance-bodies`)
	await page.getByRole('button', { name: /Other bodies/ }).click()
	await openRow(page, BODY)
	await expect(page).toHaveURL(new RegExp(`/governance-bodies/${objId(body)}`))
})

// @e2e works-council-consultation::no-parallel-meeting-schema
test('the register carries consultation-request and the universal meeting and agenda-item schemas, and no works-council meeting schema', async ({
	request,
}) => {
	const response = await request.get(
		`${BASE}/index.php/apps/openregister/api/registers/decidiq/schemas`,
		{
			headers: { 'OCS-APIRequest': 'true' },
		},
	)
	test.skip(
		response.status() === 404,
		"OpenRegister is not installed here, or decidiq's register was never imported",
	)
	expect(response.ok()).toBeTruthy()
	const body = await response.json()
	const slugs: string[] = ((body.results ?? body.items ?? []) as any[]).map(
		(schema) => String(schema.slug ?? ''),
	)

	expect(slugs).toContain(SCHEMA)
	expect(slugs).toContain('meeting')
	expect(slugs).toContain('agenda-item')
	const parallel = slugs.filter((slug) =>
		/(works-council|ondernemingsraad|overleg).*(meeting|agenda|vergadering)|(meeting|agenda).*(works-council|ondernemingsraad)/.test(
			slug,
		),
	)
	expect(parallel, 'a works-council meeting or agenda schema').toEqual([])
})
