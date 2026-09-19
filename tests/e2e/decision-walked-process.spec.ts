// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e — the decision as a walked process
 * (spec: decision-as-a-walked-process).
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * Whether the register fragment IMPORTED, and whether the schemas it declares
 * are CARRIED BY THE REGISTER. Two different failures, both silent:
 *
 *   - OpenRegister drops an undeclared field without error, so a decision saved
 *     with `withdrawnBy` against a schema that never gained the property loses
 *     it with nothing to notice.
 *   - A schema declared in `components.schemas` and missing from
 *     `components.registers.decidiq.schemas` is created and linked to nothing.
 *     `ObjectService::setSchema()` under a named register then throws, and every
 *     caller that wraps that in `catch (\Throwable)` falls silent. That is
 *     exactly what happened to `decision-template`: the remedy guard and the
 *     remedy stamp were both skipped and every decision published 200 OK with
 *     no clause.
 *
 * WHY THIS FILE IS QUERYING A DIFFERENT ENDPOINT THAN IT USED TO
 * --------------------------------------------------------------
 * It read `/api/schemas`, which is GLOBAL: it answers with every schema on the
 * instance regardless of which register carries it, so it could not see the
 * second failure at all. And it `continue`d past a schema it did not find,
 * which turned "this app's register is not here" and "the schema is missing"
 * into the same pass. The register-scoped `/api/registers/decidiq/schemas`
 * answers only what the register CARRIES, and an absent schema is now a
 * failure. The one case that is still legitimately not a verdict — OpenRegister
 * absent, or decidiq's register never imported on this stack — is a SKIP, taken
 * once, before any assertion.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 * -------------------------------
 * Not the route engine. Instantiating a route, recording a verdict and
 * withdrawing a decision all need fixtures this stack does not carry; those
 * paths are asserted in the unit suite. What a besluit tells an anonymous
 * reader is walked end to end in
 * tests/Unit/Service/DecisionRemedyClauseReachesTheReaderTest.php.
 *
 * @spec openspec/changes/the-decision-as-a-walked-process/specs/decision-as-a-walked-process/spec.md
 * @e2e decision-as-a-walked-process/requirement-req-dwp-001-an-open-approval-is-a-work-item/the-assignee-sees-the-approval-as-work
 * @e2e decision-as-a-walked-process::a-published-besluit-reaches-an-anonymous-reader-carrying-its-remedy-clause
 * @e2e decision-as-a-walked-process::the-register-carries-the-decision-type-schema-the-guard-reads
 */
import { expect, test } from '@playwright/test'

/**
 * REGISTER-SCOPED, not global. `/api/schemas` lists every schema on the
 * instance whether or not any register carries it, so a schema that is
 * declared and unattached looks identical there to one that works.
 */
const REGISTER_SCHEMAS
	= '/index.php/apps/openregister/api/registers/decidiq/schemas'
const HEADERS = { 'OCS-APIRequest': 'true' }

/**
 * The properties this change adds, per schema slug. A schema that imported
 * without them is the silent failure this file exists to catch.
 */
const ADDED: Record<string, string[]> = {
	decision: ['withdrawn', 'withdrawnBy', 'ontvankelijkheid', 'legalRemedyClause'],
	'approval-action': ['assignee', 'dueAt', 'state', 'withdrawnReason'],
	'decision-template': ['legalRemedies'],
	// The clause has to survive the hop onto the public payload as well: the
	// payload is what an anonymous reader is served, and OpenRegister drops a
	// property the schema does not declare.
	'publication-payload': ['legalBasis', 'legalRemedyClause'],
}

/**
 * Schemas that were declared and never attached until 2026-09-19. Named rather
 * than counted: a count goes green again the moment any other schema is added.
 */
const MUST_BE_CARRIED = [
	'decision-template',
	'approval-route',
	'approval-action',
	'meeting-type',
	'agenda-item-type',
	'position-type',
	'position-hold',
	'governance-body-composition',
	'body-governance-configuration',
	'audit-statement',
	'goal',
]

type Schema = Record<string, any>

/**
 * The schemas the decidiq register carries, or null when this stack carries no
 * decidiq register at all.
 *
 * Absent register and empty register are deliberately different answers. The
 * first is "no verdict here"; the second is a real, reportable result, because
 * a register that carries nothing is precisely the state this file is about.
 */
async function carriedSchemas(request: any): Promise<Schema[] | null> {
	const response = await request.get(REGISTER_SCHEMAS, { headers: HEADERS })

	if (response.status() === 404) {
		return null
	}

	expect(
		response.ok(),
		`GET ${REGISTER_SCHEMAS} answered ${response.status()}; the register-scoped schema list is what this file judges, so an error here is not a pass`,
	).toBeTruthy()

	const body = await response.json()

	return (body.results ?? body.items ?? []) as Schema[]
}

test.describe('the decision as a walked process', () => {
	test('the register carries every schema the services address by slug', async ({
		request,
	}) => {
		const schemas = await carriedSchemas(request)

		test.skip(
			schemas === null,
			'OpenRegister is not installed here, or decidiq\'s register was never imported',
		)

		const carried = (schemas ?? []).map((schema) => String(schema.slug ?? ''))

		expect(
			carried.length,
			'The decidiq register carries no schemas at all, so every register-scoped read returns empty and every write throws',
		).toBeGreaterThan(0)

		for (const slug of MUST_BE_CARRIED) {
			expect(
				carried,
				`The register does not carry "${slug}". It is declared in components.schemas, so it exists on the instance and is linked to nothing: setSchema("${slug}") throws, and the callers that catch Throwable then do nothing at all.`,
			).toContain(slug)
		}
	})

	test('the schemas carry the properties the services write', async ({
		request,
	}) => {
		const schemas = await carriedSchemas(request)

		test.skip(
			schemas === null,
			'OpenRegister is not installed here, or decidiq\'s register was never imported',
		)

		for (const [slug, properties] of Object.entries(ADDED)) {
			const schema = (schemas ?? []).find(
				(candidate) => String(candidate.slug ?? '') === slug,
			)

			// 🔴 A MISSING SCHEMA IS A FAILURE, NOT A `continue`.
			// This used to skip past it, so the whole file passed whether or not
			// any of this worked. The one legitimate no-verdict case is handled
			// once, above, by the skip.
			expect(
				schema,
				`The register does not carry "${slug}", so nothing can read or write it`,
			).toBeTruthy()

			const declared = Object.keys(schema?.properties ?? {})
			for (const property of properties) {
				expect(
					declared,
					`${slug} did not import ${property}, so a value written to it is dropped in silence`,
				).toContain(property)
			}
		}
	})
})
