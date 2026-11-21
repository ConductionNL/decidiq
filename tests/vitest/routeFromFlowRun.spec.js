// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * @spec openspec/changes/approval-routes-on-flow-user-tasks/specs/approval-routes/spec.md#requirement-req-arf-007-the-route-tab-reads-the-flow-run
 */
import { describe, expect, it } from 'vitest'
import { compiledSteps, routeFromFlowRun } from '../../src/utils/routeFromFlowRun.js'

const route = {
	id: 'route-1',
	steps: [
		{ order: 1, stageType: 'advisory', actorType: 'role', actor: 'team-mobiliteit', label: 'Ambtelijk advies' },
		{ order: 2, stageType: 'decisive', actorType: 'body', actor: 'college', label: 'College van B&W' },
		{ order: 3, stageType: 'advisory', actorType: 'body', actor: 'commissie-ruimte', label: 'Commissie Ruimte' },
		{ order: 4, stageType: 'endorsement', actorType: 'person', actor: 'wouter', label: 'Ondertekening' },
		{ order: 5, stageType: 'decisive', actorType: 'body', actor: 'raad', label: 'Gemeenteraad' },
		{ order: 6, stageType: 'ratifying', actorType: 'person', actor: 'griffier', label: 'Bekrachtiging' },
	],
}

const task = (node, state, extra = {}) => ({ uuid: `t-${node}-${extra.created || '1'}`, state, metadata: { flowNode: node }, created: extra.created || '2026-10-01T09:00:00Z', ...extra })

describe('compiledSteps', () => {
	it('gives each step the node id the compiler gives it, parallel steps by slot', () => {
		const steps = compiledSteps({ steps: [{ order: 2, label: 'b' }, { order: 1, label: 'a' }, { order: 2, label: 'c' }] })
		expect(steps.map((s) => s.node)).toEqual(['step-1-1', 'step-2-1', 'step-2-2'])
		expect(steps.map((s) => s.label)).toEqual(['a', 'b', 'c'])
	})
})

describe('routeFromFlowRun', () => {
	const tasks = [
		task('step-1-1', 'completed', { outcome: 'advised', completedAt: '2026-10-02T10:00:00Z', completedBy: 'sanne' }),
		task('step-2-1', 'completed', { outcome: 'approved', completedAt: '2026-10-04T10:00:00Z', completedBy: 'secretaris' }),
		task('step-3-1', 'completed', { outcome: 'advised', completedAt: '2026-10-06T10:00:00Z', completedBy: 'griffie' }),
		task('step-4-1', 'active'),
	]

	it('scenario: the route tab on a running route shows 3 of 6 decided, step 4 current and active, 5 and 6 pending', () => {
		const { stages, current, decided } = routeFromFlowRun(route, tasks)

		expect(decided).toBe(3)
		expect(stages).toHaveLength(6)
		expect(current).toBe('step-4-1')
		expect(stages.map((s) => s.status)).toEqual(['decided', 'decided', 'decided', 'active', 'pending', 'pending'])
		expect(stages[0]).toMatchObject({ sequence: 1, label: 'Ambtelijk advies', outcome: 'advised', decidedAt: '2026-10-02T10:00:00Z', method: 'advice' })
		expect(stages[1]).toMatchObject({ method: 'vote', decisionMakerType: 'body', outcome: 'approved' })
		expect(stages[3]).toMatchObject({ outcome: '', decidedAt: '', method: 'signature' })
	})

	it('scenario: Lisa signs for Wouter; the row names her as substitute', () => {
		const signed = [...tasks.slice(0, 3), task('step-4-1', 'completed', { outcome: 'approved', completedBy: 'lisa', completedAt: '2026-10-07T12:00:00Z' })]
		const row = routeFromFlowRun(route, signed).stages[3]

		expect(row.status).toBe('decided')
		expect(row.substitute).toBe('lisa')
		expect(routeFromFlowRun(route, tasks.slice(0, 3).concat(task('step-4-1', 'completed', { outcome: 'approved', completedBy: 'wouter' }))).stages[3].substitute).toBe('')
	})

	it('scenario: a return re-opens step 1 and keeps its first outcome in the history', () => {
		const returned = [
			task('step-1-1', 'completed', { outcome: 'advised', completedAt: '2026-10-02T10:00:00Z', completedBy: 'sanne', created: '2026-10-01T09:00:00Z' }),
			task('step-3-1', 'completed', { outcome: 'returned', comment: 'kostenraming ontbreekt', completedAt: '2026-10-06T10:00:00Z' }),
			task('step-1-1', 'active', { created: '2026-10-06T10:01:00Z' }),
		]
		const { stages, current } = routeFromFlowRun(route, returned)

		expect(current).toBe('step-1-1')
		expect(stages[0].status).toBe('active')
		expect(stages[0].history).toEqual([{ outcome: 'advised', decidedAt: '2026-10-02T10:00:00Z', by: 'sanne', comment: '', lapsed: false }])
		expect(stages[2]).toMatchObject({ outcome: 'returned' })
	})

	it('scenario: a lapsed advice term shows as a lapse', () => {
		const lapse = [task('step-1-1', 'completed', { outcome: 'advised', onTimeout: 'approve', completedAt: '2026-10-15T00:00:00Z' })]
		expect(routeFromFlowRun(route, lapse).stages[0].lapsed).toBe(true)
		expect(routeFromFlowRun(route, tasks).stages[0].lapsed).toBe(false)
	})

	it('ignores tasks of other nodes and answers an empty route with no rows', () => {
		expect(routeFromFlowRun(route, [task('something-else', 'active')]).current).toBeNull()
		expect(routeFromFlowRun({}, tasks)).toEqual({ stages: [], current: null, decided: 0 })
	})
})
