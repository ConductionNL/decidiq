/*
 * SPDX-FileCopyrightText: 2026 Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * p3-citizen-participation: the Consultations hub and reaction moderation,
 * driven through the SPA as a staff user.
 *
 * The page runs as the suite's admin session (the project storageState). The
 * rows each test needs are seeded, and every outcome is read back, through a
 * SEPARATE admin request context (../support/api-actors.ts): what the UI did is
 * checked in the store it wrote to, not in the DOM that reported it.
 *
 * Reactions are seeded directly as `pending` objects rather than submitted
 * through the intake endpoint. Moderation is what these scenarios are about;
 * how a reaction arrives is covered in api.spec.ts.
 */

import type { Page } from '@playwright/test'
import type { Actor } from '../support/api-actors.ts'

import { expect, test } from '@playwright/test'
import {
	adminActor,
	appPost,
	BASE,
	createObject,
	daysFromNow,
	describe as describeResponse,
	disposeActors,
	newRunId,
	ObjectLedger,
	readObject,
	uuidOf,
} from '../support/api-actors.ts'

const RUN = newRunId()
const TAG = `e2e-hub-${RUN}`

let admin: Actor
const ledger = new ObjectLedger([
	'consultation-reaction',
	'budget-proposal',
	'participatory-budget',
	'public-consultation',
])

// Every test here loads the SPA at least once and then waits on a network
// round-trip it caused. The per-navigation timeouts below are explicit, so a
// slow load is named as one instead of surfacing as a bare test timeout.
test.describe.configure({ timeout: 60_000 })

test.beforeAll(async ({ playwright }) => {
	admin = await adminActor(playwright)
})

test.afterAll(async () => {
	test.setTimeout(90_000)
	await ledger.cleanup(admin)
	await disposeActors(admin)
})

/**
 * A consultation, created by staff.
 *
 * @param fields Overrides.
 * @return Its UUID.
 */
async function consultation(fields: Record<string, unknown> = {}): Promise<string> {
	const obj = await createObject(admin, ledger, 'public-consultation', {
		moderationPolicy: 'pre-moderation',
		status: 'open',
		submissionDeadline: daysFromNow(14),
		title: `${TAG}-consultation`,
		...fields,
	})
	return uuidOf(obj)
}

/**
 * A pending reaction on a consultation.
 *
 * @param consultationId The consultation it belongs to.
 * @param body           Its text, which the tests locate it by.
 * @return Its UUID.
 */
async function pendingReaction(
	consultationId: string,
	body: string,
): Promise<string> {
	const obj = await createObject(admin, ledger, 'consultation-reaction', {
		body,
		moderationStatus: 'pending',
		relations: [
			{
				id: consultationId,
				register: 'decidiq',
				schema: 'public-consultation',
			},
		],
		submittedAt: new Date().toISOString(),
		submitterId: `${TAG}-citizen`,
	})
	return uuidOf(obj)
}

/**
 * A reaction as staff now read it.
 *
 * @param id The reaction.
 * @return The stored object.
 */
async function storedReaction(id: string): Promise<any> {
	const resp = await readObject(admin, 'consultation-reaction', id)
	expect(resp.status(), await describeResponse(resp)).toBe(200)
	return resp.json()
}

/**
 * Open an SPA route and wait until the given element is on screen.
 *
 * @param page   The page.
 * @param route  The path under /apps/decidiq.
 * @param testId The data-testid that proves the view mounted.
 * @return Resolves once it is visible.
 */
async function open(page: Page, route: string, testId: string): Promise<void> {
	await page.goto(`${BASE}/apps/decidiq${route}`, { timeout: 30_000 })
	await expect(page.getByTestId(testId).first()).toBeVisible({ timeout: 30_000 })
}

/**
 * A quick-filter option by its label.
 *
 * NcSelect renders a long label through NcEllipsisedOption, which splits it
 * into two text nodes, so the option's accessible name comes out as
 * "Citizen par ticipation". The pattern allows a break between any two
 * characters and nothing else.
 *
 * @param page  The page.
 * @param label The option label as the manifest spells it.
 * @return The option locator.
 */
function option(page: Page, label: string) {
	const pattern = label
		.split('')
		.map((c) => c.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
		.join('\\s?')
	return page.getByRole('option', { name: new RegExp(`^${pattern}$`) })
}

test.describe('Consultations hub', () => {
	// @e2e p3-citizen-participation::type-tabs-live-in-the-action-bar
	// @e2e p3-citizen-participation::filtering-by-type-returns-only-that-type
	test('the type filter sits in the action bar, and choosing Tenders lists tenders only, filtered by the server', async ({
		page,
	}) => {
		const tenderId = await consultation({
			consultationType: 'tender',
			status: 'published',
			title: `${TAG}-tender`,
		})
		const citizenId = await consultation({
			consultationType: 'citizen-participation',
			title: `${TAG}-citizen`,
		})

		await open(page, '/consultations', 'cn-index-page')

		// Inside the action bar, not in a row of its own.
		const filter = page
			.getByTestId('cn-actions-bar')
			.locator('.cn-quick-filter-bar')
		await expect(filter).toBeVisible()
		await filter.getByRole('combobox').click()
		for (const label of [
			'Citizen participation',
			'Market consultations',
			'Tenders',
			'Idea box',
			'Participatory budgets',
		]) {
			await expect(option(page, label)).toBeVisible()
		}

		const refetch = page.waitForResponse(
			(resp) => {
				const url = decodeURIComponent(resp.url())
				return (
					resp.request().method() === 'GET'
					&& url.includes('public-consultation')
					&& url.includes('consultationType')
				)
			},
			{ timeout: 30_000 },
		)
		await option(page, 'Tenders').click()
		const response = await refetch

		// The filter is the SERVER's: the request names the stored field, and
		// every row that came back is a tender.
		expect(decodeURIComponent(response.url())).toMatch(
			/consultationType(\[\])?=tender/,
		)
		const rows = ((await response.json()).results ?? []) as any[]
		expect(rows.length, 'the filtered list is not empty').toBeGreaterThan(0)
		for (const row of rows) {
			expect(row.consultationType, `row ${uuidOf(row)}`).toBe('tender')
		}
		expect(rows.map(uuidOf)).toContain(tenderId)
		expect(rows.map(uuidOf)).not.toContain(citizenId)

		await expect(
			page.locator(
				`[data-testid="cn-object-row"][data-testid-row-id="${tenderId}"]`,
			),
		).toBeVisible()
		await expect(
			page.locator(
				`[data-testid="cn-object-row"][data-testid-row-id="${citizenId}"]`,
			),
		).toHaveCount(0)
	})

	// @e2e p3-citizen-participation::budget-round-appears-in-the-hub
	test('filtered to Participatory budgets, a budget consultation shows its ceiling and the hub links to the budget rounds', async ({
		page,
	}) => {
		// Two gaps, both on development today (decidiq#1281): the hub's list
		// columns and the detail's data widget both leave out budgetCeiling and
		// currency, and the filter's `route: BudgetRounds` is read by nothing
		// (CnIndexPage ignores a quick filter's route), so no link is rendered.
		test.fixme(
			true,
			'decidiq#1281: the hub shows no budgetCeiling and has no link to the BudgetRounds view',
		)

		const budgetId = await consultation({
			budgetCeiling: 48250,
			consultationType: 'participatory-budget',
			currency: 'EUR',
			status: 'draft',
			title: `${TAG}-budget`,
		})

		await open(page, '/consultations', 'cn-index-page')
		await page
			.getByTestId('cn-actions-bar')
			.locator('.cn-quick-filter-bar')
			.getByRole('combobox')
			.click()
		await option(page, 'Participatory budgets').click()

		const row = page.locator(
			`[data-testid="cn-object-row"][data-testid-row-id="${budgetId}"]`,
		)
		await expect(row).toBeVisible({ timeout: 30_000 })
		await expect(row).toContainText(/48[.,]?250/)
		await expect(row).toContainText('EUR')
		await expect(page.locator('a[href*="/budget-rounds"]').first()).toBeVisible()
	})

	// @e2e p3-citizen-participation::legacy-budget-data-stays-usable-during-transition
	test('the retained budget rounds, reached from the hub, still show a legacy round and its proposals', async ({
		page,
	}) => {
		test.fixme(
			true,
			'decidiq#1281: the hub has no link to the BudgetRounds view, so it cannot be opened from the hub',
		)

		const name = `${TAG}-legacy-round`
		const roundId = uuidOf(
			await createObject(admin, ledger, 'participatory-budget', {
				currency: 'EUR',
				name,
				status: 'submission',
				submissionDeadline: daysFromNow(14),
				totalAmount: 20000,
			}),
		)
		await createObject(admin, ledger, 'budget-proposal', {
			participatoryBudget: roundId,
			requestedAmount: 5000,
			status: 'submitted',
			submitter: `${TAG}-citizen`,
			title: `${TAG}-legacy-proposal`,
		})

		await open(page, '/consultations', 'cn-index-page')
		await page.locator('a[href*="/budget-rounds"]').first().click()
		const row = page.locator(
			`[data-testid="cn-object-row"][data-testid-row-id="${roundId}"]`,
		)
		await expect(row).toBeVisible({ timeout: 30_000 })
		await row.getByText(name).click()
		await expect(page.getByText(`${TAG}-legacy-proposal`)).toBeVisible({
			timeout: 30_000,
		})
	})
})

test.describe('Reaction moderation', () => {
	// @e2e p3-citizen-participation::cross-consultation-pending-list
	test('the moderation queue lists pending reactions from every consultation and approves one', async ({
		page,
	}) => {
		const first = await consultation({ title: `${TAG}-queue-a` })
		const second = await consultation({ title: `${TAG}-queue-b` })
		const approveBody = `${TAG} queue reaction on A`
		const keepBody = `${TAG} queue reaction on B`
		const approveId = await pendingReaction(first, approveBody)
		const keepId = await pendingReaction(second, keepBody)

		await open(page, '/moderation-queue', 'moderation-queue-page')

		const items = page.getByTestId('consultation-reactions-item')
		const toApprove = items.filter({ hasText: approveBody })
		const toKeep = items.filter({ hasText: keepBody })
		await expect(toApprove).toBeVisible({ timeout: 30_000 })
		await expect(toKeep).toBeVisible()

		await toApprove.getByTestId('consultation-reactions-approve').click()
		await expect(page.getByTestId('reaction-approve-modal')).toBeVisible()
		await page.getByTestId('reaction-approve-confirm').click()
		await expect(toApprove).toHaveCount(0, { timeout: 15_000 })

		await expect
			.poll(async () => (await storedReaction(approveId)).moderationStatus, {
				timeout: 15_000,
			})
			.toBe('approved')
		expect((await storedReaction(keepId)).moderationStatus).toBe('pending')
		await expect(toKeep).toBeVisible()
	})

	// @e2e p3-citizen-participation::moderate-a-reaction-from-the-consultation-it-belongs-to
	test("staff approve a pending reaction from the consultation detail, which lists only that consultation's reactions", async ({
		page,
	}) => {
		const here = await consultation({ title: `${TAG}-detail-here` })
		const elsewhere = await consultation({ title: `${TAG}-detail-elsewhere` })
		const hereBody = `${TAG} reaction on this consultation`
		const elsewhereBody = `${TAG} reaction on another consultation`
		const hereId = await pendingReaction(here, hereBody)
		await pendingReaction(elsewhere, elsewhereBody)

		await open(page, `/consultations/${here}`, 'consultation-reactions-tab')
		const tab = page.getByTestId('consultation-reactions-tab')
		const item = tab
			.getByTestId('consultation-reactions-item')
			.filter({ hasText: hereBody })
		await expect(item).toBeVisible({ timeout: 30_000 })
		await expect(tab.getByText(elsewhereBody)).toHaveCount(0)

		await item.getByTestId('consultation-reactions-approve').click()
		await page.getByTestId('reaction-approve-confirm').click()
		await expect(item).toHaveCount(0, { timeout: 15_000 })

		await expect
			.poll(async () => (await storedReaction(hereId)).moderationStatus, {
				timeout: 15_000,
			})
			.toBe('approved')
		// Without leaving the consultation.
		expect(new URL(page.url()).pathname).toBe(
			`/apps/decidiq/consultations/${here}`,
		)
	})

	// @e2e p3-citizen-participation::reject-requires-a-reason
	test('rejecting a reaction needs a reason, and the reason is stored on it', async ({
		page,
	}) => {
		const consultationId = await consultation({ title: `${TAG}-reject` })
		const body = `${TAG} reaction to reject`
		const reactionId = await pendingReaction(consultationId, body)
		const reason = `Off-topic (${TAG})`

		// The server refuses a rejection without a reason, whatever the UI does.
		const bare = await appPost(
			admin,
			`/participation/reactions/${reactionId}/reject`,
			{ reason: '  ' },
		)
		expect(bare.status(), await describeResponse(bare)).toBe(400)
		expect((await storedReaction(reactionId)).moderationStatus).toBe('pending')

		await open(
			page,
			`/consultations/${consultationId}`,
			'consultation-reactions-tab',
		)
		const item = page
			.getByTestId('consultation-reactions-tab')
			.getByTestId('consultation-reactions-item')
			.filter({ hasText: body })
		await expect(item).toBeVisible({ timeout: 30_000 })
		await item.getByTestId('consultation-reactions-reject').click()

		const confirm = page.getByTestId('reaction-reject-confirm')
		await expect(page.getByTestId('reaction-reject-modal')).toBeVisible()
		await expect(confirm).toBeDisabled()
		// NcTextArea has inheritAttrs:false, so the test id is on the textarea.
		await page.getByTestId('reaction-reject-reason').fill(reason)
		await expect(confirm).toBeEnabled()
		await confirm.click()
		await expect(item).toHaveCount(0, { timeout: 15_000 })

		await expect
			.poll(async () => (await storedReaction(reactionId)).moderationStatus, {
				timeout: 15_000,
			})
			.toBe('rejected')
		expect((await storedReaction(reactionId)).moderationReason).toBe(reason)
	})
})
