/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: every person has a profile page at /people/:id with their
 * memberships, portfolio, outside positions and voting record, and a body's
 * members widget links each name to it (change
 * bodies-member-profile-and-voting-record, matrix rows bod-05 and vot-18).
 *
 * @spec openspec/specs/person-and-membership/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	listObjects,
	newLedger,
} from './workflows/governance-fixture.ts'

const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const idOf = (o: Record<string, any>) => o.id ?? o['@self']?.id

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e person-and-membership::a-clerk-opens-a-council-members-profile
// @e2e person-and-membership::a-person-without-a-photo
// @e2e person-and-membership::an-aldermans-portfolio
// @e2e person-and-membership::from-the-council-to-a-member
test('a member profile with memberships, portfolio and outside positions', async ({
	page,
}) => {
	const council = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-raad`,
		bodyType: 'legislative',
		domain: 'municipality',
	})
	const college = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-college`,
		bodyType: 'executive',
		domain: 'municipality',
	})
	const femke = await createObject(page, ledger, 'person', {
		name: `${tag}-Femke Halsema`,
		biography: `${tag} biography`,
	})
	await createObject(page, ledger, 'membership', {
		person: idOf(femke),
		governanceBody: idOf(council),
		role: 'member',
		party: 'D66',
		startDate: '2022-03-01T00:00:00Z',
	})
	await createObject(page, ledger, 'membership', {
		person: idOf(femke),
		governanceBody: idOf(college),
		role: 'member',
		startDate: '2022-06-01T00:00:00Z',
		portfolio: ['Public order and safety', 'Communication'],
	})
	await createObject(page, ledger, 'ancillary-position', {
		person: idOf(femke),
		governanceBody: idOf(council),
		organisation: `${tag}-housing foundation`,
		role: 'Board member',
		remunerated: false,
		startDate: '2022-03-01',
		declaredAt: '2022-03-01',
		lifecycle: 'public',
	})

	await page.goto(
		`${BASE}/index.php/apps/decidiq/governance-bodies/${idOf(council)}`,
	)
	await page
		.getByTestId('body-members-tab')
		.getByRole('link', { name: `${tag}-Femke Halsema` })
		.click()
	await expect(page).toHaveURL(new RegExp(`/people/${idOf(femke)}`))

	await expect(page.getByTestId('person-initials')).toBeVisible()
	await expect(page.getByTestId('person-photo')).toHaveCount(0)
	await expect(page.getByText(`${tag} biography`)).toBeVisible()

	const current = page.getByTestId('person-memberships-current')
	await expect(current).toContainText(`${tag}-raad`)
	await expect(current).toContainText('D66')
	await expect(current).toContainText(`${tag}-college`)
	const portfolio = page.getByTestId('person-portfolio')
	await expect(portfolio).toHaveCount(1)
	await expect(portfolio).toContainText('Public order and safety')
	await expect(portfolio).toContainText('Communication')

	await expect(page.getByText(`${tag}-housing foundation`)).toBeVisible()
})

// @e2e person-and-membership::a-member-reads-a-colleagues-record
test('the voting record lists open votes and leaves out secret rounds', async ({
	page,
}) => {
	const marie = await createObject(page, ledger, 'person', {
		name: `${tag}-Marie Janssen`,
		email: `${tag}-marie@example.org`,
	})
	const participant = await createObject(page, ledger, 'participant', {
		displayName: `${tag}-Marie Janssen`,
		role: 'member',
		email: `${tag}-marie@example.org`,
		party: 'D66',
	})
	const rounds: Record<string, any> = {}
	for (const [key, secret, title] of [
		['open', false, `${tag}-Groen dak op het stadhuis`],
		['secret', true, `${tag}-Benoeming griffier`],
	] as const) {
		const round = await createObject(page, ledger, 'voting-round', {
			votingMethod: 'for-against-abstain',
			isSecret: secret,
			closedAt: '2025-04-10T21:00:00Z',
			result: 'adopted',
		})
		const decision = await createObject(page, ledger, 'decision', {
			title,
			text: title,
			decisionType: 'motion',
		})
		await createObject(page, ledger, 'decision-stage', {
			decision: idOf(decision),
			votingRound: idOf(round),
			sequence: 1,
			stageType: 'decisive',
			status: 'decided',
			decisionMakerType: 'body',
			label: 'Council',
		})
		await createObject(page, ledger, 'vote', {
			participant: idOf(participant),
			votingRound: idOf(round),
			value: 'for',
			castAt: '2025-04-10T20:30:00Z',
		})
		rounds[key] = round
	}

	await page.goto(`${BASE}/index.php/apps/decidiq/people/${idOf(marie)}`)
	const record = page.getByTestId('person-voting-record')
	await expect(record).toContainText(`${tag}-Groen dak op het stadhuis`)
	await expect(record).toContainText('For')
	await expect(record).toContainText('Adopted')
	await expect(record).toContainText('D66')
	await expect(record).not.toContainText(`${tag}-Benoeming griffier`)
})

// @e2e person-and-membership::a-person-without-participants
test('a person without participants has no votes and nothing is created', async ({
	page,
}) => {
	const loner = await createObject(page, ledger, 'person', {
		name: `${tag}-Nobody`,
		email: `${tag}-nobody@example.org`,
	})
	const before = {
		person: (await listObjects(page, 'person')).length,
		participant: (await listObjects(page, 'participant')).length,
	}

	await page.goto(`${BASE}/index.php/apps/decidiq/people/${idOf(loner)}`)
	await expect(page.getByTestId('person-voting-record')).toContainText(
		'No recorded votes.',
	)

	expect((await listObjects(page, 'person')).length).toBe(before.person)
	expect((await listObjects(page, 'participant')).length).toBe(before.participant)
})
