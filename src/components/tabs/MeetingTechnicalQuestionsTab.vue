<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Meeting widget: the technical questions on this meeting's agenda (mot-17).

 A technical question is an agenda item whose type declares an `assignedTo`
 person field. Each row shows the question, the official it was assigned to,
 the answer deadline and whether it is open, answered or overdue, and opens
 the agenda item, where the official fills in the answer.

 @spec openspec/specs/motion-management/spec.md#requirement-req-mtq-001-technical-questions-go-to-an-official-with-a-deadline
-->
<template>
	<div class="decidiq-tab" data-testid="meeting-technical-questions-tab">
		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Technical questions')">
			{{ error }}
		</CnNoteCard>
		<p v-else-if="loading" class="decidiq-tab__muted">
			{{ t('decidiq', 'Loading…') }}
		</p>
		<p v-else-if="rows.length === 0" class="decidiq-tab__muted">
			{{ t('decidiq', 'This meeting has no technical questions.') }}
		</p>
		<table v-else class="decidiq-tab__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('decidiq', 'Question') }}
					</th>
					<th scope="col">
						{{ t('decidiq', 'Assignee') }}
					</th>
					<th scope="col">
						{{ t('decidiq', 'Deadline') }}
					</th>
					<th scope="col">
						{{ t('decidiq', 'Status') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="row in rows"
					:key="row.id"
					:data-status="row.status"
					data-testid="technical-question-row">
					<td>
						<router-link :to="{ path: `/agenda-items/${row.id}` }">
							{{ row.question }}
						</router-link>
					</td>
					<td>{{ row.assignee || '-' }}</td>
					<td>{{ row.deadline || '-' }}</td>
					<td>
						<span
							:class="`decidiq-tq-status decidiq-tq-status--${row.status}`">
							{{ statusLabel(row.status) }}
						</span>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { technicalQuestionRows } from '../../utils/technicalQuestions.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MeetingTechnicalQuestionsTab',
	components: { CnNoteCard },

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			items: [],
			types: [],
		}
	},

	computed: {
		/** @spec openspec/specs/motion-management/spec.md#requirement-req-mtq-001-technical-questions-go-to-an-official-with-a-deadline */
		rows() {
			return technicalQuestionRows(
				this.items,
				this.types,
				new Date().toISOString().slice(0, 10),
			)
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/motion-management/spec.md#requirement-req-mtq-001-technical-questions-go-to-an-official-with-a-deadline */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/**
		 * Load the meeting's agenda items and the configured item types.
		 *
		 * @spec openspec/specs/motion-management/spec.md#requirement-req-mtq-001-technical-questions-go-to-an-official-with-a-deadline
		 */
		async load() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const store = ensureRelationType('agenda-item')
				const typeStore = ensureRelationType('agenda-item-type')
				const [items, types] = await Promise.all([
					store.fetchCollection('agenda-item', {
						meeting: this.objectId,
						_limit: 200,
					}),
					typeStore.fetchCollection('agenda-item-type', { _limit: 200 }),
				])
				this.items = items || []
				this.types = types || []
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'Failed to load agenda.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {string} status open, answered or overdue.
		 * @return {string} The label.
		 * @spec openspec/specs/motion-management/spec.md#requirement-req-mtq-001-technical-questions-go-to-an-official-with-a-deadline
		 */
		statusLabel(status) {
			if (status === 'answered') return this.t('decidiq', 'Answered')
			if (status === 'overdue') return this.t('decidiq', 'Overdue')
			return this.t('decidiq', 'Open')
		},
	},
}
</script>

<style scoped>
.decidiq-tab__table {
	width: 100%;
	border-collapse: collapse;
}

.decidiq-tab__table th,
.decidiq-tab__table td {
	padding: 6px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.decidiq-tq-status {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-background-dark);
}

.decidiq-tq-status--answered {
	background-color: var(--color-success);
	color: var(--color-primary-element-text);
}

.decidiq-tq-status--overdue {
	background-color: var(--color-error);
	color: var(--color-primary-element-text);
}
</style>
