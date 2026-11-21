/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */
import { describe, expect, it } from 'vitest'
import {
	provisioningGroups,
	provisioningGroupsPayload,
} from '../../src/utils/memberProvisioningGroups.js'

describe('memberProvisioningGroups', () => {
	it('reads the comma-separated groups trimmed and without duplicates', () => {
		expect(provisioningGroups({ member_provisioning_groups: ' council, readers,,council ' }))
			.toEqual(['council', 'readers'])
	})

	it('reads no groups from missing settings', () => {
		expect(provisioningGroups({})).toEqual([])
		expect(provisioningGroups(undefined)).toEqual([])
	})

	it('writes the groups as one comma-separated value', () => {
		expect(provisioningGroupsPayload([' council', 'readers ', ''])).toEqual({ member_provisioning_groups: 'council,readers' })
		expect(provisioningGroupsPayload([])).toEqual({ member_provisioning_groups: '' })
	})
})
