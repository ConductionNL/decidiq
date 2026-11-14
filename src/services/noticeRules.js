// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// noticeRules — pure frontend mirror of the meeting notice-deadline
// computation: statutory convocation deadline arithmetic (BW 2:225 / BW 2:38)
// used by the meeting detail view to warn BEFORE the secretary sends a notice.
// The server computation is authoritative; this mirror only drives the
// pre-send UI hint.
//
// @spec openspec/specs/meeting-management/spec.md

/**
 * Default statutory notice period in days (meeting convocation notice).
 */
export const DEFAULT_NOTICE_PERIOD_DAYS = 15

/**
 * Warn when sending within this many days of the deadline.
 */
export const DEADLINE_WARNING_DAYS = 3

/**
 * Compute the statutory notice deadline + warning level for a meeting.
 *
 * @param {object} meeting Meeting payload (`meetingDate`, optional `noticePeriodDays`).
 * @param {Date} [now] Clock injection for tests; defaults to the current time.
 *
 * @return {{deadline: string|null, daysUntilDeadline: number|null, level: string}}
 *         `level` is one of 'ok' | 'warning' (within 3 days) | 'overdue' (deadline passed)
 *         | 'shortened' (an extraordinary meeting convened at a recorded shortened notice:
 *         a deliberate deviation, not an error) | 'unknown' (no parseable meeting date).
 *
 * @spec openspec/specs/meeting-management/spec.md
 * @spec openspec/changes/urgent-decision-procedure/specs/meeting-management/spec.md#requirement-shortened-notice-deviation-recording-for-emergency-convocations
 */
export function getNoticeDeadlineInfo(meeting, now = new Date()) {
	const meetingDate = meetingDay(meeting)
	if (meetingDate === null) {
		return { deadline: null, daysUntilDeadline: null, level: 'unknown' }
	}

	const deadline = new Date(
		meetingDate.getTime() - noticePeriodDays(meeting) * 86400000,
	)
	const today = new Date(`${now.toISOString().slice(0, 10)}T00:00:00Z`)
	const daysUntilDeadline = Math.round(
		(deadline.getTime() - today.getTime()) / 86400000,
	)

	return {
		deadline: deadline.toISOString().slice(0, 10),
		daysUntilDeadline,
		level: isRecordedShortenedNotice(meeting) ? 'shortened' : deadlineLevel(daysUntilDeadline),
	}
}

/**
 * Whether the meeting records a deliberate shortened notice. Only an
 * `extraordinary` meeting can: a regular meeting keeps the overdue warning.
 *
 * @param {object} meeting Meeting payload.
 *
 * @return {boolean} True for a recorded deviation.
 */
export function isRecordedShortenedNotice(meeting) {
	return meeting?.meetingType === 'extraordinary' && meeting?.shortenedNotice === true
}

/**
 * The meeting's day at UTC midnight, from `meetingDate` or `meetingStart`.
 *
 * @param {object} meeting Meeting payload.
 *
 * @return {Date|null} The day, null when there is no parseable date.
 */
function meetingDay(meeting) {
	const raw = String(meeting?.meetingDate || meeting?.meetingStart || '').slice(
		0,
		10,
	)
	const day = new Date(`${raw}T00:00:00Z`)
	return !raw || Number.isNaN(day.getTime()) ? null : day
}

/**
 * The meeting's notice period in days; the statutory default when it is
 * absent, negative or not a finite number.
 *
 * @param {object} meeting Meeting payload.
 *
 * @return {number} The notice period in days.
 */
function noticePeriodDays(meeting) {
	const days = Number(meeting?.noticePeriodDays ?? DEFAULT_NOTICE_PERIOD_DAYS)
	return !Number.isFinite(days) || days < 0 ? DEFAULT_NOTICE_PERIOD_DAYS : days
}

/**
 * The warning level for the days left until the deadline.
 *
 * @param {number} daysUntilDeadline Whole days until the deadline.
 *
 * @return {string} 'overdue', 'warning' or 'ok'.
 */
function deadlineLevel(daysUntilDeadline) {
	if (daysUntilDeadline < 0) {
		return 'overdue'
	}
	return daysUntilDeadline <= DEADLINE_WARNING_DAYS ? 'warning' : 'ok'
}
