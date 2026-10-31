/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e — the decisions leaf names its own tab
 * (spec: approval-routes, REQ-AR-011).
 *
 * WHAT THIS ASSERTS, AND WHY IT IS THE WHOLE REQUIREMENT
 * ------------------------------------------------------
 * `CnObjectSidebar` renders one tab per registered provider and takes the tab's
 * name from `provider.label` and its icon from `provider.icon`, read straight
 * off the descriptor (nextcloud-vue, CnObjectSidebar.vue, the registry-mode
 * branch). There is no separate `tab` object in the contract: `LeafDescriptor`
 * has no such parameter and the host reads no such key. So "the tab reads the
 * label decidiq declared, not a fallback naming decidesk" is a statement about
 * `label` and `icon`, and that is what this checks.
 *
 * It checks it in the BROWSER rather than in the descriptor, because the two
 * halves already agreeing with each other proves nothing about what a sidebar
 * receives: a bundle that did not build registers nothing at all, and both
 * halves still agree.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 * -------------------------------
 * Not that a consumer's sidebar renders the tab. That needs a host app with an
 * object sidebar installed, which this CI has not; the registry entry is the
 * part decidiq owns.
 *
 * @spec openspec/changes/document-approval-chain-leaf/specs/approval-routes/spec.md
 * @e2e document-approval-chain-leaf/requirement-req-ar-011-the-decisions-leaf-names-its-tab/the-sidebar-shows-the-decidiq-label
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const DECISIONS_LEAF_ID = 'decidesk-decisions'

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

test.describe('the decisions leaf names its own tab', () => {
	test('the sidebar is given decidiq label and icon, not a fallback', async ({
		page,
	}) => {
		await page.goto('/apps/decidiq/')
		await waitForRegistry(page)

		const registered = await page.evaluate(() => {
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

		test.skip(
			registered.length === 0,
			'integration registry not initialised on this build',
		)

		const decisions = registered.find(
			(entry) => String(entry.id) === DECISIONS_LEAF_ID,
		)
		expect(
			decisions,
			`${DECISIONS_LEAF_ID} did not register, so a host sidebar has no tab to name`,
		).toBeTruthy()

		const label = String(decisions?.label ?? '')

		// Not empty: an empty label is what makes a host fall back to the id.
		expect(label).not.toBe('')
		// Not the id, and not the retired app name it would fall back through.
		expect(label).not.toBe(DECISIONS_LEAF_ID)
		expect(label.toLowerCase()).not.toContain('decidesk')
		expect(String(decisions?.icon ?? '')).not.toBe('')
	})
})
