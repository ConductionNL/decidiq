// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e: a voting round opened with a voting group lets only that
 * group's members vote (voting-groups-chosen-when-a-round-opens, Task 3,
 * REQ-VGP-002).
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * `VotingGroupResolverTest` and `VotingGroupRoundTest` resolve a group against
 * doubles. Only a live instance proves the whole path: the chair's POST
 * /api/voting-rounds with `votingGroup`, the group's people matched to the
 * meeting's participants by Nextcloud account, and the cast endpoint then
 * accepting a vote from a member and refusing one from a participant who is
 * not in the group. Each voter is a REAL second account, so the refusal is
 * the round's, not a missing login.
 *
 * WHAT IT CREATES AND REMOVES. Two Nextcloud accounts, a governance body, a
 * meeting, three participants (the admin as chair, a member of the group and
 * one outside it), two people, the voting group, a motion and the round. All
 * of it is removed in afterAll.
 *
 * @spec openspec/changes/archive/2026-11-24-voting-groups-chosen-when-a-round-opens/specs/voting-group-presets/spec.md#requirement-req-vgp-002-chair-can-select-a-voting-group-preset-when-opening-a-votinground
 */

import type { Actor } from './support/api-actors.ts'

import { expect, test } from '@playwright/test'
import {
	adminActor,
	appPost,
	createObject,
	describe as describeResponse,
	disposeActors,
	newRunId,
	ObjectLedger,
	provisionAccount,
	removeAccounts,
	uuidOf,
} from './support/api-actors.ts'

const RUN = newRunId()
const ledger = new ObjectLedger(['voting-round', 'decision', 'voting-group', 'participant', 'person', 'meeting', 'governance-body'])

test.describe('A round opened with a voting group', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(120_000)

	let admin: Actor
	let inGroup: Actor
	let outside: Actor
	let meetingId = ''
	let motionId = ''
	let groupId = ''
	let roundId = ''

	test.beforeAll(async ({ playwright }) => {
		admin = await adminActor(playwright)
		inGroup = await provisionAccount(playwright, admin, `dq-e2e-vg-in-${RUN}`)
		outside = await provisionAccount(playwright, admin, `dq-e2e-vg-out-${RUN}`)

		const body = uuidOf(await createObject(admin, ledger, 'governance-body', {
			name: `e2e-${RUN} audit committee`,
			bodyType: 'legislative',
			domain: `e2e-${RUN} council`,
		}))
		meetingId = uuidOf(await createObject(admin, ledger, 'meeting', {
			title: `e2e-${RUN} meeting`,
			governanceBody: body,
			meetingMode: 'in-person',
			meetingType: 'regular',
			lifecycle: 'opened',
			scheduledDate: new Date().toISOString(),
			quorumRequired: 0,
		}))

		for (const [uid, role] of [['admin', 'chair'], [inGroup.uid, 'member'], [outside.uid, 'member']]) {
			await createObject(admin, ledger, 'participant', {
				displayName: `e2e-${RUN} ${uid}`,
				role,
				governanceBody: body,
				attendanceStatus: 'present',
				nextcloudUserId: uid,
			})
		}

		const member = uuidOf(await createObject(admin, ledger, 'person', {
			name: `e2e-${RUN} group member`,
			nextcloudUserId: inGroup.uid,
		}))
		// A person in the group who is not a participant of the meeting: reported, not seated.
		const absentee = uuidOf(await createObject(admin, ledger, 'person', {
			name: `e2e-${RUN} absent member`,
			email: `absent-${RUN}@example.org`,
		}))
		groupId = uuidOf(await createObject(admin, ledger, 'voting-group', {
			name: `e2e-${RUN} audit committee members`,
			governanceBody: body,
			members: [member, absentee],
			active: true,
		}))

		motionId = uuidOf(await createObject(admin, ledger, 'decision', {
			decisionType: 'motion',
			title: `e2e-${RUN} motion`,
			text: `e2e-${RUN} motion body text`,
			motionType: 'motion',
			proposer: `e2e-${RUN} proposer`,
			lifecycle: 'deliberating',
			submittedAt: new Date().toISOString(),
		}))
	})

	test.afterAll(async () => {
		await ledger.cleanup(admin)
		await removeAccounts(admin, [inGroup?.uid, outside?.uid].filter(Boolean) as string[])
		await disposeActors(admin, inGroup, outside)
	})

	test('the chair opens the round with the group, and the member outside the meeting is named', async () => {
		const opened = await appPost(admin, '/voting-rounds', {
			motionId,
			meetingId,
			votingMethod: 'for-against-abstain',
			votingGroup: groupId,
		})
		expect(opened.status(), await describeResponse(opened)).toBeLessThan(300)
		const round = await opened.json()
		roundId = uuidOf(round?.round ?? round)
		expect(roundId, 'the round has an id').not.toBe('')
		ledger.track('voting-round', roundId)

		const excluded = (round?.excludedGroupMembers ?? round?.round?.excludedGroupMembers ?? []) as Array<{ name?: string }>
		expect(excluded.map((row) => row.name)).toContain(`e2e-${RUN} absent member`)
	})

	test('a member of the group casts a vote', async () => {
		const cast = await appPost(inGroup, `/voting-rounds/${roundId}/cast`, { value: 'for' })
		expect(cast.status(), await describeResponse(cast)).toBeLessThan(300)
	})

	test('a participant outside the group cannot vote in it', async () => {
		const cast = await appPost(outside, `/voting-rounds/${roundId}/cast`, { value: 'against' })
		expect(cast.status(), await describeResponse(cast)).toBeGreaterThanOrEqual(400)
		expect(cast.status(), 'a refusal, not a server error').toBeLessThan(500)
	})

	test('combining a group with preset participant ids is refused', async () => {
		const both = await appPost(admin, '/voting-rounds', {
			motionId,
			meetingId,
			votingGroup: groupId,
			presetParticipantIds: ['00000000-0000-4000-8000-000000000000'],
		})
		expect(both.status(), await describeResponse(both)).toBe(400)
	})
})
