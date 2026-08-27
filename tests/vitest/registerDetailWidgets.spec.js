/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure / stateless algorithms behind decidiq's three
 * register-detail catalog widgets (src/components/widgets/registerDetailWidgets.js):
 * version ordering (RegisterVersionTimelineWidget), the ondermandaat
 * ancestor-chain cycle-safety model (DelegationChainWidget), the
 * confidentiality three-stage timeline build (ConfidentialityStatusTimelineWidget),
 * and the shared object-label resolver all three use.
 *
 * These functions are exported from a plain .js module (not a .vue SFC)
 * specifically so they are importable here: this repo's vitest.config.js
 * runs on plain Vite with no @vitejs/plugin-vue registered, so a `.vue`
 * file cannot be imported by a Vitest spec (confirmed empirically — Vite's
 * import-analysis plugin refuses to parse SFC template/style blocks without
 * the plugin). This mirrors the existing pattern in this test suite
 * (src/views/dashboard/widgets/widgetLogic.js, src/components/tabs/useRelationStore.js)
 * of keeping the logic Vitest actually exercises in importable .js modules
 * that the Vue components then call — single source of truth, not a
 * parallel copy.
 *
 * Covers register-detail-optimisation acceptance criteria: REQ-VOR-009 /
 * REQ-GDR-009 (version ordering), REQ-DMR-008 (ancestor/child chain,
 * cycle-safety), REQ-EMB-010 (three-stage timeline, overdue detection).
 *
 * @spec openspec/changes/register-detail-optimisation/tasks.md
 */

import { describe, expect, it } from 'vitest'
import {
	buildConfidentialityStages,
	findChildren,
	resolveObjectLabel,
	sortVersionsByEffectiveDate,
	toTime,
	walkAncestors,
} from '../../src/components/widgets/registerDetailWidgets.js'

describe('toTime', () => {
	it('parses a valid ISO date string to epoch ms', () => {
		expect(toTime('2025-06-01')).toBe(new Date('2025-06-01').getTime())
	})

	it('returns NaN for null / undefined / empty string', () => {
		expect(Number.isNaN(toTime(null))).toBe(true)
		expect(Number.isNaN(toTime(undefined))).toBe(true)
		expect(Number.isNaN(toTime(''))).toBe(true)
	})

	it('returns NaN for an unparseable string', () => {
		expect(Number.isNaN(toTime('not-a-date'))).toBe(true)
	})
})

describe('sortVersionsByEffectiveDate (REQ-VOR-009 / REQ-GDR-009)', () => {
	it('sorts ascending by the configured effective-date field', () => {
		const versions = [
			{ id: 'v3', effectiveDate: '2025-01-01' },
			{ id: 'v1', effectiveDate: '2024-01-01' },
			{ id: 'v2', effectiveDate: '2024-06-01' },
		]
		const sorted = sortVersionsByEffectiveDate(versions, 'effectiveDate')
		expect(sorted.map((v) => v.id)).toEqual(['v1', 'v2', 'v3'])
	})

	it('does not mutate the input array', () => {
		const versions = [
			{ id: 'v2', effectiveDate: '2025-01-01' },
			{ id: 'v1', effectiveDate: '2024-01-01' },
		]
		const original = versions.slice()
		sortVersionsByEffectiveDate(versions, 'effectiveDate')
		expect(versions).toEqual(original)
	})

	it('sorts a concept version with no effective date yet to the end', () => {
		const versions = [
			{ id: 'concept', effectiveDate: null },
			{ id: 'sealed', effectiveDate: '2025-01-01' },
		]
		const sorted = sortVersionsByEffectiveDate(versions, 'effectiveDate')
		expect(sorted.map((v) => v.id)).toEqual(['sealed', 'concept'])
	})

	it('returns an empty array for non-array input', () => {
		expect(sortVersionsByEffectiveDate(null, 'effectiveDate')).toEqual([])
		expect(sortVersionsByEffectiveDate(undefined, 'effectiveDate')).toEqual([])
	})
})

describe('walkAncestors (REQ-DMR-008 ondermandaat ancestor breadcrumb)', () => {
	it('walks a 3-level chain root-first, excluding the start object', () => {
		// A (root) <- B <- C (current)
		const byId = new Map([
			['a', { id: 'a', parentAllocation: null }],
			['b', { id: 'b', parentAllocation: 'a' }],
			['c', { id: 'c', parentAllocation: 'b' }],
		])
		const ancestors = walkAncestors(byId, 'c', 'parentAllocation')
		expect(ancestors.map((n) => n.id)).toEqual(['a', 'b'])
	})

	it('returns an empty array for a root object with no parent', () => {
		const byId = new Map([['a', { id: 'a', parentAllocation: null }]])
		expect(walkAncestors(byId, 'a', 'parentAllocation')).toEqual([])
	})

	it('terminates on a defensive cycle instead of hanging (never producible via normal save flows)', () => {
		// A -> B -> A (malformed / defensive-only cycle)
		const byId = new Map([
			['a', { id: 'a', parentAllocation: 'b' }],
			['b', { id: 'b', parentAllocation: 'a' }],
		])
		const ancestors = walkAncestors(byId, 'a', 'parentAllocation')
		// Must terminate (this assertion itself times out / hangs the suite if it doesn't)
		// and must not revisit 'a' — 'b' is the only distinct ancestor reachable
		// before the walk hits an already-visited id.
		expect(ancestors.map((n) => n.id)).toEqual(['b'])
	})

	it('stops cleanly when a referenced parent id is not in the candidate set', () => {
		const byId = new Map([['c', { id: 'c', parentAllocation: 'missing' }]])
		expect(walkAncestors(byId, 'c', 'parentAllocation')).toEqual([])
	})
})

describe('findChildren (REQ-DMR-008 ondermandaat children)', () => {
	const all = [
		{ id: 'child-1', parentAllocation: 'root' },
		{ id: 'child-2', parentAllocation: 'root' },
		{ id: 'unrelated', parentAllocation: 'other' },
	]

	it('returns every item whose parent field matches the current id', () => {
		const children = findChildren(all, 'parentAllocation', 'root')
		expect(children.map((c) => c.id)).toEqual(['child-1', 'child-2'])
	})

	it('returns an empty array when nothing matches', () => {
		expect(findChildren(all, 'parentAllocation', 'nobody')).toEqual([])
	})

	it('returns an empty array for non-array input', () => {
		expect(findChildren(null, 'parentAllocation', 'root')).toEqual([])
	})
})

describe('buildConfidentialityStages (REQ-EMB-010)', () => {
	const NOW = new Date('2026-06-01T00:00:00Z').getTime()

	it('an imposed-only record shows the imposed stage populated and the other two pending', () => {
		const record = {
			lifecycle: 'imposed',
			imposedAt: '2026-01-01T10:00:00Z',
		}
		const [imposed, ratification, dissolution] = buildConfidentialityStages(
			record,
			NOW,
		)
		expect(imposed.populated).toBe(true)
		expect(imposed.pending).toBe(false)
		expect(ratification.pending).toBe(true)
		expect(ratification.overdue).toBe(false)
		expect(dissolution.pending).toBe(true)
	})

	it('flags the ratification stage overdue when the deadline has passed and lifecycle is still imposed', () => {
		const record = {
			lifecycle: 'imposed',
			imposedAt: '2026-01-01T10:00:00Z',
			ratificationDeadline: '2026-03-01',
		}
		const [, ratification] = buildConfidentialityStages(record, NOW)
		expect(ratification.overdue).toBe(true)
		expect(ratification.pending).toBe(true)
	})

	it('does not flag overdue when the deadline is still in the future', () => {
		const record = {
			lifecycle: 'imposed',
			imposedAt: '2026-01-01T10:00:00Z',
			ratificationDeadline: '2026-12-01',
		}
		const [, ratification] = buildConfidentialityStages(record, NOW)
		expect(ratification.overdue).toBe(false)
	})

	it('never flags overdue once the record has moved past imposed, regardless of the stored deadline', () => {
		const record = {
			lifecycle: 'ratified',
			imposedAt: '2026-01-01T10:00:00Z',
			ratificationDeadline: '2026-03-01',
			ratificationDate: '2026-03-15T09:00:00Z',
			ratificationDecision: 'decision-1',
		}
		const [, ratification] = buildConfidentialityStages(record, NOW)
		expect(ratification.populated).toBe(true)
		expect(ratification.overdue).toBe(false)
	})

	it('shows the dissolution stage populated once liftingDate/dissolutionDecision are set', () => {
		const record = {
			lifecycle: 'dissolved',
			imposedAt: '2026-01-01T10:00:00Z',
			liftingDate: '2026-05-01',
			dissolutionDecision: 'decision-2',
		}
		const [, , dissolution] = buildConfidentialityStages(record, NOW)
		expect(dissolution.populated).toBe(true)
		expect(dissolution.pending).toBe(false)
	})

	it('is null-safe for a missing/empty record', () => {
		const [imposed, ratification, dissolution] = buildConfidentialityStages(
			null,
			NOW,
		)
		expect(imposed.pending).toBe(true)
		expect(ratification.pending).toBe(true)
		expect(dissolution.pending).toBe(true)
	})
})

describe('resolveObjectLabel (shared reference-label resolver)', () => {
	it('returns an empty string when no id is given', async () => {
		expect(
			await resolveObjectLabel(null, 'decision', 'decision', 'decidiq', ''),
		).toBe('')
		expect(
			await resolveObjectLabel(null, 'decision', 'decision', 'decidiq', null),
		).toBe('')
	})

	it('degrades to the raw id when no store is available', async () => {
		expect(
			await resolveObjectLabel(
				null,
				'decision',
				'decision',
				'decidiq',
				'abc-123',
			),
		).toBe('abc-123')
	})

	it('resolves the configured label field from the fetched object', async () => {
		const store = {
			objectTypeRegistry: { decision: {} },
			registerObjectType: () => {},
			async fetchObject(type, id) {
				expect(type).toBe('decision')
				expect(id).toBe('d-1')
				return { title: 'Besluit statutenwijziging' }
			},
		}
		const label = await resolveObjectLabel(
			store,
			'decision',
			'decision',
			'decidiq',
			'd-1',
			'title',
		)
		expect(label).toBe('Besluit statutenwijziging')
	})

	it('registers the type on the store when not already registered', async () => {
		let registered = null
		const store = {
			objectTypeRegistry: {},
			registerObjectType(typeSlug, schemaSlug, registerSlug) {
				registered = { typeSlug, schemaSlug, registerSlug }
				this.objectTypeRegistry[typeSlug] = {}
			},
			async fetchObject() {
				return { name: 'Grond raadsstukken' }
			},
		}
		await resolveObjectLabel(
			store,
			'geheimhouding-grond',
			'geheimhouding-grond',
			'decidiq',
			'g-1',
		)
		expect(registered).toEqual({
			typeSlug: 'geheimhouding-grond',
			schemaSlug: 'geheimhouding-grond',
			registerSlug: 'decidiq',
		})
	})

	it('falls back to the raw id when the fetch resolves to nothing', async () => {
		const store = {
			objectTypeRegistry: { decision: {} },
			registerObjectType: () => {},
			async fetchObject() {
				return null
			},
		}
		expect(
			await resolveObjectLabel(
				store,
				'decision',
				'decision',
				'decidiq',
				'd-2',
			),
		).toBe('d-2')
	})

	it('falls back to the raw id when the fetch throws', async () => {
		const store = {
			objectTypeRegistry: { decision: {} },
			registerObjectType: () => {},
			async fetchObject() {
				throw new Error('network error')
			},
		}
		expect(
			await resolveObjectLabel(
				store,
				'decision',
				'decision',
				'decidiq',
				'd-3',
			),
		).toBe('d-3')
	})
})
