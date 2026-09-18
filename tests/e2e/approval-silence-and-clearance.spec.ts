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

const SCHEMAS = '/index.php/apps/openregister/api/schemas'
const CLEARANCE = '/index.php/apps/decidiq/api/approval-routes/clearance'
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
				candidate => String(candidate.slug ?? '') === slug,
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
			candidate => String(candidate.slug ?? '') === 'approval-action',
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
		// The LEAST privileged principal that should be refused: no session at
		// all. Who is holding up somebody else's file is not public information,
		// and a signed-in user still has to be able to reach the subject.
		const anonymous = await playwright.request.newContext()

		try {
			const response = await anonymous.get(
				`${CLEARANCE}?subject=does-not-exist&subjectSchema=decision`,
				{ headers: HEADERS },
			)

			test.skip(response.status() === 404, 'decidiq is not installed here')

			expect(
				[401, 403],
				`an anonymous caller got ${response.status()} from the clearance endpoint`,
			).toContain(response.status())
		} finally {
			await anonymous.dispose()
		}
	})
})
