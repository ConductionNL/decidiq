// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * meeting-pack-board-book (task 8): the Vergaderbundel section shows the
 * versions newest first, the compile action only to the chair or secretary,
 * asks for a change note from version 2, and shows "outdated" only when a
 * pack exists and the agenda moved since.
 *
 * @spec openspec/changes/meeting-pack-board-book/specs/meeting-pack-board-book/spec.md#requirement-req-008-pack-section-on-the-meeting-detail-page
 */
import { describe, expect, it } from 'vitest'
import {
	canCompile,
	downloadUrl,
	isCompiling,
	isOutdated,
	needsChangeNote,
	packsUrl,
	statusUrl,
	versionsOf,
} from '../../src/utils/meetingPack.js'

describe('meeting pack section', () => {
	it('lists only this meeting\'s versions, newest first', () => {
		const rows = [
			{ id: 'a', version: 1, meeting: 'm1' },
			{ id: 'b', version: 3, meeting: { id: 'm1' } },
			{ id: 'c', version: 2, meeting: 'other' },
			{ id: 'd', version: 2, meeting: 'm1' },
		]
		expect(versionsOf(rows, 'm1').map((row) => row.id)).toEqual(['b', 'd', 'a'])
		expect(versionsOf(null, 'm1')).toEqual([])
	})

	it('offers compiling only to the chair, the secretary or an administrator', () => {
		expect(canCompile({ chair: true })).toBe(true)
		expect(canCompile({ secretary: true })).toBe(true)
		expect(canCompile({ admin: true })).toBe(true)
		expect(canCompile({ chair: false, secretary: false, admin: false })).toBe(false)
		expect(canCompile(null)).toBe(false)
	})

	it('asks for a change note from the second version on', () => {
		expect(needsChangeNote([])).toBe(false)
		expect(needsChangeNote([{ version: 1 }])).toBe(true)
	})

	it('shows outdated only for an existing pack whose agenda moved', () => {
		expect(isOutdated({ latestVersion: 2, outdated: true })).toBe(true)
		expect(isOutdated({ latestVersion: 2, outdated: false })).toBe(false)
		expect(isOutdated({ latestVersion: 0, outdated: true })).toBe(false)
		expect(isOutdated(null)).toBe(false)
	})

	it('is compiling while queued or running', () => {
		expect(isCompiling({ state: 'queued' })).toBe(true)
		expect(isCompiling({ state: 'running' })).toBe(true)
		expect(isCompiling({ state: 'done' })).toBe(false)
	})

	it('reads the packs and the status from their endpoints, and downloads by file name', () => {
		expect(packsUrl('m 1')).toContain('/apps/openregister/api/objects/decidiq/meeting-pack?meeting=m%201')
		expect(statusUrl('m1')).toMatch(/\/apps\/decidiq\/api\/meetings\/m1\/board-book\/status$/)
		expect(downloadUrl('Vergaderingen/Raad/Vergaderbundel/Vergaderbundel Raad v2.pdf', 'jan'))
			.toMatch(/\/files\/jan\/Vergaderbundel%20Raad%20v2\.pdf$/)
		expect(downloadUrl('', 'jan')).toBe('')
		expect(downloadUrl('a.pdf', '')).toBe('')
	})
})
