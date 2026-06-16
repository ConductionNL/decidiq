/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — admin-settings spec (governance body management)
 *
 * The governance-bodies list view + its "Add GovernanceBody" create dialog
 * are a real, working UI surface. These tests assert the live dialog fields
 * for the body-create and quorum-config scenarios. The remaining admin-settings
 * scenarios (role assignment, template assignment, org defaults, group/CSV
 * import) carry honest per-scenario @e2e exclude annotations in the spec — no
 * UI exists for them.
 *
 * @e2e openspec/specs/admin-settings/spec.md#create-a-governing-body-for-an-association-board
 * @e2e openspec/specs/admin-settings/spec.md#configure-quorum-rules-for-a-body
 */
import { test, expect } from '@playwright/test'

const BASE = process.env.NEXTCLOUD_URL || 'http://localhost:8080'

// @e2e openspec/specs/admin-settings/spec.md#create-a-governing-body-for-an-association-board
test('governance bodies list renders with Add GovernanceBody button', async ({ page }) => {
	await page.goto(`${BASE}/apps/decidesk/governance-bodies`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	// List loads with the "Showing N of N" indicator
	await expect(page.getByText('Showing')).toBeVisible()

	// The create CTA is present
	await expect(page.getByRole('button', { name: 'Add GovernanceBody' })).toBeVisible()
})

// @e2e openspec/specs/admin-settings/spec.md#create-a-governing-body-for-an-association-board
test('Create GovernanceBody dialog opens with name, bodyType and domain required fields', async ({ page }) => {
	await page.goto(`${BASE}/apps/decidesk/governance-bodies`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	await page.getByRole('button', { name: 'Add GovernanceBody' }).click()

	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 8_000 })
	await expect(dialog.getByRole('heading', { name: 'Create GovernanceBody' })).toBeVisible()

	// Required identity fields
	await expect(dialog.getByRole('textbox', { name: 'name *' })).toBeVisible()
	await expect(dialog.getByRole('textbox', { name: 'domain *' })).toBeVisible()
	// bodyType is a required combobox (council/board/assembly/committee/team semantics)
	await expect(dialog.getByText('bodyType *', { exact: false })).toBeVisible()

	// Create is disabled until the required fields are filled
	await expect(dialog.getByRole('button', { name: 'Create' })).toBeDisabled()

	// Cancel closes the dialog
	await dialog.getByRole('button', { name: 'Cancel' }).click()
	await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 5_000 })
})

// @e2e openspec/specs/admin-settings/spec.md#configure-quorum-rules-for-a-body
test('Create GovernanceBody dialog exposes the quorumRule configuration field', async ({ page }) => {
	await page.goto(`${BASE}/apps/decidesk/governance-bodies`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	await page.getByRole('button', { name: 'Add GovernanceBody' }).click()

	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 8_000 })

	// quorumRule field — the quorum calculation method configured per body
	await expect(dialog.getByRole('textbox', { name: 'quorumRule' })).toBeVisible()
	// votingDefault and workflowTemplate are also configurable on the body
	await expect(dialog.getByRole('textbox', { name: 'votingDefault' })).toBeVisible()
	await expect(dialog.getByRole('textbox', { name: 'workflowTemplate' })).toBeVisible()

	await dialog.getByRole('button', { name: 'Cancel' }).click()
	await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 5_000 })
})
