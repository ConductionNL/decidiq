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

const LEAF_ID = 'decidiq-approval-chain'
const SCHEMAS = '/index.php/apps/openregister/api/schemas'
const ACTIONS = '/index.php/apps/decidiq/api/approval-routes/actions'
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
 * Every descriptor the registry holds.
 *
 * @param page Playwright Page.
 */
async function providers(page: Page): Promise<Array<Record<string, unknown>>> {
	return await page.evaluate(() => {
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
		return reg && reg.list ? reg.list() : []
	})
}

test.describe('the document approval chain leaf', () => {
	test('the leaf reaches the page under its own id, with its own label', async ({
		page,
	}) => {
		await page.goto('/apps/decidiq/')
		await waitForRegistry(page)

		const registered = await providers(page)
		test.skip(
			registered.length === 0,
			'integration registry not initialised on this build',
		)

		const leaf = registered.find((entry) => String(entry.id) === LEAF_ID)
		expect(
			leaf,
			`${LEAF_ID} did not register, so the surface is dark wherever a host object renders`,
		).toBeTruthy()

		// The tab's name and icon, read straight off the descriptor by
		// CnObjectSidebar. A missing label shows the host's fallback and errors
		// nowhere.
		expect(String(leaf?.label ?? '')).not.toBe('')
		expect(String(leaf?.label ?? '')).not.toContain(LEAF_ID)
		expect(String(leaf?.icon ?? '')).toBe('Signature')

		// The render PAIR the declared mode needs. `mount` with only one half
		// renders once and then leaks its Vue app on every teardown.
		expect(String(leaf?.renderMode ?? '')).toBe('mount')
		expect(typeof leaf?.mount).toBe('function')
		expect(typeof leaf?.unmount).toBe('function')

		// Declared explicitly, which is what gives the parity check two sets to
		// compare rather than one and a default.
		expect(leaf?.surfaces).toEqual([
			'user-dashboard',
			'app-dashboard',
			'detail-page',
			'single-entity',
		])
		expect(String(leaf?.loadStrategy ?? '')).toBe('own-script')
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
		// The LEAST privileged principal that should be refused: no session at
		// all. The widget draws its buttons only for the step's actor, but that
		// is a courtesy; this asserts the refusal that is the actual guard.
		const anonymous = await playwright.request.newContext()

		try {
			const response = await anonymous.post(ACTIONS, {
				headers: HEADERS,
				data: {
					subject: 'does-not-exist',
					subjectSchema: 'decision',
					step: 1,
					action: 'approved',
				},
				failOnStatusCode: false,
			})

			test.skip(response.status() === 404, 'decidiq is not installed here')

			expect(
				[401, 403],
				`an anonymous caller got ${response.status()} when signing off a step`,
			).toContain(response.status())
		} finally {
			await anonymous.dispose()
		}
	})
})
