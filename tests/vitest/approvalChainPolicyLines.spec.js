/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The approval chain tab names, per step, who a rule asks, what silence means
 * and when the substitute is asked as well.
 *
 * @spec openspec/changes/approval-routes-resolve-a-manager-and-declare-silence/specs/approval-routes/spec.md
 */

import { describe, expect, it, vi } from 'vitest'
import { stagePolicyLines } from '../../src/integrations/approvalChainLink.js'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
}))

describe('stagePolicyLines', () => {
	it('says plainly that a step approves on silence', () => {
		expect(stagePolicyLines({ onSilence: 'approve' })).toEqual([
			'Approves on its own if nobody answers by the deadline',
		])
	})

	it('says nothing for a step that holds, the default', () => {
		expect(stagePolicyLines({ onSilence: 'hold' })).toEqual([])
		expect(stagePolicyLines({})).toEqual([])
		expect(stagePolicyLines(null)).toEqual([])
	})

	it('names the actor rule, the silence and the substitute ask point in that order', () => {
		expect(
			stagePolicyLines({
				actorRule: 'manager-of-subject-owner',
				onSilence: 'escalate',
				askSubstituteAfter: 0.5,
			}),
		).toEqual([
			'Asked of the manager of whoever owns the subject',
			'Goes up a level if nobody answers by the deadline',
			'The substitute is also asked after 50% of the time',
		])
	})

	it('ignores a substitute ask point outside the window', () => {
		expect(stagePolicyLines({ askSubstituteAfter: 0 })).toEqual([])
		expect(stagePolicyLines({ askSubstituteAfter: 1.5 })).toEqual([])
		expect(stagePolicyLines({ askSubstituteAfter: 'soon' })).toEqual([])
	})

	it('ignores an unknown rule or silence value', () => {
		expect(stagePolicyLines({ actorRule: 'boss', onSilence: 'shrug' })).toEqual(
			[],
		)
	})
})
