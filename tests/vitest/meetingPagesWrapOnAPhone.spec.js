/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The meeting index's view actions and the ad hoc meeting form wrap instead of
 * running off a 360-pixel screen (found by tests/e2e/phone-width.spec.ts on a
 * live instance, 25 Nov 2026).
 *
 * @spec openspec/changes/platform-baseline-on-nextcloud/specs/platform-baseline/spec.md#requirement-req-plb-008-every-page-works-at-phone-width
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'

/**
 * The declarations of one rule in a component's style block.
 *
 * @param {string} file     The component, relative to src/.
 * @param {string} selector The rule's selector.
 * @return {string} The rule's body, or '' when it is absent.
 */
function rule(file, selector) {
	const sfc = fs.readFileSync(path.join(__dirname, '../../src', file), 'utf8')
	const at = sfc.indexOf(selector + ' {')
	return at === -1 ? '' : sfc.slice(at, sfc.indexOf('}', at))
}

describe('meeting pages at phone width', () => {
	it('lets the Table / Calendar toggle and New ad hoc meeting wrap within the page', () => {
		const actions = rule('views/meetings/MeetingViewToggle.vue', '.meeting-view-actions')
		expect(actions).toContain('flex-wrap: wrap')
		expect(actions).toContain('max-width: 100%')
		expect(rule('views/meetings/MeetingViewToggle.vue', '.meeting-view-toggle')).toContain('flex-wrap: wrap')
	})

	it('lets the calendar toolbar wrap, so the view actions take a line of their own', () => {
		expect(rule('views/meetings/MeetingCalendarView.vue', '.meeting-calendar__toolbar')).toContain('flex-wrap: wrap')
	})

	it('puts the two date fields of an ad hoc meeting under each other when they do not fit', () => {
		expect(rule('views/meetings/AdhocMeetingPage.vue', '.adhoc-meeting__when')).toContain('flex-wrap: wrap')
		expect(rule('views/meetings/AdhocMeetingPage.vue', '.adhoc-meeting__fields')).toContain('minmax(min(260px, 100%), 1fr)')
	})
})
