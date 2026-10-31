// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The chair or secretary publishes the agenda from the meeting page
// (agenda-publish-and-invite-members, REQ-APIM-001).
//
// @spec openspec/specs/agenda-publication/spec.md#requirement-req-apim-001-publishing-the-agenda-invites-the-members

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { publishAgendaPath, publishedOn } from '../../src/utils/agendaPublication.js'

const here = dirname(fileURLToPath(import.meta.url))

describe('publishAgendaPath', () => {
	it('posts to the publish endpoint of the meeting', () => {
		expect(publishAgendaPath('m-1')).toBe(
			'/apps/decidiq/api/agendas/m-1/publish',
		)
	})
})

describe('publishedOn', () => {
	it('shows when the agenda was published, and nothing before', () => {
		const local = new Date(2026, 9, 1, 9, 5)
		expect(publishedOn({ agendaPublishedAt: local.toISOString() })).toBe(
			'2026-10-01 09:05',
		)
		expect(publishedOn({})).toBe('')
		expect(publishedOn({ agendaPublishedAt: 'not a date' })).toBe('')
		expect(publishedOn(null)).toBe('')
	})
})

describe('MeetingAgendaTab', () => {
	const source = readFileSync(
		resolve(here, '../../src/components/tabs/MeetingAgendaTab.vue'),
		'utf8',
	)

	it('offers Publish agenda to the chair and secretary only', () => {
		const button = source.slice(
			source.indexOf('data-testid="agenda-publish"') - 200,
			source.indexOf('data-testid="agenda-publish"'),
		)
		expect(button).toMatch(/v-if="canManage"/)
		expect(source).toMatch(/publishAgendaPath\(this\.objectId\)/)
		expect(source).toMatch(/method: 'POST'/)
	})

	it('shows the published date after publishing', () => {
		expect(source).toMatch(/data-testid="agenda-published-on"/)
		expect(source).toMatch(/publishedOn\(this\.meeting\)/)
	})
})
