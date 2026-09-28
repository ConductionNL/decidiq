/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Technical questions assigned to officials (mot-17).
 *
 * A technical question is an agenda item whose type declares an `assignedTo`
 * field. Its status follows from its type fields: answered once `answer` is
 * filled, overdue when `answerDeadline` has passed without an answer, open
 * otherwise.
 *
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mtq-001-technical-questions-go-to-an-official-with-a-deadline
 */

import { findItemType } from './agendaItemTypeFields.js'

/**
 * The status of one technical question on a given day.
 *
 * @param {object} item The agenda item.
 * @param {string} today The day, as YYYY-MM-DD.
 * @return {'answered'|'overdue'|'open'} The status.
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mtq-001-technical-questions-go-to-an-official-with-a-deadline
 */
export function technicalQuestionStatus(item, today) {
	const fields = item?.typeFields || {}
	if (String(fields.answer || '').trim() !== '') return 'answered'
	const deadline = String(fields.answerDeadline || '').slice(0, 10)
	if (deadline !== '' && deadline < today) return 'overdue'
	return 'open'
}

/**
 * The technical questions among a meeting's agenda items, as list rows.
 *
 * @param {Array<object>} items The meeting's agenda items.
 * @param {Array<object>} types The configured agenda item types.
 * @param {string} today The day, as YYYY-MM-DD.
 * @return {Array<{id: string, title: string, question: string, assignee: string, deadline: string, status: string}>} The rows.
 * @spec openspec/specs/motion-management/spec.md#requirement-req-mtq-001-technical-questions-go-to-an-official-with-a-deadline
 */
export function technicalQuestionRows(items, types, today) {
	return (items || [])
		.filter((item) => {
			const type = findItemType(types, item?.type)
			return Array.isArray(type?.fields) && type.fields.some((field) => field?.key === 'assignedTo')
		})
		.map((item) => {
			const fields = item.typeFields || {}
			return {
				id: item.id || item.uuid,
				title: item.title || '',
				question: String(fields.question || item.title || ''),
				assignee: String(fields.assignedTo || ''),
				deadline: String(fields.answerDeadline || '').slice(0, 10),
				status: technicalQuestionStatus(item, today),
			}
		})
}
