// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * bodies-shared-body-participations (matrix row bod-13): the secretary keeps
 * the participations of a shared body, and each organisation shows how many
 * of its seats are filled by members sitting on its behalf.
 *
 * @spec openspec/changes/bodies-shared-body-participations/specs/shared-governance-bodies/spec.md#requirement-req-sgbp-001-the-secretary-keeps-the-participations-of-a-shared-body
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it, vi } from 'vitest'
// The store pulls in @nextcloud/vue, which plain vitest cannot resolve; only
// the pure helpers are under test.
vi.mock('../../src/store/store.js', () => ({
	useObjectStore: () => ({}),
	useSettingsStore: () => ({}),
}))

import { buildMembershipPayload } from '../../src/components/tabs/useRelationStore.js'
import {
	buildParticipationPayload,
	calendarDate,
	endParticipationPayload,
	isActiveParticipation,
	isSharedBody,
	onBehalfOfOptions,
	PARTICIPATION_SCHEMA,
	participationQuery,
	participationRows,
} from '../../src/utils/bodyParticipations.js'
import { validatorFor } from './helpers/registerSchema.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

const REGIO = '0f5b8a8e-6a3c-4c1b-9d3e-1a2b3c4d5e61'
const OSS = '1a2b3c4d-5e6f-4a1b-8c2d-3e4f5a6b7c85'
const UDEN = '2b3c4d5e-6f7a-4b2c-9d3e-4f5a6b7c8d12'
const PIETER = '3c4d5e6f-7a8b-4c3d-8e4f-5a6b7c8d9e23'
const NOW = new Date('2026-10-09T12:00:00Z')

const bodies = {
	[OSS]: { id: OSS, name: 'Gemeente Oss' },
	[UDEN]: { id: UDEN, name: 'Gemeente Uden' },
}

describe('adding a participating municipality (REQ-SGBP-001)', () => {
	it('the widget lists Gemeente Oss with 2 seats, 0 filled, and the schema accepts the payload', () => {
		const payload = buildParticipationPayload({
			composite: REGIO,
			component: OSS,
			seats: '2',
			votingWeight: '3',
			accessionDate: '2026-10-09',
		})
		expect(payload).toMatchObject({
			composite: REGIO,
			component: OSS,
			seats: 2,
			votingWeight: 3,
		})
		expect(payload.accessionDate).toBe('2026-10-09')
		const valid = validatorFor('governance-body-composition')
		expect(valid(payload), JSON.stringify(valid.errors)).toBe(true)

		const rows = participationRows([{ id: 'p1', ...payload }], [], bodies, NOW)
		expect(rows).toEqual([
			expect.objectContaining({
				id: 'p1',
				participant: OSS,
				name: 'Gemeente Oss',
				seats: 2,
				filled: 0,
				active: true,
			}),
		])
	})

	it('leaves out what the secretary left empty, so an empty weight is not a zero', () => {
		const payload = buildParticipationPayload({
			composite: REGIO,
			component: OSS,
			seats: '',
			votingWeight: '',
			accessionDate: '',
		})
		expect(payload).toEqual({ composite: REGIO, component: OSS, compositionType: 'representation' })
		expect(validatorFor('governance-body-composition')(payload)).toBe(true)
	})

	it('an edit keeps the id so the store updates instead of creating', () => {
		const payload = buildParticipationPayload({
			id: 'p1',
			composite: REGIO,
			component: OSS,
			seats: 3,
		})
		expect(payload.id).toBe('p1')
	})
})

describe('a member fills a seat (REQ-SGBP-001)', () => {
	const participation = {
		id: 'p1',
		composite: REGIO,
		component: OSS,
		seats: 2,
	}

	it('a membership on behalf of Gemeente Oss carries onBehalfOf and the schema accepts it', () => {
		const payload = buildMembershipPayload({
			personId: PIETER,
			governanceBodyId: REGIO,
			role: 'member',
			onBehalfOf: OSS,
		})
		expect(payload.onBehalfOf).toBe(OSS)
		const valid = validatorFor('membership')
		expect(valid(payload), JSON.stringify(valid.errors)).toBe(true)
	})

	it('a membership without an organisation carries no onBehalfOf', () => {
		const payload = buildMembershipPayload({
			personId: PIETER,
			governanceBodyId: REGIO,
			role: 'member',
		})
		expect('onBehalfOf' in payload).toBe(false)
	})

	it('the widget reads 1 of 2 seats filled for Gemeente Oss', () => {
		const memberships = [
			{ id: 'm1', governanceBody: REGIO, onBehalfOf: OSS },
			// Ended: no longer fills the seat.
			{
				id: 'm2',
				governanceBody: REGIO,
				onBehalfOf: OSS,
				endDate: '2026-01-01T00:00:00Z',
			},
			// On behalf of another organisation.
			{ id: 'm3', governanceBody: REGIO, onBehalfOf: UDEN },
			// Not on behalf of anyone.
			{ id: 'm4', governanceBody: REGIO },
		]
		const [row] = participationRows([participation], memberships, bodies, NOW)
		expect(row.filled).toBe(1)
		expect(row.seats).toBe(2)
	})

	it('a membership that ends later this year still fills its seat', () => {
		const memberships = [
			{
				id: 'm1',
				governanceBody: REGIO,
				onBehalfOf: OSS,
				endDate: '2026-12-31T00:00:00Z',
			},
		]
		const [row] = participationRows([participation], memberships, bodies, NOW)
		expect(row.filled).toBe(1)
	})
})

describe('ending a participation (REQ-SGBP-001)', () => {
	it('sets the withdrawal date to today and keeps the rest, valid against the schema', () => {
		const payload = endParticipationPayload(
			{
				id: 'p1',
				composite: REGIO,
				component: OSS,
				seats: 2,
				votingWeight: 3,
				accessionDate: '2015-01-01T00:00:00Z',
				'@self': { id: 'p1' },
			},
			NOW,
		)
		const pad = (number) => String(number).padStart(2, '0')
		expect(payload.exitDate).toBe(
			`${NOW.getFullYear()}-${pad(NOW.getMonth() + 1)}-${pad(NOW.getDate())}`,
		)
		// A date-time stored before the move keeps the day it named.
		expect(payload.accessionDate).toBe('2015-01-01')
		expect(payload.id).toBe('p1')
		expect(payload.seats).toBe(2)
		expect('@self' in payload).toBe(false)
		const { id, ...rest } = payload
		expect(id).toBe('p1')
		expect(validatorFor('governance-body-composition')(rest)).toBe(true)
	})

	it('a participation is active until its withdrawal date has passed', () => {
		expect(isActiveParticipation({}, NOW)).toBe(true)
		expect(isActiveParticipation({ exitDate: null }, NOW)).toBe(true)
		expect(
			isActiveParticipation({ exitDate: '2027-01-01T00:00:00Z' }, NOW),
		).toBe(true)
		expect(
			isActiveParticipation({ exitDate: '2026-01-01T00:00:00Z' }, NOW),
		).toBe(false)
	})

	it('a withdrawn organisation stays listed, marked as no longer active, after the active ones', () => {
		const rows = participationRows(
			[
				{
					id: 'p2',
					component: UDEN,
					seats: 1,
					exitDate: '2025-01-01T00:00:00Z',
				},
				{ id: 'p1', component: OSS, seats: 2 },
			],
			[],
			bodies,
			NOW,
		)
		expect(rows.map((r) => [r.name, r.active])).toEqual([
			['Gemeente Oss', true],
			['Gemeente Uden', false],
		])
	})
})

describe('on behalf of in the add-member dialog (REQ-SGBP-001)', () => {
	it('only a shared body asks on behalf of which organisation', () => {
		expect(isSharedBody({ bodyType: 'shared-body' })).toBe(true)
		expect(isSharedBody({ bodyType: 'legislative' })).toBe(false)
		expect(isSharedBody(null)).toBe(false)
	})

	it('offers the active participating organisations by name', () => {
		const options = onBehalfOfOptions(
			[
				{ id: 'p1', component: UDEN },
				{ id: 'p2', component: OSS },
				{
					id: 'p3',
					component: 'gone',
					exitDate: '2020-01-01T00:00:00Z',
				},
			],
			bodies,
			NOW,
		)
		expect(options).toEqual([
			{ id: OSS, label: 'Gemeente Oss' },
			{ id: UDEN, label: 'Gemeente Uden' },
		])
	})
})

describe('the widget is wired into the shared body page (REQ-SGBP-001)', () => {
	it('GovernanceBodyDetail renders the participations widget instead of the read-only list', () => {
		const manifest = JSON.parse(read('src/manifest.json'))
		const page = manifest.pages.find((p) => p.id === 'GovernanceBodyDetail')
		const widget = page.config.widgets.find(
			(w) => w.id === 'body-participating-orgs',
		)
		expect(widget).toMatchObject({
			type: 'custom',
			component: 'BodyParticipationsTab',
		})
		expect(
			page.config.layout.some((l) => l.widgetId === 'body-participating-orgs'),
		).toBe(true)
	})

	it('the registry registers the widget and the dialog lives under src/dialogs', () => {
		expect(read('src/registry.js')).toMatch(
			/BodyParticipationsTab: page\(BodyParticipationsTab\)/,
		)
		const tab = read('src/components/tabs/BodyParticipationsTab.vue')
		expect(tab).toMatch(
			/import BodyParticipationDialog from '..\/..\/dialogs\/BodyParticipationDialog.vue'/,
		)
		expect(tab).toMatch(/endParticipationPayload/)
		expect(read('src/dialogs/BodyParticipationDialog.vue')).toMatch(
			/buildParticipationPayload/,
		)
	})

	it('the add-member dialog sends the chosen organisation as onBehalfOf', () => {
		const dialog = read('src/modals/MemberAddDialog.vue')
		expect(dialog).toMatch(/onBehalfOf: this\.selectedOnBehalfOf\?\.id/)
		expect(dialog).toMatch(/isSharedBody\(body\)/)
	})
})

/**
 * @spec openspec/changes/configurable-types-domain-model/specs/configurable-types-domain-model/spec.md#requirement-req-ctm-009-a-gremium-may-be-composed-of-other-gremia
 */
describe('a participation is a representation composition (REQ-CTM-009)', () => {
	it('is read and written as a governance-body-composition of the shared body', () => {
		expect(PARTICIPATION_SCHEMA).toBe('governance-body-composition')
		expect(participationQuery(42)).toEqual({
			composite: '42',
			compositionType: 'representation',
			_limit: 200,
		})
	})

	it('stores calendar dates, keeping the day a stored date-time named', () => {
		expect(calendarDate(new Date(2026, 9, 9, 23, 30))).toBe('2026-10-09')
		expect(calendarDate('2026-10-09')).toBe('2026-10-09')
		expect(calendarDate('2015-01-01T00:00:00Z')).toBe('2015-01-01')
		expect(calendarDate('2026-02-30')).toBe('')
		expect(calendarDate('not a date')).toBe('')
		expect(calendarDate(new Date('nonsense'))).toBe('')
		expect(calendarDate(null)).toBe('')
	})
})
