/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the governing-documents register end to end on a live
 * instance (change governing-documents-register). A document and its versions
 * are created through OpenRegister as a secretariat would; the version rules
 * (decidiq's save guard), the in-force and cited-by endpoints and the public
 * view are then read the way the pages and the public site read them.
 *
 * Everything the spec creates carries the run tag and is removed afterwards.
 *
 * @spec openspec/changes/governing-documents-register/specs/governing-documents-register/spec.md
 */

import type { Actor } from './support/api-actors.ts'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import {
	adminActor,
	anonymousActor,
	createObject,
	describe as describeResponse,
	disposeActors,
	newRunId,
	ObjectLedger,
	replaceObject,
	uuidOf,
} from './support/api-actors.ts'

const OR = `${BASE}/index.php/apps/openregister/api/objects/decidiq`
const API = `${BASE}/index.php/apps/decidiq/api`
const run = newRunId()
const tag = `e2e-gdr-${run}`
const ledger = new ObjectLedger(['governing-document-versie', 'governing-document', 'decision', 'governance-body'])
const ids: Record<string, string> = {}

let admin: Actor
let anonymous: Actor

test.describe.configure({ mode: 'serial' })

test.beforeAll(async ({ playwright }) => {
	admin = await adminActor(playwright)
	anonymous = await anonymousActor(playwright)
	ids.body = uuidOf(await createObject(admin, ledger, 'governance-body', { name: `${tag} ledenvergadering`, bodyType: 'association', domain: 'association' }))
	ids.decision = uuidOf(await createObject(admin, ledger, 'decision', {
		title: `${tag} statutenwijziging`,
		text: 'De ledenvergadering besluit de statuten te wijzigen.',
		decisionType: 'motion',
	}))
	ids.statuten = uuidOf(await createObject(admin, ledger, 'governing-document', {
		type: 'articles-of-association',
		citationTitle: `${tag} Statuten VNG`,
		governingBody: ids.body,
		status: 'in-force',
		isPublic: true,
	}))
	ids.internal = uuidOf(await createObject(admin, ledger, 'governing-document', {
		type: 'rules-of-procedure',
		citationTitle: `${tag} Reglement van orde`,
		governingBody: ids.body,
		status: 'in-force',
		isPublic: false,
	}))
})

test.afterAll(async () => {
	if (admin) {
		await ledger.cleanup(admin)
	}
	await disposeActors(admin, anonymous)
})

// @e2e governing-documents-register::citeertitel-and-governing-body-are-required
test('a document without its citation title or governing body is refused', async () => {
	const resp = await admin.ctx.post(`${OR}/governing-document`, { data: { type: 'articles-of-association', status: 'in-force' } })
	expect(resp.status(), await describeResponse(resp)).toBeGreaterThanOrEqual(400)
	expect(resp.status()).toBeLessThan(500)
})

// @e2e governing-documents-register::constitutive-first-version-may-omit-the-decision-link
test('the constitutive first version needs no enacting decision', async () => {
	ids.v1 = uuidOf(await createObject(admin, ledger, 'governing-document-versie', {
		document: ids.statuten,
		versionNumber: 1,
		status: 'draft',
		notes: `${tag} oprichtingsakte`,
	}))
	expect(ids.v1).toBeTruthy()
})

test('the first version takes effect', async () => {
	for (const status of ['adopted', 'in-effect']) {
		const read = await admin.ctx.get(`${OR}/governing-document-versie/${ids.v1}`)
		const current = await read.json()
		const resp = await replaceObject(admin, 'governing-document-versie', ids.v1, {
			document: ids.statuten,
			versionNumber: 1,
			notes: current.notes,
			status,
			effectiveDate: '2024-01-01',
		})
		expect(resp.ok(), `${status}: ${resp.status()}`).toBeTruthy()
	}
})

// @e2e governing-documents-register::amendment-version-without-enacting-decision-refused
test('an amendment version without its enacting decision is refused', async () => {
	const resp = await admin.ctx.post(`${OR}/governing-document-versie`, { data: { document: ids.statuten, versionNumber: 2, status: 'draft' } })
	expect(resp.status()).toBeGreaterThanOrEqual(400)
	expect(resp.status()).toBeLessThan(500)
})

// @e2e governing-documents-register::version-numbers-are-unique-per-document
test('a second version with the same number is refused', async () => {
	const resp = await admin.ctx.post(`${OR}/governing-document-versie`, { data: { document: ids.statuten, versionNumber: 1, status: 'draft', determinedBy: ids.decision } })
	expect(resp.status()).toBeGreaterThanOrEqual(400)
	expect(resp.status()).toBeLessThan(500)
})

// @e2e governing-documents-register::editing-a-sealed-version-refused
test('a version in effect cannot be edited', async () => {
	const resp = await replaceObject(admin, 'governing-document-versie', ids.v1, {
		document: ids.statuten,
		versionNumber: 1,
		status: 'in-effect',
		effectiveDate: '2024-02-01',
		notes: `${tag} oprichtingsakte`,
	})
	expect(resp.status()).toBeGreaterThanOrEqual(400)
	expect(resp.status()).toBeLessThan(500)
	const stored = await (await admin.ctx.get(`${OR}/governing-document-versie/${ids.v1}`)).json()
	expect(String(stored.effectiveDate)).toContain('2024-01-01')
})

// @e2e governing-documents-register::resolve-the-version-in-force-on-a-historical-date
test('the version in force on a date is resolved', async () => {
	const now = await admin.ctx.get(`${API}/governing-documents/${ids.statuten}/in-force`)
	expect(now.ok()).toBeTruthy()
	expect((await now.json()).version).toMatchObject({ id: ids.v1, versionNumber: 1, status: 'in-effect' })

	// No version is in force before the first one took effect.
	const before = await admin.ctx.get(`${API}/governing-documents/${ids.statuten}/in-force`, { params: { date: '2023-06-01' } })
	expect(before.ok()).toBeTruthy()
	expect((await before.json()).version).toBeNull()
})

// @e2e governing-documents-register::a-decision-cites-a-statuten-article
test('a decision citing the document is listed as citing it', async () => {
	const citing = await createObject(admin, ledger, 'decision', {
		title: `${tag} besluit krachtens artikel 12`,
		text: 'Genomen krachtens artikel 12 van de statuten.',
		decisionType: 'motion',
		citesGoverningDocuments: [{ document: ids.statuten, article: '12' }],
	})
	const resp = await admin.ctx.get(`${API}/governing-documents/${ids.statuten}/cited-by`)
	const body = await resp.json()
	expect(resp.ok()).toBeTruthy()
	expect((body.results ?? []).map((row: any) => uuidOf(row))).toContain(uuidOf(citing))
})

// @e2e governing-documents-register::vereniging-publishes-its-statuten
test('the public view shows a public document and none of its internal fields', async () => {
	const resp = await anonymous.ctx.get(`${API}/public/governing-documents/${ids.statuten}`)
	expect(resp.ok()).toBeTruthy()
	const view = await resp.json()
	expect(view).toMatchObject({ citationTitle: `${tag} Statuten VNG`, type: 'articles-of-association' })
	expect(view.currentVersion).toMatchObject({ versionNumber: 1 })
	// The notes, the notary and draft versions are not part of the public view.
	expect(JSON.stringify(view)).not.toContain(`${tag} oprichtingsakte`)
	expect(view.currentVersion).not.toHaveProperty('notes')
	expect(view.currentVersion).not.toHaveProperty('notary')
})

// @e2e governing-documents-register::internal-document-stays-internal
test('an internal document is not shown publicly', async () => {
	const resp = await anonymous.ctx.get(`${API}/public/governing-documents/${ids.internal}`)
	expect(resp.status()).toBe(404)
})

// @e2e governing-documents-register::browse-and-filter-the-register
test('the register lists the document', async ({ page }) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/governing-documents`)
	await expect(page.getByText(`${tag} Statuten VNG`).first()).toBeVisible({ timeout: 20_000 })
})
