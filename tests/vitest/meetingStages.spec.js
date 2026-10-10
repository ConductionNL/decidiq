// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The stage buttons on the meeting page (meeting-stage-buttons-and-cost,
// REQ-MSB-001 and REQ-MSB-002).
//
// @spec openspec/specs/meeting-workflow/spec.md#requirement-req-msb-001-the-chair-moves-a-meeting-through-its-stages

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	lifecyclePath,
	readStageAnswer,
	recordedCost,
	transitionsPath,
} from '../../src/utils/meetingStages.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

describe('stage paths', () => {
	it('asks for the steps and applies one per meeting', () => {
		expect(transitionsPath('m-1')).toBe(
			'/apps/decidiq/api/meetings/m-1/transitions',
		)
		expect(lifecyclePath('m-1')).toBe('/apps/decidiq/api/meetings/m-1/lifecycle')
	})
})

describe('readStageAnswer', () => {
	it('keeps the offered steps in button order and drops unknown ones', () => {
		expect(
			readStageAnswer({
				lifecycle: 'scheduled',
				actions: ['close', 'open', 'explode'],
			}),
		).toEqual({ lifecycle: 'scheduled', actions: ['open', 'close'] })
	})

	it('offers no step to a member, or without an answer', () => {
		expect(
			readStageAnswer({ lifecycle: 'opened', actions: [] }).actions,
		).toEqual([])
		expect(readStageAnswer(null)).toEqual({ lifecycle: '', actions: [] })
		expect(readStageAnswer({ actions: 'open' }).actions).toEqual([])
	})
})

describe('recordedCost', () => {
	it('reads the cost the server recorded on close', () => {
		expect(recordedCost({ meetingCost: 1000 })).toBe(1000)
		expect(recordedCost({ meetingCost: '675.5' })).toBe(675.5)
		expect(recordedCost({ meetingCost: null })).toBeNull()
		expect(recordedCost({})).toBeNull()
		expect(recordedCost(null)).toBeNull()
	})
})

describe('MeetingStageTab', () => {
	const source = read('src/components/tabs/MeetingStageTab.vue')

	it('takes its buttons from the server answer and posts through the guarded lifecycle', () => {
		expect(source).toMatch(/transitionsPath\(this\.objectId\)/)
		expect(source).toMatch(/lifecyclePath\(this\.objectId\)/)
		expect(source).toMatch(/v-for="action in stage\.actions"/)
		expect(source).toMatch(/method: 'POST'/)
	})

	it('shows the cost recorded on close', () => {
		expect(source).toMatch(/recordedCost\(payload\?\.meeting\)/)
		expect(source).toMatch(/data-testid="meeting-stage-cost"/)
	})
})

describe('manifest', () => {
	const manifest = JSON.parse(read('src/manifest.json'))
	const page = (id) => manifest.pages.find((p) => p.id === id)

	it('puts the stage widget on the meeting page', () => {
		const detail = page('MeetingDetail')
		const widget = detail.config.widgets.find((w) => w.id === 'meeting-stage')
		expect(widget).toMatchObject({
			type: 'custom',
			component: 'MeetingStageTab',
		})
		expect(
			detail.config.layout.some((l) => l.widgetId === 'meeting-stage'),
		).toBe(true)
		expect(detail.slots['widget-meeting-stage']).toBe('MeetingStageTab')
		expect(read('src/registry.js')).toMatch(
			/MeetingStageTab: page\(MeetingStageTab\)/,
		)
	})

	it('keeps the stage off the create and edit forms', () => {
		expect(page('Meetings').config.excludeFields).toContain('lifecycle')
	})
})
