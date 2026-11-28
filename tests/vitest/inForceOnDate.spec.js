/**
 * SPDX-FileCopyrightText: 2026 Conduction / Decidiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "In force on date X" on the delegations index: a chosen day becomes the
 * four-key validity-window query in the address, clearing it takes those
 * keys off, and every other key of the address stays.
 *
 * @spec openspec/changes/delegatie-mandaatregister/specs/delegatie-mandaatregister/spec.md#requirement-req-dmr-004-register-views-per-delegans-per-delegataris-in-force-on-date-search-csv-export
 */
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it, vi } from 'vitest'
import {
	dayFromQuery,
	inForceOnDateQuery,
	isoDay,
	withInForceDay,
} from '../../src/utils/inForceOnDate.js'
import { loadSfcScript } from './helpers/sfcScript.js'

const FILE = path.resolve(__dirname, '../../src/components/widgets/InForceOnDateFilter.vue')
const MANIFEST = path.resolve(__dirname, '../../src/manifest.d/delegatie-mandaatregister.json')
const REGISTRY = path.resolve(__dirname, '../../src/registry.js')

describe('in force on date X (REQ-DMR-004)', () => {
	it('asks for in force, valid from X or earlier, valid until X or later or open-ended', () => {
		expect(inForceOnDateQuery('2026-06-01')).toEqual({
			status: 'effective',
			'validFrom[lte]': '2026-06-01',
			'validTo[gte]': '2026-06-01',
			'validTo[orNull]': 'true',
		})
	})

	it('reads a date as the user\'s own calendar day', () => {
		expect(isoDay(new Date(2026, 5, 1, 23, 30))).toBe('2026-06-01')
		expect(isoDay('2026-06-01')).toBe('2026-06-01')
		expect(isoDay('1 June')).toBe('')
		expect(isoDay(null)).toBe('')
		expect(isoDay(new Date('nope'))).toBe('')
	})

	it('sets the day and keeps the other keys of the address', () => {
		const next = withInForceDay({ _search: 'budget', type: 'mandate' }, '2026-06-01')
		expect(next).toEqual({ _search: 'budget', type: 'mandate', ...inForceOnDateQuery('2026-06-01') })
		expect(dayFromQuery(next)).toBe('2026-06-01')
	})

	it('moves to another day without leaving the first one behind', () => {
		const first = withInForceDay({}, '2026-06-01')
		expect(withInForceDay(first, '2026-07-15')).toEqual(inForceOnDateQuery('2026-07-15'))
	})

	it('clears the four keys and nothing else', () => {
		const set = withInForceDay({ type: 'mandate' }, '2026-06-01')
		expect(withInForceDay(set, '')).toEqual({ type: 'mandate' })
	})

	it('leaves a status the user chose alone when no day was set', () => {
		expect(withInForceDay({ status: 'withdrawn' }, '')).toEqual({ status: 'withdrawn' })
		expect(dayFromQuery({ status: 'withdrawn', 'validFrom[lte]': '2026-06-01', 'validTo[gte]': '2026-06-01' })).toBe('')
		expect(dayFromQuery(null)).toBe('')
	})
})

describe('InForceOnDateFilter', () => {
	const vmOf = (bag) => ({ ...bag })
	const component = loadSfcScript(FILE, {
		'@nextcloud/vue': { NcDateTimePickerNative: {} },
		'../../utils/inForceOnDate.js': { dayFromQuery, isoDay, withInForceDay },
	})

	it('shows the day the address asks about', () => {
		const vm = vmOf({ $route: { query: inForceOnDateQuery('2026-06-01') } })
		const picked = component.computed.pickedDate.call(vm)
		expect(isoDay(picked)).toBe('2026-06-01')
		expect(component.computed.pickedDate.call(vmOf({ $route: { query: {} } }))).toBeNull()
	})

	it('writes the picked day into the address', () => {
		const replace = vi.fn(() => Promise.resolve())
		const vm = vmOf({ $route: { query: { type: 'mandate' } }, $router: { replace } })
		component.methods.onPick.call(vm, new Date(2026, 5, 1))
		expect(replace).toHaveBeenCalledWith({ query: { type: 'mandate', ...inForceOnDateQuery('2026-06-01') } })
	})

	it('does not navigate when nothing changes', () => {
		const replace = vi.fn(() => Promise.resolve())
		const vm = vmOf({ $route: { query: {} }, $router: { replace } })
		component.methods.onPick.call(vm, null)
		expect(replace).not.toHaveBeenCalled()
	})
})

describe('the delegations index carries the date choice', () => {
	it('places the widget above the list and registers it', () => {
		const manifest = JSON.parse(fs.readFileSync(MANIFEST, 'utf8'))
		const index = manifest.pages.find((p) => p.id === 'AuthorityDelegations')
		expect(index.config.statRow.map((w) => w.type)).toContain('InForceOnDateFilter')
		expect(fs.readFileSync(REGISTRY, 'utf8')).toMatch(/InForceOnDateFilter: page\(InForceOnDateFilter\)/)
	})
})
