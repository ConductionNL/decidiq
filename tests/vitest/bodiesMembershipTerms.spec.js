// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * bodies-membership-terms-contacts-and-factions (matrix rows bod-02 bod-06
 * bod-16): a membership records from when to when, members and bodies keep
 * contact details, and members belong to a faction with its own workspace.
 *
 * @spec openspec/specs/governance-bodies/spec.md
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it, vi } from 'vitest'
// The store pulls in @nextcloud/vue, which plain vitest cannot resolve; only
// the pure helpers are under test (as in memberRelations.spec.js).
vi.mock('../../src/store/store.js', () => ({
	useObjectStore: () => ({}),
	useSettingsStore: () => ({}),
}))

import {
	buildMemberRow,
	buildMembershipPayload,
} from '../../src/components/tabs/useRelationStore.js'
import {
	contactDetailPayload,
	contactSummary,
	factionsOf,
	memberRowsFor,
	startOfDay,
} from '../../src/utils/bodyMembership.js'
import { validatorFor } from './helpers/registerSchema.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

const COUNCIL = '0f5b8a8e-6a3c-4c1b-9d3e-1a2b3c4d5e61'
const GROEN = '1a2b3c4d-5e6f-4a1b-8c2d-3e4f5a6b7c85'
const ANNA = '2b3c4d5e-6f7a-4b2c-9d3e-4f5a6b7c8d12'
const PIETER = '3c4d5e6f-7a8b-4c3d-8e4f-5a6b7c8d9e23'

describe('a membership records from when to when (REQ-BMT-001)', () => {
	it('a new membership carries its start date and faction, and the schema accepts it', () => {
		const payload = buildMembershipPayload({
			personId: ANNA,
			governanceBodyId: COUNCIL,
			role: 'member',
			startDate: startOfDay('2026-03-01'),
			faction: GROEN,
		})
		expect(payload.startDate).toBe(new Date(2026, 2, 1).toISOString())
		expect(payload.faction).toBe(GROEN)
		const valid = validatorFor('membership')
		expect(valid(payload), JSON.stringify(valid.errors)).toBe(true)
	})

	it('past members are listed with from and to only when asked', () => {
		const memberships = [
			{
				id: 'm1',
				person: ANNA,
				role: 'member',
				startDate: '2022-03-01T00:00:00Z',
				endDate: '2026-06-01T00:00:00Z',
			},
			{
				id: 'm2',
				person: PIETER,
				role: 'chair',
				startDate: '2022-03-01T00:00:00Z',
			},
		]
		const persons = { [ANNA]: { name: 'Anna' }, [PIETER]: { name: 'Pieter' } }
		expect(
			memberRowsFor(memberships, persons, { past: false }).map(
				(r) => r.displayName,
			),
		).toEqual(['Pieter'])
		const past = memberRowsFor(memberships, persons, { past: true })
		expect(past.map((r) => r.displayName)).toEqual(['Anna'])
		expect(past[0].startDate).toBe('2022-03-01T00:00:00Z')
		expect(past[0].endDate).toBe('2026-06-01T00:00:00Z')
	})

	it('the add dialog asks for the start date and the members widget has a Past members switch', () => {
		const dialog = read('src/modals/MemberAddDialog.vue')
		expect(dialog).toMatch(/data-testid="member-add-start"/)
		expect(dialog).toMatch(/startDate: startOfDay\(/)
		const tab = read('src/components/tabs/GovernanceBodyMembersTab.vue')
		expect(tab).toMatch(/data-testid="body-members-past"/)
		expect(tab).toMatch(/memberRowsFor\(/)
		// Removing a member keeps the start date, so the past list can say from when.
		expect(tab).toMatch(/startDate: target\.startDate/)
	})
})

describe('contact details for members and bodies (REQ-BMT-002)', () => {
	it('a phone number for a person is a ContactDetail the schema accepts', () => {
		const payload = contactDetailPayload({
			type: 'phone',
			value: ' 06 12345678 ',
			personId: PIETER,
		})
		expect(payload).toEqual({
			type: 'phone',
			value: '06 12345678',
			person: PIETER,
		})
		const valid = validatorFor('contact-detail')
		expect(valid(payload), JSON.stringify(valid.errors)).toBe(true)
		expect(
			contactDetailPayload({
				type: 'email',
				value: 'griffie@raad.nl',
				bodyId: COUNCIL,
			}).governanceBody,
		).toBe(COUNCIL)
	})

	it('a member row shows the first email and phone', () => {
		expect(
			contactSummary([
				{ type: 'address', value: 'Stadhuisplein 1' },
				{ type: 'cell', value: '06 12345678' },
				{ type: 'email', value: 'pieter@raad.nl' },
			]),
		).toEqual({ email: 'pieter@raad.nl', phone: '06 12345678' })
		expect(contactSummary([])).toEqual({ email: '', phone: '' })
	})

	it('rows and the body offer Contact details', () => {
		const tab = read('src/components/tabs/GovernanceBodyMembersTab.vue')
		expect(tab).toMatch(/ContactDetailsDialog/)
		expect(tab).toMatch(/data-testid="body-contact-details"/)
		expect(read('src/modals/ContactDetailsDialog.vue')).toMatch(
			/contactDetailPayload\(/,
		)
	})
})

describe('members belong to a faction with its own workspace (REQ-BMT-003)', () => {
	it('the factions of a council are its sub-bodies of type faction', () => {
		const bodies = [
			{ id: GROEN, name: 'Groen', bodyType: 'faction', parentBody: COUNCIL },
			{
				id: 'x',
				name: 'Commissie',
				bodyType: 'advisory-body',
				parentBody: COUNCIL,
			},
			{ id: 'y', name: 'Rood', bodyType: 'faction', parentBody: 'other' },
		]
		expect(factionsOf(bodies, COUNCIL)).toEqual([{ id: GROEN, label: 'Groen' }])
	})

	it('a member row names its faction', () => {
		expect(
			buildMemberRow(
				{
					id: 'm1',
					person: ANNA,
					faction: GROEN,
					startDate: '2022-03-01T00:00:00Z',
				},
				{ name: 'Anna' },
			).faction,
		).toBe(GROEN)
	})

	it('the body page shows the Collectives workspace, and a faction lists the members that name it', () => {
		const manifest = JSON.parse(read('src/manifest.json'))
		const page = manifest.pages.find((p) => p.id === 'GovernanceBodyDetail')
		const widget = page.config.widgets.find((w) => w.id === 'body-workspace')
		expect(widget?.type).toBe('integration')
		expect(widget?.integrationId).toBe('collectives')
		expect(page.config.layout.some((l) => l.widgetId === 'body-workspace')).toBe(
			true,
		)
		expect(read('src/components/tabs/GovernanceBodyMembersTab.vue')).toMatch(
			/faction: this\.objectId/,
		)
	})
})
