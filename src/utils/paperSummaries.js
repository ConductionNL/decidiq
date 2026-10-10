// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * AI summaries of meeting papers on the agenda item page
 * (agenda-ai-paper-summaries, matrix rows age-16 age-20 plt-27): which
 * summaries a viewer sees, which review actions a clerk gets per status, and
 * the payloads a review writes. The review states are the schema's
 * x-openregister-lifecycle (lib/Settings/register.d/119-paper-summaries.json),
 * so an action offered here is always a transition OpenRegister accepts.
 *
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-005-members-see-a-summary-only-after-a-clerk-shows-it
 */
import { generateUrl } from '@nextcloud/router'

/** Review actions per status, for a clerk. Edit stays inside draft. */
const CLERK_ACTIONS = {
	requested: [],
	draft: ['edit', 'show', 'hide'],
	shown: ['hide'],
	hidden: ['show'],
	failed: ['retry'],
}

/**
 * The address that asks for a summary of a paper of this agenda item.
 *
 * @param {string} agendaItemId The agenda item.
 * @return {string} The URL.
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
 */
export function requestUrl(agendaItemId) {
	return generateUrl(
		`/apps/decidiq/api/agenda-items/${agendaItemId}/paper-summaries`,
	)
}

/**
 * The address that says whether an AI provider is installed and whether the
 * viewer may ask.
 *
 * @return {string} The URL.
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
 */
export function availabilityUrl() {
	return generateUrl('/apps/decidiq/api/paper-summaries/availability')
}

/**
 * The summaries a viewer sees, newest first: a clerk every one, a member the
 * shown ones only (OpenRegister's read rule already filters; this keeps the
 * page right if the rule is loosened).
 *
 * @param {Array<object>} summaries The agenda item's summaries.
 * @param {boolean} isClerk Whether the viewer is a clerk.
 * @return {Array<object>} The summaries to show.
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-001-an-ai-summary-is-a-record-with-a-review-status
 */
export function visibleSummaries(summaries, isClerk) {
	const list = Array.isArray(summaries) ? summaries : []
	return list
		.filter((summary) => isClerk || summary?.status === 'shown')
		.slice()
		.sort((a, b) =>
			String(b?.['@self']?.created ?? '').localeCompare(
				String(a?.['@self']?.created ?? ''),
			),
		)
}

/**
 * The review actions a viewer gets on a summary.
 *
 * @param {object} summary The summary.
 * @param {boolean} isClerk Whether the viewer is a clerk.
 * @return {Array<string>} Some of edit, show, hide, retry.
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-005-members-see-a-summary-only-after-a-clerk-shows-it
 */
export function actionsFor(summary, isClerk) {
	if (!isClerk) return []
	return CLERK_ACTIONS[summary?.status] ?? []
}

/**
 * The stored fields of a summary, without OpenRegister's metadata.
 *
 * @param {object} summary The summary as read.
 * @return {object} Its own fields.
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-005-members-see-a-summary-only-after-a-clerk-shows-it
 */
function ownFields(summary) {
	const fields = { ...summary }
	delete fields['@self']
	delete fields.id
	return fields
}

/**
 * The summary shown to members or hidden again, with who did it and when.
 *
 * @param {object} summary The summary.
 * @param {string} status `shown` or `hidden`.
 * @param {string} reviewer The clerk's display name.
 * @param {Date} now The moment.
 * @return {object} The fields to save.
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-005-members-see-a-summary-only-after-a-clerk-shows-it
 */
export function reviewPayload(summary, status, reviewer, now) {
	return {
		...ownFields(summary),
		status,
		reviewedBy: reviewer,
		reviewedAt: now.toISOString(),
	}
}

/**
 * A draft with the clerk's corrected text; it stays a draft.
 *
 * @param {object} summary The draft.
 * @param {string} text The corrected text.
 * @return {object} The fields to save.
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-005-members-see-a-summary-only-after-a-clerk-shows-it
 */
export function editPayload(summary, text) {
	return { ...ownFields(summary), text }
}

/**
 * The papers of the item a clerk can pick, optionally without one.
 *
 * @param {Array<object>} files The item's files as OpenRegister lists them.
 * @param {number|null} without A file id to leave out.
 * @return {Array<{id: number, label: string}>} The options.
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
 */
export function paperOptions(files, without = null) {
	const list = Array.isArray(files) ? files : []
	return list
		.map((file) => ({
			id: Number(file?.id),
			label: String(file?.name ?? file?.title ?? file?.id ?? ''),
		}))
		.filter((option) => Number.isFinite(option.id) && option.id !== without)
}

/**
 * The request body that asks again for a failed summary.
 *
 * @param {object} summary The failed summary.
 * @return {{fileId: number, kind: string, comparedFileId?: number}} The body.
 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-004-the-ai-result-lands-as-a-draft-and-long-papers-are-summarised-in-parts
 */
export function retryBody(summary) {
	const body = {
		fileId: Number(summary?.paperFileId),
		kind: summary?.kind ?? 'summary',
	}
	if (body.kind === 'comparison' && summary?.comparedFileId) {
		body.comparedFileId = Number(summary.comparedFileId)
	}
	return body
}
