// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// noticeRules — pure frontend mirror of
// BoardMeetingService::getNoticeDeadlineInfo(): statutory convocation
// deadline arithmetic (BW 2:225 / BW 2:38) used by BoardMeetingDetail to
// warn BEFORE the secretary sends a notice. The server computation is
// authoritative; this mirror only drives the pre-send UI hint.
//
// @spec openspec/specs/meeting-management/spec.md

/**
 * Default statutory notice period in days (mirror of
 * BoardMeetingService::DEFAULT_NOTICE_PERIOD_DAYS).
 */
export const DEFAULT_NOTICE_PERIOD_DAYS = 15

/**
 * Warn when sending within this many days of the deadline (mirror of
 * BoardMeetingService::DEADLINE_WARNING_DAYS).
 */
export const DEADLINE_WARNING_DAYS = 3

/**
 * Compute the statutory notice deadline + warning level for a board meeting.
 *
 * @param {object} meeting Board-meeting payload (`meetingDate`, optional `noticePeriodDays`).
 * @param {Date} [now] Clock injection for tests; defaults to the current time.
 *
 * @return {{deadline: string|null, daysUntilDeadline: number|null, level: string}}
 *         `level` is one of 'ok' | 'warning' (within 3 days) | 'overdue' (deadline passed)
 *         | 'unknown' (no parseable meeting date).
 *
 * @spec openspec/specs/meeting-management/spec.md
 */
export function getNoticeDeadlineInfo(meeting, now = new Date()) {
	const raw = String(meeting?.meetingDate || meeting?.meetingStart || '').slice(0, 10)
	const meetingDate = new Date(`${raw}T00:00:00Z`)
	if (!raw || Number.isNaN(meetingDate.getTime())) {
		return { deadline: null, daysUntilDeadline: null, level: 'unknown' }
	}

	let periodDays = Number(meeting?.noticePeriodDays ?? DEFAULT_NOTICE_PERIOD_DAYS)
	if (!Number.isFinite(periodDays) || periodDays < 0) {
		periodDays = DEFAULT_NOTICE_PERIOD_DAYS
	}

	const deadline = new Date(meetingDate.getTime() - periodDays * 86400000)
	const today = new Date(`${now.toISOString().slice(0, 10)}T00:00:00Z`)
	const daysUntilDeadline = Math.round((deadline.getTime() - today.getTime()) / 86400000)

	let level = 'ok'
	if (daysUntilDeadline < 0) {
		level = 'overdue'
	} else if (daysUntilDeadline <= DEADLINE_WARNING_DAYS) {
		level = 'warning'
	}

	return {
		deadline: deadline.toISOString().slice(0, 10),
		daysUntilDeadline,
		level,
	}
}
