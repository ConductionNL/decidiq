// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * agenda-ai-paper-summaries (matrix rows age-16 age-20 plt-27): members see
 * shown summaries only, a clerk gets the review actions the lifecycle allows,
 * and what a review writes is accepted by the register's PaperSummary schema.
 *
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-005-members-see-a-summary-only-after-a-clerk-shows-it
 */
import { readFileSync } from 'node:fs'
import { describe, expect, it, vi } from 'vitest'
import { validatorFor } from './helpers/registerSchema.js'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

const {
	actionsFor,
	availabilityUrl,
	editPayload,
	paperOptions,
	requestUrl,
	retryBody,
	reviewPayload,
	visibleSummaries,
} = await import('../../src/utils/paperSummaries.js')

const ITEM = 'a1b2c3d4-0000-4000-8000-000000000001'

const draft = {
	id: '7a1d2c3e-0000-4000-8000-000000000119',
	'@self': {
		id: '7a1d2c3e-0000-4000-8000-000000000119',
		created: '2026-10-01T10:00:00+00:00',
	},
	agendaItem: ITEM,
	paperFileId: 900412,
	paperTitle: 'Raadsvoorstel kadernota 2026.pdf',
	kind: 'summary',
	text: 'De begroting sluit met een overschot van 2 miljoen.',
	provider: 'llm2',
	taskId: 42,
	chunked: false,
	status: 'draft',
}
const shown = {
	...draft,
	id: 's2',
	'@self': { created: '2026-09-30T10:00:00+00:00' },
	status: 'shown',
	reviewedBy: 'Jan de Vries',
	reviewedAt: '2026-09-30T10:15:00+00:00',
}
const failed = {
	...draft,
	id: 's3',
	'@self': { created: '2026-10-02T10:00:00+00:00' },
	status: 'failed',
	kind: 'comparison',
	comparedFileId: 910001,
	text: undefined,
}

/**
 * The schema's lifecycle transitions as from>to strings.
 *
 * @return {Set<string>} The allowed transitions.
 */
function transitions() {
	const fragment = JSON.parse(
		readFileSync(
			new URL(
				'../../lib/Settings/register.d/119-paper-summaries.json',
				import.meta.url,
			),
			'utf8',
		),
	)
	const lifecycle =
		fragment.components.schemas.PaperSummary['x-openregister-lifecycle']
	return new Set(lifecycle.transitions.map((step) => `${step.from}>${step.to}`))
}

describe('paper summaries on the agenda item page', () => {
	it('shows a member the shown summaries only, a clerk every one, newest first', () => {
		expect(visibleSummaries([draft, shown, failed], false)).toEqual([shown])
		expect(
			visibleSummaries([shown, draft, failed], true).map((s) => s.id),
		).toEqual(['s3', draft.id, 's2'])
		expect(visibleSummaries(null, true)).toEqual([])
	})

	it('gives a member no actions and a clerk only the transitions the lifecycle allows', () => {
		expect(actionsFor(draft, false)).toEqual([])
		expect(actionsFor(draft, true)).toEqual(['edit', 'show', 'hide'])
		expect(actionsFor(shown, true)).toEqual(['hide'])
		expect(actionsFor({ status: 'hidden' }, true)).toEqual(['show'])
		expect(actionsFor({ status: 'requested' }, true)).toEqual([])
		expect(actionsFor(failed, true)).toEqual(['retry'])

		const allowed = transitions()
		for (const status of ['requested', 'draft', 'shown', 'hidden', 'failed']) {
			for (const action of actionsFor({ status }, true)) {
				if (action === 'show')
					expect(allowed.has(`${status}>shown`), status).toBe(true)
				if (action === 'hide')
					expect(allowed.has(`${status}>hidden`), status).toBe(true)
			}
		}
	})

	it('writes a review the register accepts, naming the clerk and the time', () => {
		const payload = reviewPayload(
			draft,
			'shown',
			'Jan de Vries',
			new Date('2026-10-03T09:00:00Z'),
		)
		expect(payload).toMatchObject({
			status: 'shown',
			reviewedBy: 'Jan de Vries',
			reviewedAt: '2026-10-03T09:00:00.000Z',
			text: draft.text,
		})
		expect(payload).not.toHaveProperty('@self')
		const validate = validatorFor('paper-summary')
		expect(validate(payload), JSON.stringify(validate.errors)).toBe(true)

		const edited = editPayload(
			draft,
			'De begroting sluit met een overschot van 1,2 miljoen.',
		)
		expect(edited.status).toBe('draft')
		expect(edited.text).toContain('1,2 miljoen')
		expect(validate(edited), JSON.stringify(validate.errors)).toBe(true)
	})

	it('offers the other papers of the item to compare with', () => {
		const files = [
			{ id: 900412, name: 'Raadsvoorstel kadernota 2026.pdf' },
			{ id: '910001', name: 'Kadernota 2026.docx' },
		]
		expect(paperOptions(files)).toEqual([
			{ id: 900412, label: 'Raadsvoorstel kadernota 2026.pdf' },
			{ id: 910001, label: 'Kadernota 2026.docx' },
		])
		expect(paperOptions(files, 900412)).toEqual([
			{ id: 910001, label: 'Kadernota 2026.docx' },
		])
	})

	it('asks again for a failed summary with the same papers', () => {
		expect(retryBody(failed)).toEqual({
			fileId: 900412,
			kind: 'comparison',
			comparedFileId: 910001,
		})
		expect(retryBody(draft)).toEqual({ fileId: 900412, kind: 'summary' })
	})

	it('posts to the routes the controller answers', () => {
		const routes = readFileSync(
			new URL('../../appinfo/routes.php', import.meta.url),
			'utf8',
		)
		expect(requestUrl(ITEM)).toBe(
			`/index.php/apps/decidiq/api/agenda-items/${ITEM}/paper-summaries`,
		)
		expect(routes).toContain(
			"'url' => '/api/agenda-items/{id}/paper-summaries', 'verb' => 'POST'",
		)
		expect(availabilityUrl()).toBe(
			'/index.php/apps/decidiq/api/paper-summaries/availability',
		)
		expect(routes).toContain(
			"'url' => '/api/paper-summaries/availability',      'verb' => 'GET'",
		)
	})
})
