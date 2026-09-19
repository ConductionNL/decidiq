// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e — approval routes resolve a manager and declare silence
 * (spec: approval-routes, REQ-AR-012 to REQ-AR-017).
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * Two things, both of which a green unit suite is blind to.
 *
 * One, whether the register fragment IMPORTED. OpenRegister drops an undeclared
 * property in silence, so a stage patched with `onSilence` against a schema that
 * never gained the field loses it with no error anywhere, and the sweep then
 * reads every stage as holding. The unit tests pass either way, because they
 * hand the service the array themselves.
 *
 * Two, whether the clearance endpoint is REACHABLE and refuses an anonymous
 * caller. A route that is not registered 404s, and a 404 is exactly what a
 * fail-closed consumer treats as "not cleared", so a broken deployment would
 * look like a working one that happens to block everything.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 * -------------------------------
 * Not what silence does. Every lapse needs a stage, a term and a clock the CI
 * stack has no fixtures for; all four policies, the stand-in ask and the
 * idempotency of the sweep are asserted in the unit suite
 * (tests/Unit/Service/StageLapsePolicyTest.php and
 * tests/Unit/Service/ApprovalStageLapseServiceTest.php).
 *
 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md
 * @e2e exclude every scenario in the delta is marked `@e2e exclude` in the spec
 *      itself, as a service or timed path; this file covers the deployment
 *      facts around them instead.
 */
import { expect, test } from '@playwright/test'
import {
	anonymousActor,
	APP_API,
	describe as describeResponse,
	disposeActors,
} from './support/api-actors.ts'

const SCHEMAS = '/index.php/apps/openregister/api/schemas'
const CLEARANCE = `${APP_API}/approval-routes/clearance`
const HEADERS = { 'OCS-APIRequest': 'true' }

/**
 * The properties this change adds, per schema slug. A schema that imported
 * without them is the silent failure this file exists to catch.
 */
const ADDED: Record<string, string[]> = {
	'approval-route': ['required', 'steps'],
	'decision-stage': [
		'actorRule',
		'onSilence',
		'dueAt',
		'activatedAt',
		'askSubstituteAfter',
		'substituteActor',
		'lapsedAt',
	],
	'approval-action': ['onSilencePolicy'],
}

test.describe('approval routes: a rule, a declared silence and a clearance answer', () => {
	test('the schemas carry the fields the sweep reads and writes', async ({
		request,
	}) => {
		const response = await request.get(SCHEMAS, { headers: HEADERS })

		test.skip(response.status() === 404, 'OpenRegister is not installed here')
		expect(response.ok()).toBeTruthy()

		const body = await response.json()
		const schemas = (body.results ?? body.items ?? body ?? []) as Array<
			Record<string, any>
		>

		for (const [slug, properties] of Object.entries(ADDED)) {
			const schema = schemas.find(
				(candidate) => String(candidate.slug ?? '') === slug,
			)

			// A stack that never imported decidiq's register at all is a skip,
			// not a failure: this asserts the fragment's CONTENT.
			if (!schema) {
				continue
			}

			const declared = Object.keys(schema.properties ?? {})
			for (const property of properties) {
				expect(
					declared,
					`${slug} did not import ${property}, so a value written to it is dropped in silence`,
				).toContain(property)
			}
		}
	})

	test('the lapse action enums can hold a lapse at all', async ({ request }) => {
		const response = await request.get(SCHEMAS, { headers: HEADERS })

		test.skip(response.status() === 404, 'OpenRegister is not installed here')

		const body = await response.json()
		const schemas = (body.results ?? body.items ?? body ?? []) as Array<
			Record<string, any>
		>
		const action = schemas.find(
			(candidate) => String(candidate.slug ?? '') === 'approval-action',
		)
		test.skip(!action, 'decidiq has not imported its register here')

		const properties = action?.properties ?? {}

		// A lapse writes actorType `system` and verb `lapsed`. Either enum
		// arriving unwidened rejects the row, and the step then moves with
		// nothing on the record saying why.
		expect(
			properties.actorType?.enum ?? [],
			'actorType cannot hold `system`, so a lapse leaves no auditable action',
		).toContain('system')
		expect(
			properties.action?.enum ?? [],
			'the action verb cannot hold `lapsed`',
		).toContain('lapsed')
	})

	test('an anonymous caller is refused the clearance answer', async ({
		playwright,
	}) => {
		// 🔴 THE PROBE HAS TO BE BUILT, NOT ASSUMED. `playwright.request
		// .newContext()` inherits `use.storageState` from playwright.config.ts,
		// which is the ADMINISTRATOR's session, so the version of this test
		// that called it "no session at all" was signed in as a superuser.
		// Measured against a live instance on 2026-09-19: a real anonymous
		// caller gets 401 "Current user is not logged in" here and admin gets
		// 400 "Object not found in magic table", and the test received the 400.
		// It failed, so the escalation showed. Pointed the other way, with the
		// route made public, the same probe would keep passing over an endpoint any
		// passer-by could read.
		//
		// `anonymousActor` starts from an EMPTY storage state and carries no
		// credentials. Its context has no baseURL, so the URL is absolute.
		const anonymous = await anonymousActor(playwright)

		try {
			const response = await anonymous.ctx.get(
				`${CLEARANCE}?subject=does-not-exist&subjectSchema=decision`,
				{ headers: HEADERS },
			)

			test.skip(response.status() === 404, 'decidiq is not installed here')

			// 401, exactly. Who is holding up somebody else's file is not public
			// information, so the session guard has to turn the caller away
			// before the controller reads the subject at all. Accepting any 4xx
			// accepts the answer admin gets too, which says nothing about who
			// was asking.
			expect(
				response.status(),
				`an anonymous caller got ${response.status()} from the clearance endpoint: ${await describeResponse(response)}`,
			).toBe(401)
		} finally {
			await disposeActors(anonymous)
		}
	})
})
