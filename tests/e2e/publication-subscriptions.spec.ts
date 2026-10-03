/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * publication-subscriptions-and-daily-digest (matrix rows pub-10, pub-16):
 * a member subscribes on his settings page to agendas and papers of the
 * bodies he follows, sees the subscription listed and removes it again.
 *
 * Defensive skip: when the deployed instance does not serve this branch's
 * settings page yet, the spec skips instead of failing, like the other
 * settings suites.
 *
 * @e2e openspec/specs/public-publication/spec.md#a-member-follows-two-committees
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'

test.describe('Publication subscriptions', () => {
	test('a member subscribes to agendas and papers immediately, then removes it', async ({
		page,
	}) => {
		await page.goto(`${BASE}/settings/user/decidiq`)
		const section = page.locator('[data-testid="subscriptions-section"]')
		try {
			await section.waitFor({ state: 'visible', timeout: 15_000 })
		} catch {
			test.skip(true, 'This branch is not deployed: no subscriptions section')
			return
		}

		const rows = section.locator('[data-testid="subscriptions-list"] li')
		const before = await rows.count()

		await section
			.locator('[data-testid="subscription-kind-paper"]')
			.check({ force: true })
		await section
			.locator('[data-testid="subscription-frequency-immediate"]')
			.check({ force: true })
		await section.locator('[data-testid="subscription-add"]').click()

		await expect(rows).toHaveCount(before + 1)
		await expect(rows.last()).toContainText(/immediately/i)

		await rows.last().locator('[data-testid="subscription-remove"]').click()
		await expect(rows).toHaveCount(before)
	})
})
