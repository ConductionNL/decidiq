// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Pure helpers for playing the meeting recording from the moment an agenda
 * item started (live-recording-jump-to-item).
 *
 * @spec openspec/specs/meeting-transcription/spec.md#requirement-req-lrj-001-jump-to-an-item-in-the-recording
 */

const VIDEO_EXTENSIONS = ['mp4', 'webm', 'mov', 'mkv', 'm4v', 'ogv']

/**
 * The moment each agenda item started in the recording: the earliest start
 * of the transcript segments aligned to it.
 *
 * @param {?Array<{startTime?: number, agendaItem?: string}>} segments The transcript segments.
 *
 * @return {Object<string, number>} Seconds from the start, per agenda item id.
 *
 * @spec openspec/specs/meeting-transcription/spec.md#requirement-req-lrj-001-jump-to-an-item-in-the-recording
 */
export function itemStartTimes(segments) {
	const starts = {}
	for (const segment of segments ?? []) {
		const item = segment?.agendaItem
		const start = Number(segment?.startTime)
		if (!item || segment?.startTime === undefined || !Number.isFinite(start))
			continue
		if (starts[item] === undefined || start < starts[item]) starts[item] = start
	}
	return starts
}

/**
 * Whether the recording is a video, by its file name.
 *
 * @param {?string} path The recording's path.
 *
 * @return {boolean} True for a video file.
 *
 * @spec openspec/specs/meeting-transcription/spec.md#requirement-req-lrj-001-jump-to-an-item-in-the-recording
 */
export function isVideoRecording(path) {
	const extension = String(path ?? '')
		.split('.')
		.pop()
		.toLowerCase()
	return String(path ?? '').includes('.') && VIDEO_EXTENSIONS.includes(extension)
}
