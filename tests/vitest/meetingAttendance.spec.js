// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Attendance is recorded per meeting (meeting-attendance-per-meeting, pla-09).
//
// @spec openspec/changes/meeting-attendance-per-meeting/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
// @e2e tests/e2e/meeting-attendance-per-meeting.spec.ts

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
	ATTENDANCE_STATUSES,
	attendancePayload,
	attendanceRows,
	everyonePresent,
} from '../../src/utils/meetingAttendance.js'

const here = dirname(fileURLToPath(import.meta.url))
const read = (path) => readFileSync(resolve(here, '../../', path), 'utf8')

const members = ['anna', 'bert', 'cees', 'dirk', 'eva'].map((n) => ({
	id: `P-${n}`,
	displayName: n,
}))
const records = [
	{ id: 'a-1', meeting: 'm-07', participant: 'P-anna', status: 'present' },
	{ id: 'a-2', meeting: 'm-14', participant: 'P-bert', status: 'present' },
]

describe('attendanceRows', () => {
	it("shows this meeting's status and ignores other meetings", () => {
		const rows = attendanceRows(members, records, 'm-14')
		expect(rows).toHaveLength(5)
		expect(rows.find((r) => r.id === 'P-anna').attendance).toBe('')
		expect(rows.find((r) => r.id === 'P-bert').attendance).toBe('present')
	})

	it('lists a guest who has a record for this meeting', () => {
		const rows = attendanceRows(
			members,
			[
				...records,
				{ meeting: 'm-14', participant: 'P-gast', status: 'present' },
			],
			'm-14',
			[{ id: 'P-gast', displayName: 'Gast' }],
		)
		expect(rows.map((r) => r.displayName)).toContain('Gast')
	})
})

describe('attendancePayload', () => {
	it('marks Anna excused on 14 October with a new record and leaves 7 October alone', () => {
		const rows = attendanceRows(members, records, 'm-14')
		const anna = rows.find((r) => r.id === 'P-anna')
		const payload = attendancePayload(anna.attendanceRecord, {
			meetingId: 'm-14',
			participantId: 'P-anna',
			status: 'excused',
			now: '2026-10-14T19:00:00Z',
		})
		expect(payload).toEqual({
			meeting: 'm-14',
			participant: 'P-anna',
			status: 'excused',
		})
		expect(payload.id).toBeUndefined()
		expect(records[0]).toEqual({
			id: 'a-1',
			meeting: 'm-07',
			participant: 'P-anna',
			status: 'present',
		})
	})

	it('updates the existing record of this meeting and stamps the arrival once', () => {
		const payload = attendancePayload(
			{ id: 'a-2', meeting: 'm-14', participant: 'P-bert', status: 'absent' },
			{
				meetingId: 'm-14',
				participantId: 'P-bert',
				status: 'present',
				now: '2026-10-14T19:32:00Z',
			},
		)
		expect(payload).toMatchObject({
			id: 'a-2',
			status: 'present',
			arrivedAt: '2026-10-14T19:32:00Z',
		})
		const again = attendancePayload(payload, {
			meetingId: 'm-14',
			participantId: 'P-bert',
			status: 'present',
			now: 'later',
		})
		expect(again.arrivedAt).toBe('2026-10-14T19:32:00Z')
	})

	it('refuses a status the schema does not know', () => {
		expect(() =>
			attendancePayload(null, {
				meetingId: 'm',
				participantId: 'p',
				status: 'late',
			}),
		).toThrow()
		expect(ATTENDANCE_STATUSES).toEqual([
			'present',
			'absent',
			'excused',
			'proxy',
		])
	})
})

describe('everyonePresent', () => {
	it('writes a present record for every row not yet present', () => {
		const rows = attendanceRows(members, records, 'm-14')
		const payloads = everyonePresent(rows, 'm-14', '2026-10-14T19:00:00Z')
		expect(payloads).toHaveLength(4)
		expect(
			payloads.every((p) => p.meeting === 'm-14' && p.status === 'present'),
		).toBe(true)
	})
})

describe('the meeting Participants widget', () => {
	const source = read('src/components/tabs/MeetingParticipantsTab.vue')

	it('records attendance through meeting-attendance, not an undeclared meetings array', () => {
		expect(source).toContain('meetingAttendance.js')
		expect(source).toContain("'meeting-attendance'")
		expect(source).not.toMatch(/meetings\.push|\.\.\.participant, meetings/)
		expect(source).toContain('meeting-participants-everyone-present')
	})
})

describe('the meetings report', () => {
	const manifest = JSON.parse(read('src/manifest.json'))

	it('counts absences and attendance per meeting record', () => {
		const find = (id) => {
			let hit = null
			const walk = (node) => {
				if (hit || !node || typeof node !== 'object') return
				if (node.id === id && (node.content || node.type)) {
					hit = node
					return
				}
				Object.values(node).forEach(walk)
			}
			walk(manifest)
			return hit
		}
		const absent = find('meet-absent')
		expect(absent.content.source.schema).toBe('meeting-attendance')
		expect(absent.content.source.filter).toEqual({ status: 'absent' })
		const donut = find('meet-by-attendance')
		expect(donut.content.dataSource.schema).toBe('meeting-attendance')
		expect(donut.content.dataSource.aggregate.groupBy).toBe('status')
	})
})
