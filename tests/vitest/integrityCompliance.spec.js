// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Review status of other positions: the dashboard KPI "Other positions
 * without a current review" and the per-body compliance panel on the
 * ancillary-positions index (interests-and-integrity, REQ-INT-010).
 *
 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
 */

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import * as compliance from '../../src/utils/integrityCompliance.js'
import { instanceOf, loadSfcScript, recordingStores } from './helpers/sfcScript.js'

const {
	complianceRows,
	isActiveMembership,
	isReviewOverdue,
	lastReviewTime,
	overdueReviews,
	overdueReviewsRoute,
	refId,
	reviewCutoff,
} = compliance

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const read = (file) => readFileSync(resolve(ROOT, file), 'utf8')

const NOW = new Date('2026-11-14T12:00:00Z').getTime()
function monthsAgo(n) {
	const d = new Date(NOW)
	d.setMonth(d.getMonth() - n)
	return d.toISOString()
}

describe('reviewCutoff and lastReviewTime', () => {
	it('puts the cut-off twelve calendar months back', () => {
		expect(new Date(reviewCutoff(NOW)).toISOString()).toBe('2025-11-14T12:00:00.000Z')
	})

	it('uses the review date, else the declaration date, else nothing', () => {
		expect(lastReviewTime({ reviewedAt: '2026-01-01', declaredAt: '2020-01-01' }))
			.toBe(new Date('2026-01-01').getTime())
		expect(lastReviewTime({ reviewedAt: '', declaredAt: '2020-01-01' }))
			.toBe(new Date('2020-01-01').getTime())
		expect(lastReviewTime({})).toBeNaN()
		expect(lastReviewTime(null)).toBeNaN()
	})
})

describe('isReviewOverdue', () => {
	it('flags a position reviewed 13 months ago', () => {
		expect(isReviewOverdue({ lifecycle: 'public', reviewedAt: monthsAgo(13) }, NOW)).toBe(true)
	})

	it('keeps a position reviewed 2 months ago current', () => {
		expect(isReviewOverdue({ lifecycle: 'public', reviewedAt: monthsAgo(2) }, NOW)).toBe(false)
	})

	it('falls back to the declaration date when never reviewed', () => {
		expect(isReviewOverdue({ lifecycle: 'reported', declaredAt: monthsAgo(14) }, NOW)).toBe(true)
		expect(isReviewOverdue({ lifecycle: 'reported', declaredAt: monthsAgo(1) }, NOW)).toBe(false)
	})

	it('flags a position with neither date', () => {
		expect(isReviewOverdue({ lifecycle: 'internal' }, NOW)).toBe(true)
	})

	it('never flags an ended position or nothing', () => {
		expect(isReviewOverdue({ lifecycle: 'ended', reviewedAt: monthsAgo(30) }, NOW)).toBe(false)
		expect(isReviewOverdue(null, NOW)).toBe(false)
	})

	it('a recent review wins over an old declaration', () => {
		expect(isReviewOverdue(
			{ lifecycle: 'public', declaredAt: monthsAgo(40), reviewedAt: monthsAgo(3) },
			NOW,
		)).toBe(false)
	})
})

describe('overdueReviews (dashboard KPI)', () => {
	it('counts two of: two outdated, one current, one ended (spec scenario)', () => {
		const positions = [
			{ id: 'a', lifecycle: 'public', reviewedAt: monthsAgo(14) },
			{ id: 'b', lifecycle: 'internal', reviewedAt: monthsAgo(13) },
			{ id: 'c', lifecycle: 'public', reviewedAt: monthsAgo(1) },
			{ id: 'd', lifecycle: 'ended', reviewedAt: monthsAgo(20) },
		]
		expect(overdueReviews(positions, NOW).map((p) => p.id)).toEqual(['a', 'b'])
	})

	it('treats a non-array as no positions', () => {
		expect(overdueReviews(null, NOW)).toEqual([])
		expect(overdueReviews({}, NOW)).toEqual([])
	})

	it('deep-links to the index narrowed to open positions with an old review', () => {
		expect(overdueReviewsRoute()).toEqual({
			name: 'AncillaryPositions',
			query: {
				lifecycle: ['reported', 'public', 'internal'],
				'reviewedAt[lt]': '@today-365d',
			},
		})
	})

	it('returns a fresh lifecycle list each time', () => {
		overdueReviewsRoute().query.lifecycle.push('ended')
		expect(overdueReviewsRoute().query.lifecycle).toEqual(['reported', 'public', 'internal'])
	})
})

describe('refId and isActiveMembership', () => {
	it('reads an id from a plain value or an object', () => {
		expect(refId('p1')).toBe('p1')
		expect(refId(7)).toBe('7')
		expect(refId({ id: 'p2' })).toBe('p2')
		expect(refId({ uuid: 'p3' })).toBe('p3')
		expect(refId({})).toBe('')
		expect(refId(null)).toBe('')
		expect(refId(undefined)).toBe('')
	})

	it('treats a membership as active between its start and end', () => {
		expect(isActiveMembership({}, NOW)).toBe(true)
		expect(isActiveMembership({ startDate: monthsAgo(5) }, NOW)).toBe(true)
		expect(isActiveMembership({ startDate: monthsAgo(-1) }, NOW)).toBe(false)
		expect(isActiveMembership({ endDate: monthsAgo(1) }, NOW)).toBe(false)
		expect(isActiveMembership({ startDate: monthsAgo(5), endDate: monthsAgo(-5) }, NOW)).toBe(true)
		expect(isActiveMembership(null, NOW)).toBe(false)
	})
})

describe('complianceRows (compliance panel)', () => {
	const memberships = [
		{ person: 'p-current', governanceBody: 'raad' },
		{ person: 'p-none', governanceBody: 'raad' },
		{ person: { id: 'p-stale' }, governanceBody: 'raad' },
	]
	const positions = [
		{ person: 'p-current', lifecycle: 'public', reviewedAt: monthsAgo(2) },
		{ person: 'p-stale', lifecycle: 'public', reviewedAt: monthsAgo(14) },
	]

	it('marks the member without a declaration and the one with an old review (spec scenario)', () => {
		const rows = complianceRows(memberships, positions, NOW, {
			'p-current': 'Anna',
			'p-none': 'Bram',
			'p-stale': 'Cees',
		})
		expect(rows).toEqual([
			{ person: 'p-none', name: 'Bram', status: 'none', positions: 0 },
			{ person: 'p-stale', name: 'Cees', status: 'stale', positions: 1 },
			{ person: 'p-current', name: 'Anna', status: 'current', positions: 1 },
		])
	})

	it('falls back to the person id when the name is unknown', () => {
		const rows = complianceRows([{ person: 'p-x' }], [], NOW)
		expect(rows).toEqual([{ person: 'p-x', name: 'p-x', status: 'none', positions: 0 }])
	})

	it('does not count an ended position as a declaration', () => {
		const rows = complianceRows(
			[{ person: 'p1' }],
			[{ person: 'p1', lifecycle: 'ended', reviewedAt: monthsAgo(1) }],
			NOW,
		)
		expect(rows[0].status).toBe('none')
	})

	it('is current when any one of several positions was reviewed recently', () => {
		const rows = complianceRows(
			[{ person: 'p1' }],
			[
				{ person: 'p1', lifecycle: 'public', reviewedAt: monthsAgo(20) },
				{ person: { id: 'p1' }, lifecycle: 'internal', reviewedAt: monthsAgo(1) },
			],
			NOW,
		)
		expect(rows).toEqual([{ person: 'p1', name: 'p1', status: 'current', positions: 2 }])
	})

	it('skips ended memberships, memberships without a person, and duplicates', () => {
		const rows = complianceRows(
			[
				{ person: 'p1' },
				{ person: 'p1' },
				{ person: 'p2', endDate: monthsAgo(2) },
				{ person: '' },
				null,
			],
			[{ lifecycle: 'public' }, null],
			NOW,
		)
		expect(rows.map((r) => r.person)).toEqual(['p1'])
	})

	it('sorts by status, then by name', () => {
		const rows = complianceRows(
			[{ person: 'b' }, { person: 'a' }, { person: 'c' }],
			[{ person: 'c', lifecycle: 'public', reviewedAt: monthsAgo(1) }],
			NOW,
			{ a: 'Zoë', b: 'Arie', c: 'Aad' },
		)
		expect(rows.map((r) => r.name)).toEqual(['Arie', 'Zoë', 'Aad'])
	})

	it('treats non-arrays as empty', () => {
		expect(complianceRows(null, null, NOW)).toEqual([])
	})
})

describe('OverdueReviewsKpiWidget', () => {
	const FILE = resolve(ROOT, 'src/views/dashboard/widgets/OverdueReviewsKpiWidget.vue')

	function load(answer) {
		const stores = recordingStores(answer)
		const component = loadSfcScript(FILE, {
			'@conduction/nextcloud-vue': { CnStatsBlock: {} },
			'vue-material-design-icons/BriefcaseClockOutline.vue': { default: {} },
			'../../../components/tabs/useRelationStore.js': {
				ensureRelationType: stores.ensureRelationType,
			},
			'../../../utils/integrityCompliance.js': compliance,
			'./dashboardRefreshMixin.js': { default: {} },
		})
		const vm = instanceOf(component, {})
		vm.loading = false
		vm.error = null
		return { component, vm, calls: stores.calls }
	}

	it('fetches the positions that have not ended in one list query', async () => {
		const { vm, calls } = load(() => [{ id: 'x' }])
		await vm.load()
		expect(calls).toEqual([{
			fn: 'fetchCollection',
			type: 'ancillary-position',
			schema: 'ancillary-position',
			params: { lifecycle: ['reported', 'public', 'internal'], _limit: 500 },
		}])
		expect(vm.positions).toEqual([{ id: 'x' }])
		expect(vm.loading).toBe(false)
	})

	it('counts the overdue positions and warns while there are any', () => {
		const { component, vm } = load(() => [])
		vm.positions = [
			{ lifecycle: 'public', reviewedAt: '2000-01-01' },
			{ lifecycle: 'public', reviewedAt: new Date().toISOString() },
		]
		const count = component.computed.count.call(vm)
		expect(count).toBe(1)
		expect(component.computed.variant.call({ count })).toBe('warning')
		expect(component.computed.variant.call({ count: 0 })).toBe('default')
		expect(component.computed.route.call(vm)).toEqual(overdueReviewsRoute())
	})

	it('shows the error and no positions when the fetch fails', async () => {
		const { vm } = load(() => {
			throw new Error('down')
		})
		await vm.load()
		expect(vm.error).toBeInstanceOf(Error)
		expect(vm.positions).toEqual([])
		expect(vm.loading).toBe(false)
	})
})

describe('AncillaryCompliancePanel', () => {
	const FILE = resolve(ROOT, 'src/components/AncillaryCompliancePanel.vue')

	function load(answer) {
		const stores = recordingStores(answer)
		const component = loadSfcScript(FILE, {
			'@nextcloud/vue': { NcSelect: {} },
			'./tabs/useRelationStore.js': { ensureRelationType: stores.ensureRelationType },
			'../utils/integrityCompliance.js': compliance,
		})
		return { component, vm: instanceOf(component, {}), calls: stores.calls }
	}

	it('loads one body with exactly two list queries', async () => {
		const { vm, calls } = load((call) => (call.schema === 'membership'
			? [{ person: 'p1' }]
			: [{ person: 'p1', lifecycle: 'public' }]))
		vm.body = { id: 'raad', label: 'Raad' }
		await vm.loadBody('raad')
		expect(calls).toEqual([
			{ fn: 'fetchCollection', type: 'membership', schema: 'membership', params: { governanceBody: 'raad', _limit: 500 } },
			{ fn: 'fetchCollection', type: 'ancillary-position', schema: 'ancillary-position', params: { governanceBody: 'raad', _limit: 500 } },
		])
		expect(vm.memberships).toEqual([{ person: 'p1' }])
		expect(vm.positions).toEqual([{ person: 'p1', lifecycle: 'public' }])
		expect(vm.loading).toBe(false)
		expect(vm.error).toBe('')
	})

	it('queries nothing when no body is chosen', async () => {
		const { vm, calls } = load(() => [])
		vm.memberships = [{ person: 'old' }]
		await vm.loadBody('')
		expect(calls).toEqual([])
		expect(vm.memberships).toEqual([])
	})

	it('drops an answer for a body that is no longer chosen', async () => {
		const { vm } = load(() => [{ person: 'p1' }])
		vm.body = { id: 'other' }
		await vm.loadBody('raad')
		expect(vm.memberships).toEqual([])
		expect(vm.positions).toEqual([])
	})

	it('reports a failed load', async () => {
		const { vm } = load(() => {
			throw new Error('down')
		})
		vm.body = { id: 'raad' }
		await vm.loadBody('raad')
		expect(vm.error).toBe('The declaration status could not be loaded.')
		expect(vm.loading).toBe(false)
	})

	it('maps every person to a name once, and survives a failed name load', async () => {
		const { vm, calls } = load(() => [{ id: 'p1', name: 'Anna' }, { uuid: 'p2', name: 'Bram' }, { id: 'p3' }])
		await vm.loadNames()
		expect(calls).toEqual([{ fn: 'fetchCollection', type: 'person', schema: 'person', params: { _limit: 500 } }])
		expect(vm.names).toEqual({ p1: 'Anna', p2: 'Bram' })

		const failing = load(() => {
			throw new Error('down')
		}).vm
		failing.names = { stale: 'x' }
		await failing.loadNames()
		expect(failing.names).toEqual({})
	})

	it('loads the governance bodies for the selector', async () => {
		const { component, vm, calls } = load(() => [{ id: 'raad', name: 'Gemeenteraad' }, { id: 'rvc' }])
		await vm.loadBodies()
		expect(calls[0].params).toEqual({ _limit: 200 })
		expect(component.computed.bodyOptions.call(vm)).toEqual([
			{ id: 'raad', label: 'Gemeenteraad' },
			{ id: 'rvc', label: 'rvc' },
		])

		const failing = load(() => {
			throw new Error('down')
		}).vm
		await failing.loadBodies()
		expect(failing.bodies).toEqual([])
		expect(failing.error).toBe('The governance bodies could not be loaded.')
	})

	it('labels each status in words, not colour alone', () => {
		const { vm } = load(() => [])
		expect(vm.statusLabel({ status: 'none' })).toBe('No declaration registered')
		expect(vm.statusLabel({ status: 'stale' })).toBe('Review older than 12 months')
		expect(vm.statusLabel({ status: 'current' })).toBe('Up to date')
	})
})

describe('manifest wiring', () => {
	const manifest = JSON.parse(read('src/manifest.json'))
	const fragment = JSON.parse(read('src/manifest.d/interests-and-integrity.json'))
	const registry = read('src/registry.js')

	it('puts the KPI on the dashboard as a self-fetching custom widget', () => {
		const dashboard = manifest.pages.find((p) => p.id === 'Dashboard')
		const widget = dashboard.config.widgets.find((w) => w.id === 'overdue-reviews-kpi')
		expect(widget.type).toBe('custom')
		expect(dashboard.config.layout.some((l) => l.widgetId === 'overdue-reviews-kpi')).toBe(true)
		expect(dashboard.slots['widget-overdue-reviews-kpi']).toBe('OverdueReviewsKpiWidget')
		expect(registry).toMatch(/OverdueReviewsKpiWidget: widget\(OverdueReviewsKpiWidget/)
	})

	it('mounts the compliance panel above the ancillary-positions index', () => {
		const index = fragment.pages.find((p) => p.id === 'AncillaryPositions')
		expect(index.slots).toEqual({ 'below-header': 'AncillaryCompliancePanel' })
		expect(registry).toMatch(/AncillaryCompliancePanel: page\(AncillaryCompliancePanel\)/)
	})

	it('the KPI deep-link names an existing page', () => {
		expect(fragment.pages.some((p) => p.id === overdueReviewsRoute().name)).toBe(true)
	})
})
