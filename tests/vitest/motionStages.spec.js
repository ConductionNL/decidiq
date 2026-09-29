// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Stages and themes on motions (motions-stages-and-themes, REQ-MST-001 and
// REQ-MST-002).
//
// @spec openspec/specs/motion-status-management/spec.md#requirement-req-mst-001-move-a-motion-through-its-stages-on-its-page

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	actionKey,
	readMotionStageAnswer,
	themeNames,
	transitionPath,
	transitionsPath,
	withThemeVocabulary,
} from '../../src/utils/motionStages.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

describe('motion stage paths', () => {
	it('asks for the steps and posts one to the motion route', () => {
		expect(transitionsPath('m-12')).toBe(
			'/apps/decidiq/api/motions/m-12/transitions',
		)
		expect(transitionPath('m-12')).toBe(
			'/apps/decidiq/api/motions/m-12/transition',
		)
	})
})

describe('readMotionStageAnswer', () => {
	it('keeps the offered steps in lifecycle order with their result', () => {
		expect(
			readMotionStageAnswer({
				lifecycle: 'voting',
				outcome: null,
				actions: [
					{ to: 'withdrawn' },
					{ to: 'decided', outcome: 'rejected' },
					{ to: 'decided', outcome: 'adopted' },
					{ to: 'explode' },
				],
			}),
		).toEqual({
			lifecycle: 'voting',
			outcome: '',
			actions: [
				{ to: 'decided', outcome: 'adopted' },
				{ to: 'decided', outcome: 'rejected' },
				{ to: 'withdrawn' },
			],
		})
	})

	it('offers a member nothing, and nothing without an answer', () => {
		expect(readMotionStageAnswer(null)).toEqual({
			lifecycle: '',
			outcome: '',
			actions: [],
		})
		expect(readMotionStageAnswer({ actions: 'withdrawn' }).actions).toEqual([])
	})

	it('names each step by target and result', () => {
		expect(actionKey({ to: 'withdrawn' })).toBe('withdrawn')
		expect(actionKey({ to: 'decided', outcome: 'adopted' })).toBe(
			'decided-adopted',
		)
	})
})

describe('themes', () => {
	it('reads the configured theme names, sorted and without blanks', () => {
		expect(
			themeNames([{ name: 'Housing' }, { name: '' }, { name: 'Climate' }, {}]),
		).toEqual(['Climate', 'Housing'])
		expect(themeNames(null)).toEqual([])
	})

	it('offers the configured themes as choices on the form', () => {
		const schema = {
			properties: { themes: { type: 'array', items: { type: 'string' } } },
		}
		expect(
			withThemeVocabulary(schema, ['Housing']).properties.themes.items.enum,
		).toEqual(['Housing'])
		// Without configured themes the field stays free text, never an empty picker.
		expect(withThemeVocabulary(schema, [])).toBe(schema)
		expect(withThemeVocabulary({ properties: {} }, ['Housing'])).toEqual({
			properties: {},
		})
	})
})

describe('MotionStageTab', () => {
	const source = read('src/components/tabs/MotionStageTab.vue')

	it('takes its buttons from the server and posts to the motion route', () => {
		expect(source).toMatch(/transitionsPath\(this\.objectId\)/)
		expect(source).toMatch(/transitionPath\(this\.objectId\)/)
		expect(source).toMatch(/v-for="action in stage\.actions"/)
		expect(source).toMatch(/newState: action\.to/)
	})
})

describe('manifest', () => {
	const manifest = JSON.parse(read('src/manifest.json'))
	const themesFragment = JSON.parse(read('src/manifest.d/motion-themes.json'))
	const page = (id) => manifest.pages.find((p) => p.id === id)

	it('puts the stage widget on the motion page', () => {
		const detail = page('MotionDetail')
		expect(
			detail.config.widgets.find((w) => w.id === 'motion-stage'),
		).toMatchObject({ type: 'custom', component: 'MotionStageTab' })
		expect(detail.config.layout.some((l) => l.widgetId === 'motion-stage')).toBe(true)
		expect(detail.slots['widget-motion-stage']).toBe('MotionStageTab')
		expect(read('src/registry.js')).toMatch(/MotionStageTab: page\(MotionStageTab\)/)
	})

	it('shows stage, result and themes on the motions list', () => {
		const columns = page('Motions').config.columns
		expect(columns).toEqual(
			expect.arrayContaining(['lifecycle', 'outcome', 'themes']),
		)
		expect(page('MotionDetail').config.widgets[0].content.include).toContain('themes')
	})

	it('lets an operator keep the list of themes', () => {
		const themes = themesFragment.pages.find((p) => p.id === 'Themes')
		expect(themes.config).toMatchObject({ register: 'decidiq', schema: 'theme' })
		expect(themesFragment.menu.map((m) => m.id)).toContain('Themes')
		expect(JSON.parse(read('src/menu-layout.json')).settingsSection).toContain('Themes')
	})

	it('offers the themes on the motion form', () => {
		expect(read('src/dialogs/DecisionFormDialog.vue')).toMatch(/withThemeVocabulary\(/)
	})
})
