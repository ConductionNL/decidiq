/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin section Forward motions (board DcBeheer, Moties doorsturen): the
 * switch that makes a forwarded motion wait for the chair of the receiving
 * body, read from and written to the app settings.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	forwardingPayload,
	forwardingSetting,
} from '../../src/utils/motionForwarding.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')

describe('the Forward motions setting', () => {
	it('is off until the setting says otherwise', () => {
		expect(forwardingSetting({})).toEqual({ requiresApproval: false })
		expect(
			forwardingSetting({ motion_forwarding_requires_approval: 'false' }),
		).toEqual({ requiresApproval: false })
		expect(
			forwardingSetting({ motion_forwarding_requires_approval: 'true' }),
		).toEqual({ requiresApproval: true })
		expect(
			forwardingSetting({ motion_forwarding_requires_approval: '1' }),
		).toEqual({ requiresApproval: true })
	})

	it('writes the switch as the string the server reads', () => {
		expect(forwardingPayload(true)).toEqual({
			motion_forwarding_requires_approval: 'true',
		})
		expect(forwardingPayload(false)).toEqual({
			motion_forwarding_requires_approval: 'false',
		})
		expect(read('lib', 'Service', 'SettingsService.php')).toContain(
			"'motion_forwarding_requires_approval',",
		)
	})

	it('is a section of the admin page, after the organisation settings', () => {
		const root = read('src', 'views', 'settings', 'AdminRoot.vue')
		expect(root.indexOf('<Settings v-if')).toBeLessThan(
			root.indexOf('<MotionForwardingSettings'),
		)
	})
})
