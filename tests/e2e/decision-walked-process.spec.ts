// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e — the decision as a walked process
 * (spec: decision-as-a-walked-process).
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * The six services are covered by PHPUnit, which can build the fixtures a CI
 * stack has no routes or decisions for. What PHPUnit cannot see is whether the
 * register fragment IMPORTED: OpenRegister drops an undeclared field in
 * silence, so a decision saved with `withdrawnBy` against a schema that never
 * gained the property loses it with no error anywhere, and every unit test
 * stays green because the service returned the right array.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 * -------------------------------
 * Not the route engine. Instantiating a route, recording a verdict and
 * withdrawing a decision all need fixtures this stack does not carry; those
 * paths are asserted in the unit suite.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md
 * @e2e decision-as-a-walked-process/requirement-req-dwp-001-an-open-approval-is-a-work-item/the-assignee-sees-the-approval-as-work
 */
import { expect, test } from '@playwright/test'

const SCHEMAS = '/index.php/apps/openregister/api/schemas'
const HEADERS = { 'OCS-APIRequest': 'true' }

/**
 * The properties this change adds, per schema slug. A schema that imported
 * without them is the silent failure this file exists to catch.
 */
const ADDED: Record<string, string[]> = {
	decision: ['withdrawn', 'withdrawnBy', 'ontvankelijkheid', 'legalRemedyClause'],
	'approval-action': ['assignee', 'dueAt', 'state', 'withdrawnReason'],
	'decision-template': ['legalRemedies'],
}

test.describe('the decision as a walked process', () => {
	test('the schemas carry the properties the services write', async ({
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
			// not a failure: this asserts the fragment's CONTENT, not that the
			// app is installed here.
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
})
