// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * toezeggingen-ingekomen-stukken Task 7: staff publish and withdraw a commitment
 * on the public list, and record its settlement, from the commitment page. The
 * public list reads the live object while its publication date has passed, so
 * these actions only set dates and the settlement fields; what they save must
 * still be a commitment the schema accepts. Component mounting is absent in this
 * repo's vitest, so the logic lives in src/utils/commitmentPublication.js and the
 * wiring is asserted on the manifest and the registry source.
 *
 * @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-005-public-toezeggingenlijst-via-the-or-published-predicate
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	canSettle,
	MAX_SETTLEMENT_NOTE_LENGTH,
	publicationState,
	published,
	settled,
	settlementNoteIsValid,
	withdrawn,
} from '../../src/utils/commitmentPublication.js'
import { validatorFor } from './helpers/registerSchema.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

const NOW = new Date('2026-11-14T10:00:00Z')
function commitment (extra = {}) {
  return {
	text: 'The alderman sends the council a housing report by 1 December.',
	madeBy: '2b3c4d5e-6f7a-4b2c-9d3e-4f5a6b7c8d12',
	meeting: '0f5b8a8e-6a3c-4c1b-9d3e-1a2b3c4d5e61',
	directedTo: '1a2b3c4d-5e6f-4a1b-8c2d-3e4f5a6b7c85',
	lifecycle: 'in-execution',
	deadline: '2026-12-01',
	...extra,
}
}

describe('where a commitment stands on the public list', () => {
	it('is unpublished without a publication date', () => {
		expect(publicationState(commitment(), NOW)).toBe('unpublished')
		expect(publicationState(null, NOW)).toBe('unpublished')
		expect(publicationState(commitment({ publicationDate: 'not a date' }), NOW)).toBe('unpublished')
	})

	it('is published once the publication date has passed, scheduled before', () => {
		expect(publicationState(commitment({ publicationDate: '2026-11-14T10:00:00Z' }), NOW)).toBe('published')
		expect(publicationState(commitment({ publicationDate: '2026-11-14T10:00:01Z' }), NOW)).toBe('scheduled')
	})

	it('is withdrawn once a depublication date has passed, and not before', () => {
		const at = { publicationDate: '2026-01-01T00:00:00Z' }
		expect(publicationState(commitment({ ...at, depublicationDate: '2026-11-01T00:00:00Z' }), NOW)).toBe('withdrawn')
		expect(publicationState(commitment({ ...at, depublicationDate: '2026-12-01T00:00:00Z' }), NOW)).toBe('published')
	})
})

describe('publish and withdraw save the whole commitment with its dates', () => {
	it('publishing sets the publication date to now and keeps every other field', () => {
		const before = commitment({ id: 'c1', goal: 'g1' })
		const after = published(before, NOW)
		expect(after).toEqual({ ...before, publicationDate: '2026-11-14T10:00:00.000Z' })
		expect(publicationState(after, NOW)).toBe('published')
		expect(before.publicationDate).toBeUndefined()
	})

	it('publishing a withdrawn commitment drops its depublication date', () => {
		const before = commitment({ publicationDate: '2026-01-01T00:00:00Z', depublicationDate: '2026-11-01T00:00:00Z' })
		const after = published(before, NOW)
		expect('depublicationDate' in after).toBe(false)
		expect(publicationState(after, NOW)).toBe('published')
	})

	it('withdrawing sets the depublication date to now and keeps the publication date', () => {
		const before = commitment({ publicationDate: '2026-01-01T00:00:00Z' })
		const after = withdrawn(before, NOW)
		expect(after.publicationDate).toBe('2026-01-01T00:00:00Z')
		expect(after.depublicationDate).toBe('2026-11-14T10:00:00.000Z')
		expect(publicationState(after, NOW)).toBe('withdrawn')
	})

	it('the published and the withdrawn commitment are accepted by the schema', () => {
		const valid = validatorFor('governance-commitment')
		const on = published(commitment(), NOW)
		expect(valid(on), JSON.stringify(valid.errors)).toBe(true)
		const off = withdrawn(on, NOW)
		expect(valid(off), JSON.stringify(valid.errors)).toBe(true)
	})
})

describe('settling a commitment', () => {
	it('is possible only from in-execution, the schema path to disposed', () => {
		expect(canSettle(commitment())).toBe(true)
		expect(canSettle(commitment({ lifecycle: 'open' }))).toBe(false)
		expect(canSettle(commitment({ lifecycle: 'disposed' }))).toBe(false)
		expect(canSettle(null)).toBe(false)
		const lifecycle = JSON.parse(read('lib/Settings/register.d/45-toezeggingen-ingekomen-stukken.json'))
			.components.schemas.Toezegging['x-openregister-lifecycle']
		expect(lifecycle.transitions).toContainEqual({ from: 'in-execution', to: 'disposed' })
		expect(lifecycle.transitions.filter((t) => t.to === 'disposed').map((t) => t.from)).toEqual(['in-execution'])
	})

	it('needs a note that says something and stays under the limit', () => {
		expect(settlementNoteIsValid('  ')).toBe(false)
		expect(settlementNoteIsValid(undefined)).toBe(false)
		expect(settlementNoteIsValid('Report sent with the budget')).toBe(true)
		expect(settlementNoteIsValid('x'.repeat(MAX_SETTLEMENT_NOTE_LENGTH + 1))).toBe(false)
	})

	it('moves to disposed with the trimmed note and evidence, keeping every other field', () => {
		const before = commitment({ id: 'c1', publicationDate: '2026-01-01T00:00:00Z' })
		const after = settled(before, { note: '  Report sent  ', evidence: ' https://example.org/letter.pdf ' })
		expect(after).toEqual({
			...before,
			lifecycle: 'disposed',
			settlementNotes: 'Report sent',
			settlementEvidence: 'https://example.org/letter.pdf',
		})
		const valid = validatorFor('governance-commitment')
		const { id, ...stored } = after
		expect(id).toBe('c1')
		expect(valid(stored), JSON.stringify(valid.errors)).toBe(true)
	})

	it('leaves stored evidence alone when none is given', () => {
		const after = settled(commitment({ settlementEvidence: 'letter 12' }), { note: 'Done', evidence: '  ' })
		expect(after.settlementEvidence).toBe('letter 12')
		expect(settled(commitment(), { note: 'Done' })).not.toHaveProperty('settlementEvidence')
	})

	it('refuses a commitment that is not in execution, and a missing note', () => {
		expect(() => settled(commitment({ lifecycle: 'open' }), { note: 'Done' })).toThrow(/in-execution/)
		expect(() => settled(commitment(), { note: ' ' })).toThrow(/note/)
	})
})

describe('the commitment page carries the publication widget', () => {
	it('is wired in the manifest fragment, its layout and the registry', () => {
		const fragment = JSON.parse(read('src/manifest.d/toezeggingen-ingekomen-stukken.json'))
		const page = fragment.pages.find((p) => p.id === 'CommitmentDetail')
		expect(page.config.widgets.find((w) => w.id === 'commitment-publication')).toMatchObject({
			type: 'custom',
			component: 'CommitmentPublicationTab',
		})
		expect(page.config.layout.some((l) => l.widgetId === 'commitment-publication')).toBe(true)
		expect(page.slots['widget-commitment-publication']).toBe('CommitmentPublicationTab')
		expect(read('src/registry.js')).toMatch(/CommitmentPublicationTab: page\(CommitmentPublicationTab\)/)
	})

	it('keeps each dialog in its own file under src/dialogs', () => {
		const tab = read('src/components/tabs/CommitmentPublicationTab.vue')
		expect(tab).not.toMatch(/<NcDialog|<NcModal/)
		expect(tab).toContain("from '../../dialogs/CommitmentPublicationDialog.vue'")
		expect(tab).toContain("from '../../dialogs/CommitmentSettlementDialog.vue'")
	})
})
