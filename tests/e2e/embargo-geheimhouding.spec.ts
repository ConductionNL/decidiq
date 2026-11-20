
/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: embargo and confidentiality keep documents off the public
 * surface (change embargo-geheimhouding, REQ-EMB-005, REQ-EMB-006).
 *
 * Seeds one public meeting with one agenda item carrying four documents: a
 * plain one, one under an imposed confidentiality restriction, one whose
 * embargo is still active and one whose embargo was released. An anonymous
 * caller (a context with an empty storage state) then reads the public
 * documents feed and finds only the plain and the released document. Lifting
 * the restriction (dissolved) puts its document back in the feed. The
 * administrator finally opens the confidentiality register and finds the
 * seeded restriction there.
 *
 * The target comes from base-url.ts (BASE_URL / PLAYWRIGHT_BASE_URL /
 * NEXTCLOUD_URL); there is no fallback to a shared container.
 *
 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-005-member-facing-embargo-with-scheduled-timed-release
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL as BASE } from './base-url.ts'
import {
	cleanupAll,
	createObject,
	newLedger,
	objId,
	writeHeaders,
} from './workflows/governance-fixture.ts'

const ledger = newLedger()
const tag = `e2e-${ledger.runId}`
const ids: Record<string, string> = {}

/**
 * The guids in the public documents feed, read without any session.
 *
 * @param anonymous A request context with an empty storage state.
 */
async function feedGuids(anonymous: APIRequestContext): Promise<string[]> {
	const resp = await anonymous.get(`${BASE}/index.php/apps/decidiq/feed/ori/documents.rss`)
	expect(resp.status(), await resp.text()).toBe(200)
	const xml = await resp.text()
	return [...xml.matchAll(/<guid[^>]*>([^<]+)<\/guid>/g)].map((m) => m[1].trim())
}

test.describe.configure({ mode: 'serial' })

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage()
	const body = await createObject(page, ledger, 'governance-body', {
		name: `${tag}-raad`,
		bodyType: 'legislative',
	})
	ids.body = objId(body)
	const meeting = await createObject(page, ledger, 'meeting', {
		title: `${tag} Raadsvergadering`,
		meetingType: 'regular',
		meetingMode: 'in-person',
		scheduledDate: '2026-12-10T19:30:00Z',
		lifecycle: 'scheduled',
		isPublic: true,
	})
	ids.meeting = objId(meeting)

	for (const [key, extra] of Object.entries({
		plain: {},
		restricted: {},
		embargoed: { embargoActive: true, embargoUntil: '2099-01-01T00:00:00Z' },
		released: { embargoActive: false, embargoUntil: '2020-01-01T00:00:00Z' },
	})) {
		const doc = await createObject(page, ledger, 'digital-document', {
			name: `${tag}-${key}`,
			documentType: 'report',
			...extra,
		})
		ids[key] = objId(doc)
	}

	await createObject(page, ledger, 'agenda-item', {
		title: `${tag} Grondexploitatie`,
		itemType: 'discussion',
		orderNumber: 1,
		meeting: ids.meeting,
		documents: [ids.plain, ids.restricted, ids.embargoed, ids.released],
	})

	ids.imposedAt = new Date().toISOString()
	const ground = await createObject(page, ledger, 'confidentiality-ground', {
		name: `${tag} Financial interests of the municipality`,
		citation: 'Gemeentewet art. 87',
		category: 'municipalities-act',
		requiresRatification: true,
		active: true,
	})
	const restriction = await createObject(page, ledger, 'confidentiality-restriction', {
		scope: 'document',
		targetDocument: ids.restricted,
		ground: objId(ground),
		imposedBy: 'body',
		imposedByBody: ids.body,
		imposedAt: ids.imposedAt,
		lifecycle: 'imposed',
	})
	ids.ground = objId(ground)
	ids.restriction = objId(restriction)
	await page.close()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage()
	await cleanupAll(page, ledger)
	await page.close()
})

// @e2e embargo-geheimhouding::a-restricted-or-embargoed-document-stays-off-the-public-feed
test('the public documents feed leaves out a restricted and an embargoed document', async ({
	playwright,
}) => {
	const anonymous = await playwright.request.newContext({
		storageState: { cookies: [], origins: [] },
	})
	const guids = await feedGuids(anonymous)
	expect(guids).toContain(ids.plain)
	expect(guids).toContain(ids.released)
	expect(guids).not.toContain(ids.restricted)
	expect(guids).not.toContain(ids.embargoed)
	await anonymous.dispose()
})

// @e2e embargo-geheimhouding::a-dissolved-restriction-no-longer-holds-its-document-back
test('dissolving the restriction puts its document back on the feed', async ({
	page,
	playwright,
}) => {
	const resp = await page.request.put(
		`${BASE}/index.php/apps/openregister/api/objects/decidiq/confidentiality-restriction/${ids.restriction}`,
		{
			headers: await writeHeaders(page),
			data: {
				scope: 'document',
				targetDocument: ids.restricted,
				ground: ids.ground,
				imposedBy: 'body',
				imposedByBody: ids.body,
				imposedAt: ids.imposedAt,
				lifecycle: 'dissolved',
				liftingDate: new Date().toISOString().slice(0, 10),
			},
		},
	)
	expect(resp.ok(), await resp.text()).toBe(true)

	const anonymous = await playwright.request.newContext({
		storageState: { cookies: [], origins: [] },
	})
	expect(await feedGuids(anonymous)).toContain(ids.restricted)
	await anonymous.dispose()
})

// @e2e embargo-geheimhouding::the-confidentiality-register-lists-a-restriction
test('the confidentiality register opens and lists restrictions', async ({ page }) => {
	await page.goto(`${BASE}/index.php/apps/decidiq/confidentiality-restrictions`)
	await expect(page.getByRole('heading', { name: 'Confidentiality register' })).toBeVisible()
})
