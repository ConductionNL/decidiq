// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Draft the minutes from the meeting, carry the AI draft into the minutes,
// and send approved minutes to the members (minutes-draft-and-send,
// min-01, min-02, min-07).
//
// @spec openspec/changes/minutes-draft-and-send/specs/p2-minutes-and-decisions/spec.md

/** Lifecycles in which the minutes may be sent to the members (server rule). */
export const SENDABLE_LIFECYCLES = ['approved', 'signed', 'published']

/**
 * The endpoint that drafts minutes from the meeting.
 *
 * @param {string} minutesId The minutes UUID
 * @return {string} The app-relative path
 * @spec openspec/changes/minutes-draft-and-send/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-001-draft-minutes-from-the-meeting
 */
export function draftPath(minutesId) {
	return `/apps/decidiq/api/minutes/${encodeURIComponent(minutesId)}/generate-draft`
}

/**
 * The endpoint that sends the minutes to the members.
 *
 * @param {string} minutesId The minutes UUID
 * @return {string} The app-relative path
 * @spec openspec/changes/minutes-draft-and-send/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-003-send-approved-minutes-to-the-members
 */
export function distributePath(minutesId) {
	return `/apps/decidiq/api/minutes/${encodeURIComponent(minutesId)}/distribute`
}

/**
 * Whether the minutes can still be drafted from the meeting.
 *
 * @param {object|null} minutes The minutes record
 * @return {boolean} True in the draft stage
 * @spec openspec/changes/minutes-draft-and-send/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-001-draft-minutes-from-the-meeting
 */
export function canDraft(minutes) {
	return !!minutes && (minutes.lifecycle || 'draft') === 'draft'
}

/**
 * Whether the minutes can be sent to the members.
 *
 * @param {object|null} minutes The minutes record
 * @return {boolean} True once approved
 * @spec openspec/changes/minutes-draft-and-send/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-003-send-approved-minutes-to-the-members
 */
export function canSend(minutes) {
	return !!minutes && SENDABLE_LIFECYCLES.includes(minutes.lifecycle)
}

/**
 * The minutes with the drafted text as their content.
 *
 * @param {object} minutes The minutes record
 * @param {string} preview The draft the server rendered
 * @return {object} The minutes to save
 * @spec openspec/changes/minutes-draft-and-send/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-001-draft-minutes-from-the-meeting
 */
export function withDraft(minutes, preview) {
	return { ...minutes, content: String(preview || '') }
}

/**
 * The sections of an AI draft the secretary kept.
 *
 * @param {object|null} draft The AI draft with its sections
 * @return {Array<object>} Sections not discarded
 * @spec openspec/changes/minutes-draft-and-send/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-002-use-the-ai-draft-as-the-minutes
 */
export function keptSections(draft) {
	return ((draft && draft.sections) || []).filter((s) => !s.discarded)
}

/**
 * The minutes holding the kept sections of the AI draft: the sections as
 * the minutes text, and each section's summary as the notes of its agenda
 * item (other items' notes stay).
 *
 * @param {object} minutes The minutes record
 * @param {object} draft The AI draft
 * @return {object} The minutes to save
 * @spec openspec/changes/minutes-draft-and-send/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-002-use-the-ai-draft-as-the-minutes
 */
export function minutesFromAiDraft(minutes, draft) {
	const kept = keptSections(draft)
	const content = kept
		.map((s) => `## ${s.title || ''}\n\n${s.summary || ''}`.trim())
		.join('\n\n')
	const notes = Array.isArray(minutes.itemNotes)
		? minutes.itemNotes.map((n) => ({ ...n }))
		: []
	for (const section of kept) {
		if (!section.agendaItem) continue
		const existing = notes.find((n) => n.agendaItem === section.agendaItem)
		if (existing) {
			existing.notes = section.summary || ''
		} else {
			notes.push({
				agendaItem: section.agendaItem,
				notes: section.summary || '',
			})
		}
	}
	return { ...minutes, content, itemNotes: notes }
}

/**
 * The new minutes record for a meeting that has none yet.
 *
 * @param {string} meetingId The meeting UUID
 * @param {string} title The minutes title
 * @return {object} The minutes to create
 * @spec openspec/changes/minutes-draft-and-send/specs/p2-minutes-and-decisions/spec.md#requirement-req-mds-002-use-the-ai-draft-as-the-minutes
 */
export function newMinutesFor(meetingId, title) {
	return { title, lifecycle: 'draft', meeting: meetingId }
}
