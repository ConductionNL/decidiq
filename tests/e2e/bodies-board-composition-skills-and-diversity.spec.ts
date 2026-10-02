/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a body's Composition widget shows its skills matrix with
 * the gaps and its composition figures against its own target (change
 * bodies-board-composition-skills-and-diversity, matrix rows bod-11 bod-12).
 * The fixture builds the design's supervisory board as admin (a superuser
 * passes OpenRegister's closed write verbs); the confirm route's guard is
 * covered by MemberCompetenceControllerTest.
 *
 * @spec openspec/changes/bodies-board-composition-skills-and-diversity/specs/governance-bodies/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
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

// @e2e governance-bodies::the-board-sees-its-gaps
// @e2e governance-bodies::a-gender-target-is-met
// @e2e governance-bodies::missing-data-is-counted-not-guessed
test('the Composition widget shows the gaps and the figures against the target', async ({
	page,
}) => {
	const board = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-RvC`,
		bodyType: 'corporate-board',
		domain: 'water-authority',
		diversityTargets: [
			{ dimension: 'gender', value: 'female', minimumShare: 0.33 },
		],
	})
	const people = [
		['Janneke', 'female', 'chair'],
		['Jan', 'male', 'secretary'],
		['Mark', 'male', 'member'],
	]
	const seats: Record<string, string> = {}
	for (const [name, gender, role] of people) {
		const person = await createObject(page, ledger, 'person', {
			name: `${tag}-${name}`,
			gender,
		})
		const seat = await createObject(page, ledger, 'membership', {
			person: idOf(person),
			governanceBody: idOf(board),
			role,
			startDate: '2024-01-01T00:00:00Z',
		})
		seats[name] = idOf(seat)
	}
	const competence = async (name: string, order: number) =>
		idOf(
			await createObject(page, ledger, 'board-competence', {
				governanceBody: idOf(board),
				name,
				requiredHolders: 1,
				order,
			}),
		)
	const finance = await competence('Finance and audit', 1)
	const water = await competence('Water management', 2)
	const legal = await competence('Legal', 3)
	await competence('IT and cybersecurity', 4)
	const confirmed = { confirmedBy: 'admin', confirmedAt: '2026-01-15T10:00:00+00:00' }
	await createObject(page, ledger, 'member-competence', {
		membership: seats.Janneke,
		competence: finance,
		level: 'expert',
		...confirmed,
	})
	await createObject(page, ledger, 'member-competence', {
		membership: seats.Jan,
		competence: legal,
		level: 'experienced',
		...confirmed,
	})
	await createObject(page, ledger, 'member-competence', {
		membership: seats.Mark,
		competence: water,
		level: 'expert',
	})

	await page.goto(
		`${BASE}/index.php/apps/decidiq/governance-bodies/${idOf(board)}`,
	)
	const widget = page.getByTestId('body-composition-tab')
	await expect(widget.getByTestId('body-composition-matrix')).toBeVisible({
		timeout: 15_000,
	})
	const holders = widget.getByTestId('body-composition-holders')
	await expect(holders).toHaveCount(4)
	await expect(holders.nth(0)).not.toContainText('Gap')
	await expect(holders.nth(1)).toContainText('Gap')
	await expect(holders.nth(2)).not.toContainText('Gap')
	await expect(holders.nth(3)).toContainText('Gap')
	await expect(widget).toContainText('unconfirmed')

	const gender = widget.getByTestId('body-composition-figure-gender')
	await expect(gender.getByRole('row', { name: /female/ })).toContainText('33%')
	await expect(gender.getByRole('row', { name: /^male/ })).toContainText('67%')
	await expect(widget.getByTestId('body-composition-targets')).toContainText('met')
	const ages = widget.getByTestId('body-composition-figure-ageBand')
	await expect(ages.getByRole('row', { name: /Not recorded/ })).toContainText('3')
	await expect(gender).not.toContainText(`${tag}-Mark`)
})
