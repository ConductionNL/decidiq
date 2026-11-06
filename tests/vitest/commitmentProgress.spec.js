// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * followup-public-progress (matrix row fol-06): the clerk adds dated progress
 * entries on the commitment page, and the entry the server writes is one the
 * commitment schema accepts.
 *
 * @spec openspec/specs/ori-api/spec.md#requirement-req-fpp-002-the-clerk-adds-a-progress-entry
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	MAX_NOTE_LENGTH,
	newestFirst,
	noteIsValid,
	progressPath,
} from '../../src/utils/commitmentProgress.js'
import { validatorFor } from './helpers/registerSchema.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

const MEETING = '0f5b8a8e-6a3c-4c1b-9d3e-1a2b3c4d5e61'
const PERSON = '2b3c4d5e-6f7a-4b2c-9d3e-4f5a6b7c8d12'
const BODY = '1a2b3c4d-5e6f-4a1b-8c2d-3e4f5a6b7c85'

describe('a commitment carries dated progress entries (REQ-FPP-002)', () => {
	it('the entry CommitmentProgressService writes is accepted by the commitment schema', () => {
		// The exact shape CommitmentProgressService::addEntry() appends:
		// { date: Y-m-d, note: trimmed text }, nothing else.
		const commitment = {
			text: 'The alderman sends the council a housing report by 1 December.',
			madeBy: PERSON,
			meeting: MEETING,
			directedTo: BODY,
			lifecycle: 'in-execution',
			deadline: '2026-12-01',
			progress: [
				{ date: '2026-09-01', note: 'Research started' },
				{ date: '2026-10-01', note: 'Draft report sent to the committee' },
			],
		}
		const valid = validatorFor('governance-commitment')
		expect(valid(commitment), JSON.stringify(valid.errors)).toBe(true)
	})

	it('an entry with an extra key is refused by the schema', () => {
		const valid = validatorFor('governance-commitment')
		expect(
			valid({
				text: 'x',
				madeBy: PERSON,
				meeting: MEETING,
				directedTo: BODY,
				lifecycle: 'open',
				progress: [{ date: '2026-10-01', note: 'x', author: 'bert' }],
			}),
		).toBe(false)
	})

	it('the page lists entries newest first and skips broken ones', () => {
		expect(
			newestFirst([
				{ date: '2026-09-01', note: 'Research started' },
				{ note: 'no date' },
				{ date: '2026-10-01', note: 'Draft report sent' },
				null,
			]),
		).toEqual([
			{ date: '2026-10-01', note: 'Draft report sent' },
			{ date: '2026-09-01', note: 'Research started' },
		])
		expect(newestFirst(undefined)).toEqual([])
	})

	it('a note must say something and stay under the limit', () => {
		expect(noteIsValid('  ')).toBe(false)
		expect(noteIsValid('Draft report sent')).toBe(true)
		expect(noteIsValid('x'.repeat(MAX_NOTE_LENGTH + 1))).toBe(false)
	})

	it('posts to the commitment progress route', () => {
		expect(progressPath('c 1')).toBe(
			'/apps/decidiq/api/commitments/c%201/progress',
		)
		const routes = read('appinfo/routes.php')
		expect(routes).toContain(
			"'url' => '/api/commitments/{id}/progress', 'verb' => 'POST'",
		)
	})

	it('the commitment page shows the progress widget', () => {
		const fragment = JSON.parse(
			read('src/manifest.d/toezeggingen-ingekomen-stukken.json'),
		)
		const page = fragment.pages.find((p) => p.id === 'CommitmentDetail')
		const widget = page.config.widgets.find(
			(w) => w.id === 'commitment-progress',
		)
		expect(widget).toMatchObject({
			type: 'custom',
			component: 'CommitmentProgressTab',
		})
		expect(
			page.config.layout.some((l) => l.widgetId === 'commitment-progress'),
		).toBe(true)
		expect(read('src/registry.js')).toMatch(
			/CommitmentProgressTab: page\(CommitmentProgressTab\)/,
		)
	})
})
