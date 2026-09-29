/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the result of a vote per member and per faction (change
 * voting-results-by-faction-and-member, matrix row vot-03). Ballots are
 * seeded in the shape castVote() writes (the voter only in `relations`);
 * the motion page names each voter and shows a line per faction.
 *
 * @spec openspec/specs/motion-and-voting/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	MOTION_SCHEMA,
	newLedger,
} from './workflows/governance-fixture.ts'

const ledger = newLedger()
const tag = `e2e-${ledger.runId}`

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e motion-and-voting::the-council-sees-how-factions-voted
test('the motion page names each voter and counts per faction', async ({ page }) => {
	const idOf = (o: Record<string, any>) => o.id ?? o['@self']?.id
	const motion = await createObject(page, ledger, MOTION_SCHEMA, {
		title: `${tag}-M-12`,
		decisionType: 'motion',
	})
	const round = await createObject(page, ledger, 'voting-round', {
		motion: idOf(motion),
		votingMethod: 'for-against-abstain',
		isSecret: false,
		votesFor: 2,
		votesAgainst: 1,
		votesAbstain: 0,
		result: 'adopted',
	})
	const members = [
		[`${tag}-Anna`, 'GroenLinks', 'for'],
		[`${tag}-Bas`, 'GroenLinks', 'for'],
		[`${tag}-Cor`, 'VVD', 'against'],
	]
	for (const [name, party, value] of members) {
		const participant = await createObject(page, ledger, 'participant', {
			displayName: name,
			party,
		})
		await createObject(page, ledger, 'vote', {
			value,
			weight: 1,
			isProxy: false,
			castAs: 'unknown',
			relations: [
				{ register: 'decidiq', schema: 'voting-round', id: idOf(round) },
				{
					register: 'decidiq',
					schema: 'participant',
					id: idOf(participant),
				},
			],
		})
	}

	await page.goto(`${BASE}/index.php/apps/decidiq/motions/${idOf(motion)}`)
	const tab = page.getByTestId('motion-votes-tab')
	await expect(tab.getByText(`${tag}-Anna`)).toBeVisible({ timeout: 20_000 })
	await expect(tab.getByText(`${tag}-Cor`)).toBeVisible()
	const factions = tab.getByTestId('motion-votes-factions')
	await expect(
		factions.getByRole('row').filter({ hasText: 'GroenLinks' }),
	).toContainText('2')
	await expect(factions.getByRole('row').filter({ hasText: 'VVD' })).toContainText(
		'1',
	)
})
