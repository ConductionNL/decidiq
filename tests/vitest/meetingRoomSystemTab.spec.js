/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Room system widget on the meeting page (live-room-conference-system-link
 * task 5): it reads the meeting's messages through the staff-guarded route,
 * says so while no room system is connected, and offers Apply again on a
 * rejected message only. This repo's vitest cannot mount a `.vue` file, so the
 * wiring is asserted against the sources.
 *
 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-006-a-message-that-cannot-be-applied-is-kept-and-can-be-applied-again
 */

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

function source(path) {
	return readFileSync(fileURLToPath(new URL(`../../${path}`, import.meta.url)), 'utf8')
}

const manifest = JSON.parse(source('src/manifest.json'))
const detail = manifest.pages.find((p) => p.id === 'MeetingDetail')
const tab = source('src/components/tabs/MeetingRoomSystemTab.vue')

describe('the Room system widget', () => {
	it('is mounted on the meeting page, laid out and slotted', () => {
		expect(detail.config.widgets.find((w) => w.id === 'meeting-room-system')).toMatchObject({
			type: 'custom',
			component: 'MeetingRoomSystemTab',
			title: 'Room system',
		})
		expect(detail.config.layout.some((l) => l.widgetId === 'meeting-room-system')).toBe(true)
		expect(detail.slots['widget-meeting-room-system']).toBe('MeetingRoomSystemTab')
		expect(source('src/registry.js')).toMatch(/MeetingRoomSystemTab: page\(MeetingRoomSystemTab\)/)
	})

	it('reads the meeting through the room-system route and posts Apply again to the message', () => {
		expect(tab).toContain('apiUrl(`/meetings/${this.objectId}/room-system`)')
		expect(tab).toContain('apiUrl(`/room-system-messages/${item.id}/apply`)')
		expect(source('appinfo/routes.php')).toMatch(/'roomSystem#meeting',\s+'url' => '\/api\/meetings\/\{id\}\/room-system',\s+'verb' => 'GET'/)
		expect(source('appinfo/routes.php')).toMatch(/'roomSystem#apply',\s+'url' => '\/api\/room-system-messages\/\{id\}\/apply',\s+'verb' => 'POST'/)
	})

	it('says No room system is connected, and offers Apply again on a rejected message only', () => {
		expect(tab).toMatch(/v-else-if="!connected"[\s\S]*?t\('decidiq', 'No room system is connected'\)/)
		expect(tab).toMatch(/v-if="item\.status === 'rejected'"[\s\S]*?t\('decidiq', 'Apply again'\)/)
	})

	it('has Dutch words for what it shows', () => {
		const nl = JSON.parse(source('l10n/nl.json')).translations
		expect(nl['No room system is connected']).toBe('Er is geen zaalsysteem gekoppeld')
		expect(nl['Apply again']).toBe('Opnieuw verwerken')
		expect(nl['Seat {seat}']).toBe('Zetel {seat}')
	})
})
