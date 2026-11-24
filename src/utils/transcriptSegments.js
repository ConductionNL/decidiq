// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Addressing one segment of a meeting's transcript.
 *
 * A transcript segment has no id of its own: it is identified by where it
 * starts in the recording. A speaking request's `transcriptSegment` holds that
 * start, in seconds or as the transcript shows it (m:ss or h:mm:ss). The
 * agenda slot links to `#transcript-segment-<whole seconds>` on the meeting
 * page, where the transcript gives every segment that anchor.
 *
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-006-written-bijdrage-and-transcript-segment-linkage
 */

const PREFIX = 'transcript-segment-'

/**
 * The start of a segment in seconds, read from a stored or typed value.
 *
 * @param {number|string|null|undefined} value Seconds (number or numeric text), or m:ss / h:mm:ss.
 * @return {number|null} Whole seconds, or null when the value names no moment.
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-006-written-bijdrage-and-transcript-segment-linkage
 */
export function segmentStart(value) {
	if (typeof value === 'number') {
		return Number.isFinite(value) && value >= 0 ? Math.floor(value) : null
	}
	const text = String(value ?? '').trim()
	if (/^\d+(\.\d+)?$/.test(text)) {
		return Math.floor(Number(text))
	}
	const clock = text.match(/^(?:(\d+):)?(\d{1,2}):(\d{2})$/)
	if (!clock || Number(clock[3]) > 59 || (clock[1] !== undefined && Number(clock[2]) > 59)) {
		return null
	}
	return Number(clock[1] ?? 0) * 3600 + Number(clock[2]) * 60 + Number(clock[3])
}

/**
 * The anchor of the segment that starts at a moment.
 *
 * @param {number} seconds The start in seconds.
 * @return {string} The element id, without '#'.
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-006-written-bijdrage-and-transcript-segment-linkage
 */
export function segmentAnchor(seconds) {
	return `${PREFIX}${Math.floor(Number(seconds) || 0)}`
}

/**
 * The start named by a location hash, when it points at a segment.
 *
 * @param {string} hash A location hash such as '#transcript-segment-754'.
 * @return {number|null} The start in seconds, or null.
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-006-written-bijdrage-and-transcript-segment-linkage
 */
export function startFromHash(hash) {
	const match = String(hash ?? '').match(/^#?transcript-segment-(\d+)$/)
	return match ? Number(match[1]) : null
}

/**
 * A start as the transcript shows it: m:ss, or h:mm:ss from an hour on.
 *
 * @param {number} seconds The start in seconds.
 * @return {string} The formatted moment.
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-006-written-bijdrage-and-transcript-segment-linkage
 */
export function formatOffset(seconds) {
	const total = Math.max(0, Math.floor(Number(seconds) || 0))
	const h = Math.floor(total / 3600)
	const m = Math.floor((total % 3600) / 60)
	const s = String(total % 60).padStart(2, '0')
	return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`
}

/**
 * Where the meeting page shows a speaker's linked segment, or null when none is linked.
 *
 * @param {string} meetingId The meeting the speaker spoke at.
 * @param {number|string|null|undefined} transcriptSegment The speaking request's segment start.
 * @return {object|null} A route to the meeting page at the segment.
 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-006-written-bijdrage-and-transcript-segment-linkage
 */
export function transcriptRoute(meetingId, transcriptSegment) {
	const start = segmentStart(transcriptSegment)
	if (start === null || !meetingId) return null
	return { name: 'MeetingDetail', params: { id: String(meetingId) }, hash: `#${segmentAnchor(start)}` }
}
