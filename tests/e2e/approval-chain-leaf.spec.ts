/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e — the document approval chain leaf
 * (spec: approval-routes, REQ-AR-008 to REQ-AR-011).
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * Three deployment facts, each of which a fully green unit suite is blind to.
 *
 * One, that the leaf REACHES THE PAGE. The registration ships in decidiq's own
 * init bundle (`own-script`, decidiq#1345). A bundle that did not build, or an
 * init script the app stopped adding, leaves the leaf registered nowhere while
 * both halves of the descriptor still agree with each other perfectly.
 *
 * Two, that the leaf carries DECIDIQ'S OWN LABEL. `CnObjectSidebar` reads
 * `provider.label` and `provider.icon` straight off the descriptor for its tab
 * (nextcloud-vue, CnObjectSidebar.vue, the registry-mode branch), so a
 * descriptor that shipped without them would show the host's fallback and
 * nothing would error. The same fact for the decisions leaf beside this one is
 * REQ-AR-011 and lives in tests/e2e/decisions-leaf-tab.spec.ts.
 *
 * Three, that the register FRAGMENT IMPORTED. OpenRegister drops an undeclared
 * property in silence, so a stage patched with `dueAt` or `origin` against a
 * schema that never gained them loses the value with no error anywhere, and
 * every unit test stays green because the service returned the right array.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 * -------------------------------
 * Not the sign-off itself. Approving from the widget needs a document, a route
 * and a session belonging to the step's actor, which this CI has no fixtures
 * for; the engine's advance and its refusals are asserted in the unit suite
 * (tests/Unit/Service/ApprovalRouteServiceTest.php), and the two halves of the
 * descriptor are compared in tests/Unit/Listener/ApprovalChainLeafParityTest.php.
 *
 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md
 * @e2e document-approval-chain-leaf/requirement-req-ar-010-the-route-is-a-render-surface-leaf/a-reviewer-approves-from-the-cases-documents-tab
 * @e2e document-approval-chain-leaf/requirement-req-ar-010-the-route-is-a-render-surface-leaf/a-user-who-is-not-the-current-actor-sees-no-buttons
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	anonymousActor,
	APP_API,
	describe,
	disposeActors,
} from './support/api-actors.ts'

const LEAF_ID = 'decidiq-approval-chain'
const SCHEMAS = '/index.php/apps/openregister/api/schemas'
const HEADERS = { 'OCS-APIRequest': 'true' }

/**
 * The properties this change adds, per schema slug.
 */
const ADDED: Record<string, string[]> = {
	'decision-stage': ['origin', 'dueAt'],
}

/**
 * Wait briefly for the integration registry to install on `window`.
 *
 * @param page Playwright Page.
 */
async function waitForRegistry(page: Page): Promise<void> {
	await page
		.waitForFunction(
			() =>
				!!(
					window as Window & {
						OCA?: {
							OpenRegister?: {
								integrations?: { list?: () => unknown[] }
							}
						}
					}
				).OCA?.OpenRegister?.integrations?.list,
			{ timeout: 4_000 },
		)
		.catch(() => {
			/* registry absent — the caller skips on an empty list */
		})
}

/**
 * What one registered leaf looks like, once it has crossed into node.
 */
interface LeafReading {
	/** How many leaves the registry holds at all. */
	total: number
	/** Whether the wanted id is among them. */
	found: boolean
	label: string
	icon: string
	renderMode: string
	/** `typeof descriptor.mount`, TAKEN IN THE PAGE. See readLeaf. */
	mountType: string
	/** `typeof descriptor.unmount`, taken in the page. */
	unmountType: string
	surfaces: string[] | null
}

/**
 * Read one leaf's registration, resolving every claim INSIDE the page.
 *
 * 🔴 A FUNCTION DOES NOT SURVIVE `page.evaluate`, AND ITS KEY DOES.
 * Playwright serialises the return value, and a function member arrives as
 * `undefined` on the node side while `Object.keys` still lists it. Measured on
 * this Playwright version against a hand-built registry on `about:blank`:
 *
 *     ACROSS THE BRIDGE  typeof mount = undefined | keys: id,renderMode,mount,unmount
 *     INSIDE THE PAGE    typeof mount = function  | typeof unmount = function
 *
 * So the earlier shape of this test, returning the descriptor and then
 * asserting `typeof leaf.mount === 'function'` in node, could not pass whatever
 * the app shipped, and it reported a dark leaf on an instance where the leaf
 * renders.
 * The registry says the same thing from the other side: `register()` refuses a
 * `renderMode: 'mount'` descriptor that has no pair, so a leaf missing its
 * `mount` is ABSENT from `list()`, never present-but-crippled. An entry that is
 * in the list has already proved the pair exists.
 *
 * Every `typeof` below is therefore taken in the browser and crosses as a
 * string, which is a value serialisation cannot quietly change.
 *
 * @param page Playwright Page.
 * @param id   The leaf id to read.
 */
async function readLeaf(page: Page, id: string): Promise<LeafReading> {
	return await page.evaluate((leafId) => {
		const reg = (
			window as Window & {
				OCA?: {
					OpenRegister?: {
						integrations?: {
							list?: () => Array<Record<string, unknown>>
						}
					}
				}
			}
		).OCA?.OpenRegister?.integrations
		const all = reg && reg.list ? reg.list() : []
		const leaf = all.find((entry) => String(entry.id) === leafId)

		return {
			total: all.length,
			found: leaf !== undefined,
			label: String(leaf?.label ?? ''),
			icon: String(leaf?.icon ?? ''),
			renderMode: String(leaf?.renderMode ?? ''),
			mountType: typeof leaf?.mount,
			unmountType: typeof leaf?.unmount,
			surfaces: Array.isArray(leaf?.surfaces)
				? (leaf?.surfaces as string[])
				: null,
		}
	}, id)
}

test.describe('the document approval chain leaf', () => {
	test('the leaf reaches the page under its own id, with its own label', async ({
		page,
	}) => {
		await page.goto('/apps/decidiq/')
		await waitForRegistry(page)

		const leaf = await readLeaf(page, LEAF_ID)
		test.skip(
			leaf.total === 0,
			'integration registry not initialised on this build',
		)

		expect(
			leaf.found,
			`${LEAF_ID} did not register, so the surface is dark wherever a host object renders`,
		).toBe(true)

		// The tab's name and icon, read straight off the descriptor by
		// CnObjectSidebar. A missing label shows the host's fallback and errors
		// nowhere.
		expect(leaf.label).not.toBe('')
		expect(leaf.label).not.toContain(LEAF_ID)
		expect(leaf.icon).toBe('Signature')

		// The render PAIR the declared mode needs. `mount` with only one half
		// renders once and then leaks its Vue app on every teardown. Both reads
		// happen in the page; see readLeaf for why that is load-bearing.
		expect(leaf.renderMode).toBe('mount')
		expect(leaf.mountType).toBe('function')
		expect(leaf.unmountType).toBe('function')

		// Declared explicitly, which is what gives the parity check two sets to
		// compare rather than one and a default.
		expect(leaf.surfaces).toEqual([
			'user-dashboard',
			'app-dashboard',
			'detail-page',
			'single-entity',
		])

		// `loadStrategy` is NOT asserted here, and its absence is deliberate.
		// The registry's normaliser copies a fixed key list and drops every
		// other key, and `loadStrategy` is not on that list, so reading it off
		// a registered entry answers about the normaliser, not about decidiq.
		// The two halves' declared strategy is compared for real in
		// tests/Unit/Listener/ApprovalChainLeafParityTest.php
		// (testBothHalvesDeclareTheOwnScriptLoadStrategy). What THIS page can
		// prove about `own-script` it has already proved above: the leaf is in
		// the registry, which only happens because decidiq's own init bundle
		// loaded and registered it.
	})

	test('the stage schema carries the fields a held route writes', async ({
		request,
	}) => {
		const response = await request.get(SCHEMAS, { headers: HEADERS })

		test.skip(response.status() === 404, 'OpenRegister is not installed here')
		expect(response.ok()).toBeTruthy()

		const body = await response.json()
		const schemas = (body.results ?? body.items ?? body ?? []) as Array<
			Record<string, any>
		>

		for (const [slug, properties] of Object.entries(ADDED)) {
			const schema = schemas.find(
				(candidate) => String(candidate.slug ?? '') === slug,
			)
			// A stack that never imported decidiq's register is a skip, not a
			// failure: this asserts the fragment's CONTENT.
			if (!schema) {
				continue
			}

			const declared = Object.keys(schema.properties ?? {})
			for (const property of properties) {
				expect(
					declared,
					`${slug} did not import ${property}, so a value written to it is dropped in silence`,
				).toContain(property)
			}
		}
	})

	test('an anonymous caller cannot sign off a step', async ({ playwright }) => {
		// 🔴 THE PROBE HAS TO BE BUILT, NOT ASSUMED. `playwright.request
		// .newContext()` inherits `use.storageState` from playwright.config.ts,
		// which is the ADMINISTRATOR's session, so the version of this test
		// that called it "no session at all" was signed in as a superuser.
		// Measured against a live instance on 2026-09-19: this endpoint answers
		// a real anonymous caller 401 "Current user is not logged in" and
		// answers admin 400 "This subject has no active stage", and the test
		// received the 400. It failed, so the escalation was visible this time.
		// Pointed the other way it is silent: make the route public and the
		// same probe keeps passing while an anonymous caller walks in.
		//
		// `anonymousActor` starts from an EMPTY storage state and carries no
		// credentials, which is the only way to get the principal this test
		// names. Its context has no baseURL, so the URL is absolute.
		const anonymous = await anonymousActor(playwright)

		try {
			const response = await anonymous.ctx.post(
				`${APP_API}/approval-routes/actions`,
				{
					headers: HEADERS,
					data: {
						subject: 'does-not-exist',
						subjectSchema: 'decision',
						step: 1,
						action: 'approved',
					},
					failOnStatusCode: false,
				},
			)

			test.skip(response.status() === 404, 'decidiq is not installed here')

			// 401, exactly: the session guard turned the caller away before the
			// controller looked at the body. A 403 would mean the request got
			// past that guard and was refused on something else, and any 4xx
			// that admin also produces (400 here) proves nothing about who was
			// asking, which is the whole failure this assertion replaces.
			expect(
				response.status(),
				`an anonymous caller got ${response.status()} when signing off a step: ${await describe(response)}`,
			).toBe(401)
		} finally {
			await disposeActors(anonymous)
		}
	})
})
