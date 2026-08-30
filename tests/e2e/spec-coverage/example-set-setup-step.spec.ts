/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ADR-042 / ADR-111 — the example-set setup steps, against a running instance.
 *
 * WHY THIS EXISTS. The programme that added demo data to this fleet shipped a
 * defect that every unit test passed: the import printed `register "…"
 * imported.` and seeded ZERO of the descriptor's objects. The unit tests could
 * not see it — they mock the import service, so they validate the CALL and
 * never its effect.
 *
 * So the assertion that matters here is not "the endpoint answers 200". It is
 * that the response NAMES WHAT LANDED. A success message that cannot be told
 * apart from an import that wrote nothing is exactly what let that defect
 * through.
 *
 * WHAT CHANGED. Installing this app used to plant 334 objects nobody asked
 * for: every register.d fragment carried its own seedData and the configuration
 * import merged all of them. The objects now live in `lib/Settings/profiles/`,
 * one file per example set, and a bare install plants nothing. The wizard asks
 * WHICH set, then loads it — two steps rather than one, because
 * `CnSetupWizard::runAction()` posts no body and so cannot carry the answer.
 *
 * WHY THE API AND NOT A CLICK-THROUGH. `CnAppRoot` opens the optional wizard
 * only while an optional step is outstanding, and the CI seed deliberately
 * settles those so the wizard stops covering the app in every test. The
 * observable surface for this capability is therefore the contract the wizard
 * calls — `GET /api/setup/status`, `POST /api/setup/config` and
 * `POST /api/setup/action/{id}` — issued from inside the authenticated admin
 * page so every call carries the real session and `OC.requestToken` through
 * Nextcloud's `AuthorizedAdminSetting` middleware. A unit test with a mocked
 * IAppConfig cannot show that middleware admitting the request; this can.
 *
 * WHAT THIS DELIBERATELY DOES NOT ASSERT. That an example-set step comes FIRST
 * is a property of the manifest, which the app bundles rather than serves, so
 * it is not observable from here; a static gate checks it on every change.
 * That importing a set leaves the register row untouched is likewise invisible
 * from the browser: it is a property of the descriptor shape (no
 * `components.registers`), asserted in RegisterJsonTest and verified against a
 * live instance when the shape was chosen.
 *
 * @spec exclude ADR-042/ADR-111 setup contract; no per-app behavioural spec.
 */
import { test, expect, type Page } from '@playwright/test'
import * as path from 'path'

const STORAGE_STATE = path.resolve(__dirname, '../.auth/admin.json')

const BASE = '/apps/decidiq'

/** One authenticated JSON call issued from inside the logged-in admin page. */
async function api(
	page: Page,
	method: string,
	apiPath: string,
	body?: unknown,
): Promise<{ status: number; json: any }> {
	return await page.evaluate(
		async ({ method, apiPath, body }) => {
			const res = await fetch(apiPath, {
				method,
				headers: {
					'Content-Type': 'application/json',
					// eslint-disable-next-line no-undef
					requesttoken: (window as any).OC?.requestToken || '',
					'OCS-APIREQUEST': 'true',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			let json: any = null
			try {
				json = await res.json()
			} catch {
				json = null
			}
			return { status: res.status, json }
		},
		{ method, apiPath, body },
	)
}

test.describe.configure({ mode: 'serial' })

test.describe('example sets', () => {
	// The setup contract lives behind the admin middleware, so these calls need
	// the real logged-in session `globalSetup` captured — not the suite's
	// default Basic-auth header, which does not produce an `OC.requestToken`.
	test.use({ storageState: STORAGE_STATE })

	test.beforeEach(async ({ page }) => {
		await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' })
		await page.waitForFunction(() => (window as any).OC?.requestToken, null, {
			timeout: 15000,
		})
	})

	test('setup status reports both steps, so the wizard can offer them', async ({
		page,
	}) => {
		const res = await api(page, 'GET', `${BASE}/api/setup/status`)

		expect(res.status, 'setup/status must answer an authenticated admin').toBe(
			200,
		)

		// A step the endpoint never MENTIONS resolves to `done: false` forever —
		// no operator action can clear it, and CnAppRoot then covers the app with
		// the wizard in every fresh browser context. Absence is the defect here,
		// not "not done".
		const steps = Object.keys(res.json?.steps ?? {})
		expect(steps, 'setup/status must report the choice step').toContain(
			'example-set',
		)
		expect(steps, 'setup/status must report the load step').toContain(
			'load-example-set',
		)
	})

	test('setup status offers the sets the app actually ships', async ({
		page,
	}) => {
		const res = await api(page, 'GET', `${BASE}/api/setup/status`)

		const profiles = res.json?.profiles ?? []
		expect(
			profiles.length,
			'the app must offer at least one example set',
		).toBeGreaterThan(0)

		// The wizard's own options come from the bundled manifest, but the
		// server reads the descriptors on disk. Both listing municipality is what
		// makes the choice step's value resolvable at the next step.
		const ids = profiles.map((p: any) => p.id)
		expect(ids).toContain('municipality')

		// A set that names no object count cannot be told apart from an empty
		// one, and an empty set is the thing this whole change exists to stop
		// shipping silently.
		const municipality = profiles.find((p: any) => p.id === 'municipality')
		expect(municipality.objectCount).toBeGreaterThan(0)
		expect(String(municipality.label ?? '')).not.toBe('')
	})

	test('a set that does not exist is refused rather than stored', async ({
		page,
	}) => {
		// Storing it would leave the load step pointing at nothing, so the
		// failure would surface one step later with no clue why.
		const res = await api(page, 'POST', `${BASE}/api/setup/config`, {
			example_profile: 'atlantis',
		})

		expect(res.status).toBe(400)
		expect(res.json?.success).toBe(false)
	})

	test('loading before choosing refuses rather than guessing a set', async ({
		page,
	}) => {
		// 🔴 NO SILENT DEFAULT. This is the failure the change exists to remove:
		// planting a municipality into an operator's register because nobody
		// asked them first. Clearing the choice proves the server refuses rather
		// than falling back to one.
		await api(page, 'POST', `${BASE}/api/setup/config`, {
			example_profile: 'none',
		})
		const status = await api(page, 'GET', `${BASE}/api/setup/status`)

		// "None" is an ANSWER: both steps close, and nothing is imported.
		expect(status.json?.steps?.['example-set']?.done).toBe(true)
		expect(status.json?.steps?.['load-example-set']?.done).toBe(true)
	})

	test('loading a chosen set reports HOW MUCH landed, not just success', async ({
		page,
	}) => {
		// 🔴 A REAL IMPORT, NOT A STUB. Measured on this fleet: the install arm
		// took 42.8s on dossiq and 49.6s on shillinq, and exceeded the 30s
		// default on one run. The operation is legitimately slow, and the
		// assertion is worth its cost: it is the only check that the load WROTE
		// something.
		test.slow()

		const chosen = await api(page, 'POST', `${BASE}/api/setup/config`, {
			example_profile: 'works-council',
		})
		expect(chosen.json?.success, JSON.stringify(chosen.json)).toBe(true)

		const res = await api(
			page,
			'POST',
			`${BASE}/api/setup/action/load-example-set`,
		)

		expect(res.status, 'the action must pass the admin middleware').toBe(200)
		expect(res.json?.success, `load failed: ${JSON.stringify(res.json)}`).toBe(
			true,
		)

		// 🔴 THE COUNTS ARE THE ASSERTION. "Example data loaded" with no numbers
		// is indistinguishable from an import that wrote nothing — the exact
		// defect this programme shipped and had to fix.
		const message = String(res.json?.message ?? '')
		const numbers = (message.match(/\d+/g) ?? []).map(Number)

		expect(
			numbers.some((n) => n > 0),
			`the load message must name a non-zero object count; got: "${message}"`,
		).toBe(true)
	})

	test('re-loading is safe, because the step promises it is', async ({
		page,
	}) => {
		// The step body tells the operator it is "safe to run more than once".
		// That sentence is a contract; this asserts the server keeps it rather
		// than erroring or reporting failure on a second pass.
		const again = await api(
			page,
			'POST',
			`${BASE}/api/setup/action/load-example-set`,
		)

		expect(again.status).toBe(200)
		expect(
			again.json?.success,
			`a second load must not fail: ${JSON.stringify(again.json)}`,
		).toBe(true)
	})
})
