// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * platform-role-rights-per-record-type (matrix row plt-03): the admin page
 * shows per record type who may read and change it, and maps roles to groups.
 *
 * @spec openspec/specs/authorization-via-or-rbac/spec.md#requirement-req-prr-001-administrators-see-and-map-rights-per-record-type
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { mappingOf, ruleSummary } from '../../src/utils/roleRights.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')
const words = {
	everyone: 'Everyone',
	signedIn: 'Signed-in users',
	nobody: 'Nobody',
	conditional: '{who} (under a condition)',
}

describe('rights per record type (REQ-PRR-001)', () => {
	it('says who may change minutes, the mapped group included', () => {
		const update = [
			{ group: 'decidiq-administrators', conditional: false },
			{ group: 'Griffie', conditional: false },
		]
		expect(ruleSummary(update, words)).toBe('decidiq-administrators, Griffie')
	})

	it('names everyone and signed-in users in words, and marks a condition', () => {
		const read = [
			{ group: 'public', conditional: true },
			{ group: 'authenticated', conditional: false },
		]
		expect(ruleSummary(read, words)).toBe(
			'Everyone (under a condition), Signed-in users',
		)
		expect(ruleSummary([], words)).toBe('Nobody')
	})

	it('sends role => group ids, from strings or picker options', () => {
		expect(
			mappingOf([
				{
					role: 'administrators',
					mapped: [{ id: 'Griffie', displayName: 'Griffie' }, 'Raad', ' '],
				},
				{ role: 'secretariat', mapped: [] },
			]),
		).toEqual({ administrators: ['Griffie', 'Raad'], secretariat: [] })
	})

	it('is on the admin settings page', () => {
		const root = read('src/views/settings/AdminRoot.vue')
		expect(root).toContain('RoleRightsSettings')
		const page = read('src/views/settings/RoleRightsSettings.vue')
		expect(page).toContain('/apps/decidiq/api/settings/role-rights')
		expect(page).toMatch(
			/NcSelect[\s\S]*?:input-label|NcSelect[\s\S]*?inputLabel/,
		)
	})
})
