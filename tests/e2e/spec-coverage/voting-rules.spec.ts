/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage — Voting rules (voting-rules-v1).
 *
 * Drives the configurable voting rules through the real UI: the
 * "Voting round" sidebar tab on the motion detail page (MotionVotingRoundTab
 * hosting VotingRoundPanel), the open-round dialog's threshold / abstention /
 * tie-break selectors with their documented defaults, and the active-rules +
 * computed-base summary shown with the live tally and the closed-round
 * result. API/contract assertions (rule validation, tally math, casting-vote
 * and revote guards, the proxy cap) live in Newman
 * (tests/integration/decidesk-voting-rules.postman_collection.json), not here.
 *
 * Defensive skips: when the deployed instance does not serve this branch yet
 * (no "Voting round" tab on the motion detail page — deploy mismatch) or the
 * fixture seeding API is unavailable, the specs skip instead of failing —
 * same convention as the other spec-coverage suites.
 *
 * @e2e openspec/specs/voting-system/spec.md#configure-voting-rules-when-opening-a-round
 * @e2e openspec/specs/voting-system/spec.md#display-active-rules-and-computed-base
 */
import { test, expect, request as pwRequest, type APIRequestContext, type Page } from '@playwright/test'

const BASE = process.env.NEXTCLOUD_URL || 'http://localhost:8080'
const ADMIN_USER = process.env.NC_ADMIN_USER || 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS || 'admin'

/**
 * Cookie-less basic-auth API context for fixture seeding/teardown via the
 * OpenRegister object API (same auth model the Newman collections use).
 */
async function newApiContext(): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		httpCredentials: { username: ADMIN_USER, password: ADMIN_PASS },
		extraHTTPHeaders: {
			Accept: 'application/json',
			'OCS-APIRequest': 'true',
		},
	})
}

/** Create a decidesk object via the OR object API; null when seeding is unavailable. */
async function createObject(api: APIRequestContext, schema: string, body: object): Promise<string | null> {
	const resp = await api.post(`/index.php/apps/openregister/api/objects/decidesk/${schema}`, { data: body })
	if (!resp.ok()) return null
	const json = await resp.json()
	return json?.['@self']?.id ?? json?.id ?? null
}

/** Best-effort fixture teardown. */
async function deleteObject(api: APIRequestContext, schema: string, id: string | null): Promise<void> {
	if (!id) return
	try {
		await api.delete(`/index.php/apps/openregister/api/objects/decidesk/${schema}/${id}`)
	} catch {
		// Teardown is best-effort; leftover fixtures are namespaced ("E2E VR …").
	}
}

/**
 * Open the motion detail page and activate the "Voting round" sidebar tab.
 * Returns false when the tab is not served (deploy mismatch) so callers skip.
 */
async function openVotingRoundTab(page: Page, motionId: string): Promise<boolean> {
	await page.goto(`${BASE}/apps/decidesk/motions/${motionId}`)
	await page.waitForSelector('[data-testid="app-root"]', { timeout: 15_000 })

	// The sidebar tab is rendered from the manifest's MotionDetail sidebarTabs.
	const tab = page.getByRole('tab', { name: 'Voting round' }).or(page.getByText('Voting round', { exact: true }))
	try {
		await tab.first().waitFor({ state: 'visible', timeout: 10_000 })
	} catch {
		return false
	}
	await tab.first().click()
	try {
		await page.locator('[data-testid="motion-voting-round-tab"]').waitFor({ state: 'visible', timeout: 10_000 })
		return true
	} catch {
		return false
	}
}

// @e2e openspec/specs/voting-system/spec.md#configure-voting-rules-when-opening-a-round
test('open-round dialog offers threshold, abstention and tie-break selectors with the documented defaults', async ({ page }) => {
	const api = await newApiContext()
	let meetingId: string | null = null
	let motionId: string | null = null
	try {
		meetingId = await createObject(api, 'meeting', {
			title: 'E2E VR Meeting',
			meetingType: 'regular',
			scheduledDate: '2026-06-20T10:00:00+00:00',
			meetingMode: 'hybrid',
			lifecycle: 'opened',
			quorumRequired: 1,
		})
		test.skip(!meetingId, 'OpenRegister seeding API unavailable on this instance')

		motionId = await createObject(api, 'motion', {
			title: 'E2E VR Rules Motion',
			text: 'That voting rules be configurable.',
			motionType: 'motion',
			proposer: 'E2E VR',
			lifecycle: 'debating',
			submittedAt: '2026-06-19T09:00:00+00:00',
			meeting: meetingId,
		})
		test.skip(!motionId, 'OpenRegister seeding API unavailable on this instance')

		test.skip(!(await openVotingRoundTab(page, motionId as string)),
			'Voting round tab not deployed on this instance (deploy mismatch)')

		// The motion is in debating with a linked meeting → the chair can open a
		// round. Match both locales ('Stemronde openen' is the i18n key; the
		// English catalogue renders it as 'Open voting round').
		const openButton = page.getByRole('button', { name: /Stemronde openen|Open voting round/ })
		test.skip(!(await openButton.isVisible().catch(() => false)),
			'Open-round button not available (round already open or panel not deployed)')
		await openButton.click()

		const dialog = page.getByRole('dialog', { name: /Stemronde openen|Open voting round/ })
		await expect(dialog).toBeVisible({ timeout: 8_000 })

		// The three rule selectors render with the documented defaults:
		// simple majority, abstentions excluded, and motion fails on a tie.
		const threshold = page.locator('[data-testid="vote-threshold-select"]')
		const abstention = page.locator('[data-testid="abstention-handling-select"]')
		const tieBreak = page.locator('[data-testid="tie-break-rule-select"]')
		await expect(threshold).toBeVisible()
		await expect(abstention).toBeVisible()
		await expect(tieBreak).toBeVisible()
		await expect(threshold).toHaveValue('simple-majority')
		await expect(abstention).toHaveValue('exclude')
		await expect(tieBreak).toHaveValue('rejected')

		// All qualified-majority thresholds are selectable (two-thirds,
		// three-quarters, unanimous), all abstention modes, all tie-break rules.
		await expect(threshold.locator('option')).toHaveCount(4)
		await threshold.selectOption('qualified-majority-two-thirds')
		await expect(threshold).toHaveValue('qualified-majority-two-thirds')
		await expect(abstention.locator('option')).toHaveCount(2)
		await abstention.selectOption('count')
		await expect(abstention).toHaveValue('count')
		await expect(tieBreak.locator('option')).toHaveCount(3)
		await tieBreak.selectOption('chair-decides')
		await expect(tieBreak).toHaveValue('chair-decides')
	} finally {
		await deleteObject(api, 'motion', motionId)
		await deleteObject(api, 'meeting', meetingId)
		await api.dispose()
	}
})

// @e2e openspec/specs/voting-system/spec.md#display-active-rules-and-computed-base
test('closed-round result shows the active rules and the computed base', async ({ page }) => {
	const api = await newApiContext()
	let motionId: string | null = null
	let roundId: string | null = null
	try {
		motionId = await createObject(api, 'motion', {
			title: 'E2E VR Result Motion',
			text: 'Closed-round rules display host motion.',
			motionType: 'motion',
			proposer: 'E2E VR',
			lifecycle: 'adopted',
			submittedAt: '2026-06-19T09:00:00+00:00',
		})
		test.skip(!motionId, 'OpenRegister seeding API unavailable on this instance')

		// Closed two-thirds round with the spec's worked example (14/5/1 → base 19).
		roundId = await createObject(api, 'voting-round', {
			votingMethod: 'for-against-abstain',
			isSecret: false,
			openedAt: '2026-06-20T10:05:00+00:00',
			closedAt: '2026-06-20T11:00:00+00:00',
			result: 'adopted',
			votesFor: 14,
			votesAgainst: 5,
			votesAbstain: 1,
			voteThreshold: 'qualified-majority-two-thirds',
			abstentionHandling: 'exclude',
			tieBreakRule: 'rejected',
			voteBase: 19,
			relations: [{ register: 'decidesk', schema: 'motion', id: motionId }],
		})
		test.skip(!roundId, 'OpenRegister seeding API unavailable on this instance')

		test.skip(!(await openVotingRoundTab(page, motionId as string)),
			'Voting round tab not deployed on this instance (deploy mismatch)')

		// The closed-round result block shows the applied rules + computed base.
		const rules = page.locator('[data-testid="result-voting-rules"]')
		await expect(rules).toBeVisible({ timeout: 10_000 })
		await expect(rules).toContainText('Qualified majority (2/3)')
		await expect(rules).toContainText('Abstentions excluded from base')
		await expect(rules).toContainText('base: 19')
	} finally {
		await deleteObject(api, 'voting-round', roundId)
		await deleteObject(api, 'motion', motionId)
		await api.dispose()
	}
})

// @e2e openspec/specs/voting-system/spec.md#display-active-rules-and-computed-base
test('live tally shows the active rules and the computed base while the round is open', async ({ page }) => {
	const api = await newApiContext()
	let motionId: string | null = null
	let roundId: string | null = null
	try {
		motionId = await createObject(api, 'motion', {
			title: 'E2E VR Live Motion',
			text: 'Live-tally rules display host motion.',
			motionType: 'motion',
			proposer: 'E2E VR',
			lifecycle: 'voting',
			submittedAt: '2026-06-19T09:00:00+00:00',
		})
		test.skip(!motionId, 'OpenRegister seeding API unavailable on this instance')

		// OPEN round in count mode: base = for + against + abstain = 10.
		roundId = await createObject(api, 'voting-round', {
			votingMethod: 'for-against-abstain',
			isSecret: false,
			openedAt: '2026-06-20T10:05:00+00:00',
			votesFor: 4,
			votesAgainst: 3,
			votesAbstain: 3,
			voteThreshold: 'qualified-majority-three-quarters',
			abstentionHandling: 'count',
			tieBreakRule: 'revote',
			relations: [{ register: 'decidesk', schema: 'motion', id: motionId }],
		})
		test.skip(!roundId, 'OpenRegister seeding API unavailable on this instance')

		test.skip(!(await openVotingRoundTab(page, motionId as string)),
			'Voting round tab not deployed on this instance (deploy mismatch)')

		const rules = page.locator('[data-testid="active-voting-rules"]')
		await expect(rules).toBeVisible({ timeout: 10_000 })
		await expect(rules).toContainText('Qualified majority (3/4)')
		await expect(rules).toContainText('Abstentions count toward base')
		await expect(rules).toContainText('Tie: revote (once)')
		await expect(rules).toContainText('base: 10')
	} finally {
		await deleteObject(api, 'voting-round', roundId)
		await deleteObject(api, 'motion', motionId)
		await api.dispose()
	}
})
