// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The open action items tile counts action items only: a commitment is its own
 * register and never enters it (toezeggingen-register, action-item KPI unaffected).
 *
 * @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-003-toezegging-is-not-an-action-item-and-not-motion-execution-tracking
 */
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const read = (path) => readFileSync(resolve(root, path), 'utf8')

describe('the open action items tile', () => {
	it('reads the action-item collection and nothing else', () => {
		const widget = read('src/views/dashboard/widgets/OverdueActionsKpiWidget.vue')
		expect(widget).toContain("import { getActionItems } from '../../../services/dashboardData.js'")
		expect(widget).not.toMatch(/governance-commitment|commitment/i)

		const data = read('src/services/dashboardData.js')
		const fn = data.slice(data.indexOf('export function getActionItems'), data.indexOf('export function getMotions'))
		expect(fn).toContain("fetchCollection('action-item', params)")
		expect(fn).not.toContain('commitment')
	})
})
