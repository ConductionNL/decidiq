/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest — Membership/Person helpers in useRelationStore.js
 * (model-debt-cleanup-code). GovernanceBodyMembersTab.vue and its four
 * dialogs used to read/write the deprecated flat `Participant` schema
 * directly; these plain-function helpers now do the row-join and
 * payload-building work so the schema-shape change stays unit-testable
 * without needing a DOM/Vue-render harness (this repo's vitest config runs
 * on plain Vite with no @vitejs/plugin-vue registered — see
 * registerDetailWidgets.spec.js's own note on the same constraint).
 *
 * @spec openspec/changes/model-debt-cleanup-code/specs/admin-settings/spec.md
 */
import { describe, expect, it, vi } from 'vitest'

// useRelationStore.js's module-level import of src/store/store.js pulls in
// @nextcloud/vue (for the rest of the app's stores), which has no "exports"
// entry Vite/Vitest can resolve in this repo's plain-Vite config (same
// constraint registerDetailWidgets.spec.js documents). Only the pure
// Membership/Person helpers below are under test, so the store module is
// stubbed out — mirroring ensureRelationType.spec.js's own mock.
vi.mock('../../src/store/store.js', () => ({
	useObjectStore: () => ({}),
	useSettingsStore: () => ({}),
}))

import {
	buildMemberRow,
	buildMemberRows,
	buildMembershipPayload,
	buildPersonPayload,
	isActiveMembership,
	resolveOrCreatePerson,
} from '../../src/components/tabs/useRelationStore.js'

describe('isActiveMembership', () => {
	it('is active when endDate is absent', () => {
		expect(isActiveMembership({ id: 'm1' })).toBe(true)
	})

	it('is active when endDate is null', () => {
		expect(isActiveMembership({ id: 'm1', endDate: null })).toBe(true)
	})

	it('is inactive when endDate is set', () => {
		expect(
			isActiveMembership({ id: 'm1', endDate: '2026-01-01T00:00:00Z' }),
		).toBe(false)
	})
})

describe('buildMemberRow', () => {
	it('joins a Membership to its Person for display', () => {
		const membership = {
			id: 'm1',
			person: 'p1',
			governanceBody: 'gb1',
			role: 'chair',
			party: 'GroenLinks',
			votingWeight: 2,
		}
		const person = { id: 'p1', name: 'Roos de Vries', email: 'roos@example.org' }
		expect(buildMemberRow(membership, person)).toEqual({
			id: 'm1',
			person: 'p1',
			governanceBody: 'gb1',
			role: 'chair',
			party: 'GroenLinks',
			votingWeight: 2,
			endDate: null,
			displayName: 'Roos de Vries',
			email: 'roos@example.org',
			nextcloudUserId: '',
		})
	})

	it('falls back to the raw Person id when the Person is unresolved', () => {
		const row = buildMemberRow({ id: 'm1', person: 'p1', role: 'member' }, null)
		expect(row.displayName).toBe('p1')
		expect(row.email).toBe('')
		expect(row.nextcloudUserId).toBe('')
	})

	it('carries an existing endDate through unchanged', () => {
		const row = buildMemberRow(
			{ id: 'm1', person: 'p1', endDate: '2026-05-01T00:00:00Z' },
			{ id: 'p1', name: 'Jan' },
		)
		expect(row.endDate).toBe('2026-05-01T00:00:00Z')
	})
})

describe('buildMemberRows', () => {
	const memberships = [
		{ id: 'm1', person: 'p1', role: 'chair' },
		{ id: 'm2', person: 'p2', role: 'member', endDate: '2025-01-01T00:00:00Z' },
		{ id: 'm3', person: 'p3', role: 'secretary' },
	]
	const personsById = {
		p1: { id: 'p1', name: 'Roos' },
		p3: { id: 'p3', name: 'Jan' },
	}

	it('drops ended memberships and joins the rest to their Person', () => {
		const rows = buildMemberRows(memberships, personsById)
		expect(rows.map((r) => r.id)).toEqual(['m1', 'm3'])
		expect(rows[0].displayName).toBe('Roos')
		expect(rows[1].displayName).toBe('Jan')
	})

	it('returns an empty array for an empty/undefined input', () => {
		expect(buildMemberRows(undefined)).toEqual([])
		expect(buildMemberRows([])).toEqual([])
	})
})

describe('buildPersonPayload', () => {
	it('trims the name and omits empty optional fields', () => {
		expect(buildPersonPayload({ name: '  Roos de Vries  ' })).toEqual({
			name: 'Roos de Vries',
		})
	})

	it('includes email and nextcloudUserId when present', () => {
		expect(
			buildPersonPayload({
				name: 'Jan',
				email: 'jan@example.org',
				nextcloudUserId: 'jdoe',
			}),
		).toEqual({ name: 'Jan', email: 'jan@example.org', nextcloudUserId: 'jdoe' })
	})
})

describe('buildMembershipPayload', () => {
	it('builds the required fields and omits empty optional ones', () => {
		expect(
			buildMembershipPayload({
				personId: 'p1',
				governanceBodyId: 'gb1',
				role: 'member',
			}),
		).toEqual({ person: 'p1', governanceBody: 'gb1', role: 'member' })
	})

	it('includes party, votingWeight and id when provided', () => {
		expect(
			buildMembershipPayload({
				personId: 'p1',
				governanceBodyId: 'gb1',
				role: 'chair',
				party: 'GroenLinks',
				votingWeight: 2,
				id: 'm1',
			}),
		).toEqual({
			person: 'p1',
			governanceBody: 'gb1',
			role: 'chair',
			party: 'GroenLinks',
			votingWeight: 2,
			id: 'm1',
		})
	})

	it('keeps a votingWeight of 0 (falsy but valid)', () => {
		expect(
			buildMembershipPayload({
				personId: 'p1',
				governanceBodyId: 'gb1',
				role: 'observer',
				votingWeight: 0,
			}),
		).toEqual({
			person: 'p1',
			governanceBody: 'gb1',
			role: 'observer',
			votingWeight: 0,
		})
	})
})

describe('resolveOrCreatePerson', () => {
	it('reuses an existing Person matched by exact email', async () => {
		const fetchCollection = vi
			.fn()
			.mockResolvedValue([{ id: 'p1', name: 'Roos' }])
		const saveObject = vi.fn()
		const store = { fetchCollection, saveObject }

		const person = await resolveOrCreatePerson(store, {
			name: 'Roos de Vries',
			email: 'roos@example.org',
		})

		expect(fetchCollection).toHaveBeenCalledWith('person', {
			email: 'roos@example.org',
			_limit: 1,
		})
		expect(saveObject).not.toHaveBeenCalled()
		expect(person).toEqual({ id: 'p1', name: 'Roos' })
	})

	it('creates a new Person when no email match exists', async () => {
		const fetchCollection = vi.fn().mockResolvedValue([])
		const saveObject = vi.fn().mockResolvedValue({ id: 'p2', name: 'Jan' })
		const store = { fetchCollection, saveObject }

		const person = await resolveOrCreatePerson(store, {
			name: 'Jan',
			email: 'jan@example.org',
		})

		expect(saveObject).toHaveBeenCalledWith('person', {
			name: 'Jan',
			email: 'jan@example.org',
		})
		expect(person).toEqual({ id: 'p2', name: 'Jan' })
	})

	it('creates directly, skipping the match step, when no email is given', async () => {
		const fetchCollection = vi.fn()
		const saveObject = vi.fn().mockResolvedValue({ id: 'p3', name: 'No Email' })
		const store = { fetchCollection, saveObject }

		await resolveOrCreatePerson(store, { name: 'No Email' })

		expect(fetchCollection).not.toHaveBeenCalled()
		expect(saveObject).toHaveBeenCalledWith('person', { name: 'No Email' })
	})
})
