/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the administrator reads who may change minutes on Rights
 * per record type, adds a group to the record administrators role, and the
 * row for Minutes names that group after saving (change
 * platform-role-rights-per-record-type, matrix row plt-03). That the
 * re-imported rules let a member of the group edit a minutes record is
 * covered by RoleGroupMappingTest over the real register; the live check in
 * the PR does it end to end.
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import { adminActor, anonymousActor } from './support/api-actors.ts'

const RIGHTS = `${BASE}/index.php/apps/decidiq/api/settings/role-rights`

// @e2e authorization-via-or-rbac::the-administrator-checks-who-can-edit-minutes
test('the administrator maps a group to a role and the minutes row names it', async ({
	page,
	playwright,
}) => {
	const { ctx: admin } = await adminActor(playwright)
	const groups = (await (await admin.get(RIGHTS)).json()).groups as string[]
	test.skip(!groups.includes('admin'), 'the instance has no admin group to map')

	await page.goto(`${BASE}/index.php/settings/admin/decidiq`)
	const panel = page.getByTestId('role-rights-settings')
	await expect(panel).toBeVisible()
	await expect(panel.getByTestId('role-rights-row-minutes')).toContainText(
		'decidiq-administrators',
	)

	const saved = await admin.put(RIGHTS, {
		data: { mapping: { administrators: ['admin'] } },
	})
	expect(saved.status()).toBe(200)
	await page.reload()
	await expect(page.getByTestId('role-rights-row-minutes')).toContainText('admin')

	expect(
		(
			await admin.put(RIGHTS, {
				data: { mapping: { administrators: ['no-such-group-e2e'] } },
			})
		).status(),
	).toBe(400)
	await admin.put(RIGHTS, { data: { mapping: {} } })

	const { ctx: anonymous } = await anonymousActor(playwright)
	expect((await anonymous.get(RIGHTS)).status()).toBeGreaterThanOrEqual(401)
})
