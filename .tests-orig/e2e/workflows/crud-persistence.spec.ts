/*
 * SPDX-FileCopyrightText: 2026 Decidesk Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP, data-dependent e2e — full CRUD-with-persistence for Meeting and
 * Decision driven through the real SPA UI (manifest shell).
 *
 * This is NOT a render-only spec. Each test proves data actually persists:
 *   create -> row appears in the list -> open detail -> assert values ->
 *   delete -> assert the row is gone AND the object is gone from the backend.
 *
 * Create-through-UI nuance (deploy reality, 2026-06-10): the manifest-shell
 * "Create" form keeps its submit button disabled when a *required enum*
 * (NcSelect) field cannot commit its value to the form model under headless
 * Chromium — meeting (meetingType/meetingMode/lifecycle) and decision (outcome)
 * all have required enums. We therefore:
 *   - seed the row through the OpenRegister object API (the same backend the UI
 *     writes to) so the *persistence + read-back + detail + delete* path is
 *     exercised end-to-end through the UI, and
 *   - additionally drive the UI Create dialog and assert the form renders and
 *     accepts input, flagging the disabled-submit defect as a BUG (test.fixme).
 *
 * @e2e openspec/specs/meeting-management/spec.md#create-a-board-meeting-with-physical-location
 * @e2e openspec/specs/decision-management/spec.md#create-a-standalone-decision-outside-a-meeting
 */
import { test, expect } from '@playwright/test'
import {
	BASE,
	newLedger,
	createObject,
	getObject,
	cleanupAll,
	objId,
	type SeedLedger,
} from './governance-fixture'

let ledger: SeedLedger

test.beforeAll(() => {
	ledger = newLedger()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

/** Open a list page in the SPA and wait for the manifest shell to mount. */
async function gotoList(page, path: string) {
	await page.goto(`${BASE}/apps/decidesk/${path}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await page.waitForSelector('[data-testid="cn-object-list"]', { timeout: 15_000 })
}

/** Delete a tracked row through the UI row-action menu + confirm dialog. */
async function deleteRowViaUi(page, title: string): Promise<void> {
	const row = page.getByTestId('cn-object-row').filter({ hasText: title }).first()
	await expect(row, `row "${title}" should be present before delete`).toBeVisible({ timeout: 10_000 })
	await row.getByTestId('cn-row-actions').locator('button').first().click()
	await page.getByRole('menuitem', { name: 'Delete' }).click()
	const confirm = page.getByRole('dialog')
	await expect(confirm).toBeVisible({ timeout: 8_000 })
	await confirm.getByRole('button', { name: /^\s*Delete\s*$/ }).click()
	await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 8_000 })
}

// ── MEETING: full CRUD-with-persistence ──────────────────────────────────────

// @e2e openspec/specs/meeting-management/spec.md#create-a-board-meeting-with-physical-location
test('Meeting: create persists, appears in list, detail shows values, delete removes it', async ({ page }) => {
	const tag = `e2e-${ledger.runId}`
	const title = `${tag}-meeting-crud`

	// CREATE (backend write the UI itself performs — see file header for the
	// headless NcSelect caveat).
	const created = await createObject(page, ledger, 'meeting', {
		title,
		meetingType: 'committee',
		scheduledDate: '2026-10-15T14:30:00Z',
		meetingMode: 'hybrid',
		lifecycle: 'scheduled',
		location: 'Council Chamber A',
		quorumRequired: 5,
	})
	const id = objId(created)
	expect(id, 'created meeting should have an id').toBeTruthy()

	// READ: the row appears in the UI list.
	await gotoList(page, 'meetings')
	const row = page.getByTestId('cn-object-row').filter({ hasText: title }).first()
	await expect(row, 'newly created meeting row should appear in the list').toBeVisible({ timeout: 10_000 })

	// DETAIL: navigate to detail and assert the persisted values render.
	await page.goto(`${BASE}/apps/decidesk/meetings/${id}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await expect(page.getByText(title).first()).toBeVisible({ timeout: 10_000 })
	// Persisted enum + scalar values surface somewhere in the detail view.
	await expect(page.getByText('committee', { exact: false }).first()).toBeVisible()
	await expect(page.getByText('Council Chamber A', { exact: false }).first()).toBeVisible()

	// Re-assert persistence at the source of truth.
	const fetched = await getObject(page, 'meeting', id)
	expect(fetched?.title).toBe(title)
	expect(fetched?.meetingType).toBe('committee')
	expect(fetched?.meetingMode).toBe('hybrid')
	expect(Number(fetched?.quorumRequired)).toBe(5)

	// UPDATE: edit + persist. The manifest-shell *edit* dialog cannot save a
	// meeting (its own validator rejects the persisted space-formatted
	// scheduledDate — see the dedicated BUG test below), so we perform the
	// authoritative update through the OR API and assert the change persisted
	// and surfaces in the UI.
	const newTitle = `${title}-edited`
	// OR update = POST with the id in the body (verified: updates in place, no
	// duplicate).
	await createObject(page, ledger, 'meeting', {
		id,
		title: newTitle,
		meetingType: 'committee',
		scheduledDate: '2026-10-15T14:30:00Z',
		meetingMode: 'hybrid',
		lifecycle: 'scheduled',
		location: 'Council Chamber A',
		quorumRequired: 5,
	})
	await expect(async () => {
		const after = await getObject(page, 'meeting', id)
		expect(after?.title).toBe(newTitle)
	}).toPass({ timeout: 10_000 })
	await gotoList(page, 'meetings')
	await expect(
		page.getByTestId('cn-object-row').filter({ hasText: newTitle }).first(),
		'edited title should surface in the list',
	).toBeVisible({ timeout: 10_000 })

	// DELETE: remove through the UI and assert the row + object are gone.
	await deleteRowViaUi(page, newTitle)
	await expect(page.getByTestId('cn-object-row').filter({ hasText: newTitle })).toHaveCount(0, { timeout: 10_000 })
	const gone = await getObject(page, 'meeting', id)
	expect(gone, 'deleted meeting should no longer exist in the backend').toBeNull()
})

// @e2e openspec/specs/meeting-management/spec.md#create-a-board-meeting-with-physical-location
// BUG (decidesk, deploy-confirmed 2026-06-10): the manifest-shell EDIT dialog
// for a meeting cannot be saved. OpenRegister stores scheduledDate
// space-separated ("2026-10-15 14:30:00"), but the edit form re-validates that
// persisted value against the schema's `date-time` format and rejects it —
// raising a permanent alert ("'scheduledDate' should match format 'date-time'")
// that blocks Save, even when the user only changes the title. Re-entering the
// datetime-local value does not clear it (the control does not rebind the model
// value). Un-fixme once the edit form normalises / accepts the stored date-time.
test('Meeting edit dialog saves a title change without a scheduledDate format error', async ({ page }) => {
	const tag = `e2e-${ledger.runId}`
	const title = `${tag}-meeting-editbug`
	const created = await createObject(page, ledger, 'meeting', {
		title,
		meetingType: 'regular',
		scheduledDate: '2026-10-15T14:30:00Z',
		meetingMode: 'in-person',
		lifecycle: 'scheduled',
	})
	const id = objId(created)
	await gotoList(page, 'meetings')
	const row = page.getByTestId('cn-object-row').filter({ hasText: title }).first()
	await row.getByTestId('cn-row-actions').locator('button').first().click()
	await page.getByRole('menuitem', { name: 'Edit' }).click()
	const editDialog = page.getByRole('dialog')
	await expect(editDialog).toBeVisible({ timeout: 8_000 })
	await editDialog.locator('input[placeholder="Meeting title"]').fill(`${title}-edited`)
	// EXPECTED once fixed: no format alert, Save enabled, save succeeds.
	await expect(editDialog.getByRole('alert')).toHaveCount(0)
	await editDialog.getByRole('button', { name: /Save|Update/ }).click()
	await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 8_000 })
	const after = await getObject(page, 'meeting', id)
	expect(after?.title).toBe(`${title}-edited`)
})

// ── DECISION: full CRUD-with-persistence ─────────────────────────────────────

// @e2e openspec/specs/decision-management/spec.md#create-a-standalone-decision-outside-a-meeting
test('Decision: create persists, appears in list, detail shows values, delete removes it', async ({ page }) => {
	const tag = `e2e-${ledger.runId}`
	const title = `${tag}-decision-crud`

	// NOTE: the deployed `decision` schema (register 18 / schema 96) is the
	// besluit-style schema (title, decisionDate, decisionType, governingBody,
	// explanation, …) — it does NOT carry the `text`/`outcome` fields from the
	// repo's register JSON, so we assert on the fields that actually persist.
	const created = await createObject(page, ledger, 'decision', {
		title,
		explanation: `${tag} decision rationale text`,
		decisionDate: '2026-10-20T00:00:00Z',
	})
	const id = objId(created)
	expect(id, 'created decision should have an id').toBeTruthy()

	// READ: row appears in the UI list.
	await gotoList(page, 'decisions')
	await expect(
		page.getByTestId('cn-object-row').filter({ hasText: title }).first(),
		'newly created decision row should appear',
	).toBeVisible({ timeout: 10_000 })

	// DETAIL: navigate + assert persisted values.
	await page.goto(`${BASE}/apps/decidesk/decisions/${id}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })
	await expect(page.getByText(title).first()).toBeVisible({ timeout: 10_000 })

	const fetched = await getObject(page, 'decision', id)
	expect(fetched?.title).toBe(title)
	// decisionDate persists (normalised to date-only by the schema).
	expect(String(fetched?.decisionDate)).toContain('2026-10-20')

	// UPDATE via the OR API (the manifest-shell edit dialog cannot save a
	// decision — see the dedicated BUG test below), then assert persistence and
	// that the new title surfaces in the UI list.
	const newTitle = `${title}-edited`
	await createObject(page, ledger, 'decision', {
		id,
		title: newTitle,
		explanation: `${tag} decision rationale text`,
		decisionDate: '2026-10-20T00:00:00Z',
	})
	await expect(async () => {
		const after = await getObject(page, 'decision', id)
		expect(after?.title).toBe(newTitle)
	}).toPass({ timeout: 10_000 })
	await gotoList(page, 'decisions')
	await expect(
		page.getByTestId('cn-object-row').filter({ hasText: newTitle }).first(),
		'edited decision title should surface in the list',
	).toBeVisible({ timeout: 10_000 })

	// DELETE through the UI.
	await gotoList(page, 'decisions')
	await deleteRowViaUi(page, newTitle)
	await expect(page.getByTestId('cn-object-row').filter({ hasText: newTitle })).toHaveCount(0, { timeout: 10_000 })
	expect(await getObject(page, 'decision', id), 'deleted decision should be gone').toBeNull()
})

// ── BUG: UI Create dialog cannot submit (required NcSelect never commits) ─────

// @e2e openspec/specs/meeting-management/spec.md#create-a-board-meeting-with-physical-location
// BUG (decidesk, deploy-confirmed 2026-06-10): the manifest-shell Create form's
// "Create" button stays disabled even after every required field — including the
// required enum NcSelects (meetingType/meetingMode/lifecycle) — is filled and
// shows a selected chip. Selecting via mouse-click, keyboard (ArrowDown+Enter),
// and pre-filtered search all fail to commit the enum value to the form model,
// so a meeting/decision with required enum fields cannot be created through the
// UI at all. Un-fixme once the NcSelect value binds to the create-form model.
test('Meeting Create dialog submit enables once required enums are selected', async ({ page }) => {
	await gotoList(page, 'meetings')
	await page.getByTestId('cn-cta-primary').click()
	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 8_000 })

	await dialog.locator('input[placeholder="Meeting title"]').fill(`e2e-${ledger.runId}-ui-create`)
	// Fill the REQUIRED scheduledDate datetime field by its id (the form also
	// renders an optional `endDate` datetime, so target this one explicitly).
	await dialog.locator('#cn-form-scheduledDate').fill('2026-09-01T10:00')
	// Select every required enum (meetingType / meetingMode / lifecycle). Each
	// must commit its value to the form model for the Create button to enable.
	const selects = dialog.locator('input[type="search"]')
	const selectCount = await selects.count()
	for (let i = 0; i < selectCount; i++) {
		await selects.nth(i).click()
		await page.waitForTimeout(300)
		await page.getByRole('option').first().click()
		await page.waitForTimeout(200)
	}
	// EXPECTED once fixed: the selected enums commit, so the Create button enables.
	await expect(dialog.getByRole('button', { name: 'Create' })).toBeEnabled({ timeout: 5_000 })
})

// @e2e openspec/specs/decision-management/spec.md#create-a-standalone-decision-outside-a-meeting
// BUG (decidesk, deploy-confirmed 2026-06-10): the manifest-shell EDIT dialog
// for a decision cannot be saved either. Its first text input is the `case`
// field (a uuid-format relation), and the form re-validates the persisted
// values, raising format alerts (e.g. "'case' should match format 'uuid'") that
// block Save. Un-fixme once the decision edit form binds title correctly and
// stops rejecting its own persisted relation/date values.
test('Decision edit dialog saves a title change without a format error', async ({ page }) => {
	const tag = `e2e-${ledger.runId}`
	const title = `${tag}-decision-editbug`
	const created = await createObject(page, ledger, 'decision', {
		title,
		explanation: 'x',
		decisionDate: '2026-10-20T00:00:00Z',
	})
	const id = objId(created)
	await gotoList(page, 'decisions')
	const row = page.getByTestId('cn-object-row').filter({ hasText: title }).first()
	await row.getByTestId('cn-row-actions').locator('button').first().click()
	await page.getByRole('menuitem', { name: 'Edit' }).click()
	const editDialog = page.getByRole('dialog')
	await expect(editDialog).toBeVisible({ timeout: 8_000 })
	// EXPECTED once fixed: a labelled title field exists and editing it + saving
	// raises no format alert.
	await editDialog.getByRole('textbox', { name: /title/i }).fill(`${title}-edited`)
	await expect(editDialog.getByRole('alert')).toHaveCount(0)
	await editDialog.getByRole('button', { name: /Save|Update/ }).click()
	await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 8_000 })
	expect((await getObject(page, 'decision', id))?.title).toBe(`${title}-edited`)
})
