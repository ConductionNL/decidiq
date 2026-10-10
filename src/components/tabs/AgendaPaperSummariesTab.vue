<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 AI summaries of the papers of an agenda item (agenda-ai-paper-summaries,
 matrix rows age-16 age-20 plt-27). A clerk asks for a summary of a paper or a
 comparison of two, corrects a draft, and shows it to members or hides it. A
 member reads shown summaries only, labelled as AI-generated with the clerk who
 checked it. Without an AI provider the actions are left out and the widget
 says so. The rules live in src/utils/paperSummaries.js.

 @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-005-members-see-a-summary-only-after-a-clerk-shows-it
-->
<template>
	<div class="paper-summaries" data-testid="paper-summaries">
		<NcLoadingIcon v-if="loading" :size="24" />
		<p v-else-if="error" class="paper-summaries__error" role="alert">
			{{ error }}
		</p>
		<template v-else>
			<p
				v-if="isClerk && !availability.available"
				class="paper-summaries__hint"
				data-testid="paper-summaries-no-provider">
				{{
					t(
						'decidiq',
						'No AI provider is installed, so papers cannot be summarised.',
					)
				}}
			</p>
			<ul
				v-if="isClerk && availability.available && papers.length > 0"
				class="paper-summaries__papers">
				<li
					v-for="paper in papers"
					:key="paper.id"
					class="paper-summaries__paper"
					data-testid="paper-summaries-paper">
					<span class="paper-summaries__name">{{ paper.label }}</span>
					<div class="paper-summaries__actions">
						<NcButton
							v-if="availability.summary"
							variant="secondary"
							:disabled="busy"
							:aria-label="
								t('decidiq', 'Summarise {name}', {
									name: paper.label,
								})
							"
							data-testid="paper-summaries-summarise"
							@click="ask({ fileId: paper.id, kind: 'summary' })">
							{{ t('decidiq', 'Summarise') }}
						</NcButton>
						<NcSelect
							v-if="availability.comparison && papers.length > 1"
							:modelValue="null"
							:options="paperOptionsFor(paper.id)"
							:disabled="busy"
							:clearable="false"
							:inputLabel="t('decidiq', 'Compare with')"
							:aria-label-combobox="
								t('decidiq', 'Compare {name} with another paper', {
									name: paper.label,
								})
							"
							class="paper-summaries__compare"
							data-testid="paper-summaries-compare"
							@update:modelValue="
								(option) =>
									option
									&& ask({
										fileId: paper.id,
										kind: 'comparison',
										comparedFileId: option.id,
									})
							" />
					</div>
				</li>
			</ul>
			<p
				v-if="summaries.length === 0"
				class="paper-summaries__empty"
				data-testid="paper-summaries-empty">
				{{ t('decidiq', 'No summaries of the papers yet.') }}
			</p>
			<ul v-else class="paper-summaries__list">
				<li
					v-for="summary in summaries"
					:key="summary.id"
					class="paper-summaries__summary"
					data-testid="paper-summaries-summary">
					<div class="paper-summaries__head">
						<strong>{{ headline(summary) }}</strong>
						<span
							v-if="isClerk"
							class="paper-summaries__status"
							data-testid="paper-summaries-status">
							{{ statusLabel(summary.status) }}
						</span>
					</div>
					<NcTextArea
						v-if="editing === summary.id"
						v-model="draftText"
						:label="t('decidiq', 'Summary text')"
						resize="vertical"
						data-testid="paper-summaries-text-input" />
					<p v-else-if="summary.text" class="paper-summaries__text">
						{{ summary.text }}
					</p>
					<p
						v-if="summary.status === 'failed' && summary.failure"
						class="paper-summaries__failure">
						{{
							t(
								'decidiq',
								'The AI provider could not write it: {reason}',
								{ reason: summary.failure },
							)
						}}
					</p>
					<p
						v-if="summary.status === 'requested'"
						class="paper-summaries__hint">
						{{
							t(
								'decidiq',
								'Waiting for the AI provider. Reload the page in a few minutes.',
							)
						}}
					</p>
					<p
						v-if="summary.status === 'shown' && summary.reviewedBy"
						class="paper-summaries__label"
						data-testid="paper-summaries-label">
						{{ checkedLabel(summary) }}
					</p>
					<p v-if="summary.chunked" class="paper-summaries__hint">
						{{
							t(
								'decidiq',
								'The paper was long, so it was summarised in parts first.',
							)
						}}
					</p>
					<div
						v-if="actionsOf(summary).length > 0"
						class="paper-summaries__actions">
						<template v-if="editing === summary.id">
							<NcButton
								variant="primary"
								:disabled="busy"
								data-testid="paper-summaries-save"
								@click="saveText(summary)">
								{{ t('decidiq', 'Save') }}
							</NcButton>
							<NcButton
								variant="tertiary"
								:disabled="busy"
								@click="editing = null">
								{{ t('decidiq', 'Cancel') }}
							</NcButton>
						</template>
						<template v-else>
							<NcButton
								v-if="actionsOf(summary).includes('edit')"
								variant="secondary"
								:disabled="busy"
								data-testid="paper-summaries-edit"
								@click="startEdit(summary)">
								{{ t('decidiq', 'Edit') }}
							</NcButton>
							<NcButton
								v-if="actionsOf(summary).includes('show')"
								variant="primary"
								:disabled="busy"
								data-testid="paper-summaries-show"
								@click="review(summary, 'shown')">
								{{ t('decidiq', 'Show to members') }}
							</NcButton>
							<NcButton
								v-if="actionsOf(summary).includes('hide')"
								variant="secondary"
								:disabled="busy"
								data-testid="paper-summaries-hide"
								@click="review(summary, 'hidden')">
								{{ t('decidiq', 'Hide') }}
							</NcButton>
							<NcButton
								v-if="
									actionsOf(summary).includes('retry')
									&& availability.available
								"
								variant="secondary"
								:disabled="busy"
								data-testid="paper-summaries-retry"
								@click="ask(retryBodyOf(summary))">
								{{ t('decidiq', 'Try again') }}
							</NcButton>
						</template>
					</div>
				</li>
			</ul>
			<p v-if="notice" class="paper-summaries__notice" role="status">
				{{ notice }}
			</p>
		</template>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcSelect, NcTextArea } from '@nextcloud/vue'
import {
	actionsFor,
	availabilityUrl,
	editPayload,
	paperOptions,
	requestUrl,
	retryBody,
	reviewPayload,
	visibleSummaries,
} from '../../utils/paperSummaries.js'

export default {
	name: 'AgendaPaperSummariesTab',

	components: { NcButton, NcLoadingIcon, NcSelect, NcTextArea },

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			busy: false,
			error: '',
			notice: '',
			availability: {
				available: false,
				summary: false,
				comparison: false,
				canRequest: false,
			},

			files: [],
			records: [],
			editing: null,
			draftText: '',
		}
	},

	computed: {
		/** @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-005-members-see-a-summary-only-after-a-clerk-shows-it */
		isClerk() {
			return this.availability.canRequest === true
		},

		/** @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper */
		papers() {
			return paperOptions(this.files)
		},

		/** @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-001-an-ai-summary-is-a-record-with-a-review-status */
		summaries() {
			return visibleSummaries(this.records, this.isClerk)
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-005-members-see-a-summary-only-after-a-clerk-shows-it */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/** @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-005-members-see-a-summary-only-after-a-clerk-shows-it */
		async load() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const [availability, summaries] = await Promise.all([
					axios.get(availabilityUrl()),
					axios.get(
						generateUrl(
							'/apps/openregister/api/objects/decidiq/paper-summary',
						),
						{
							params: { agendaItem: this.objectId, _limit: 100 },
						},
					),
				])
				this.availability = {
					...this.availability,
					...(availability?.data || {}),
				}
				const list = summaries?.data?.results ?? summaries?.data ?? []
				this.records = (Array.isArray(list) ? list : []).filter(
					(summary) => summary?.agendaItem === String(this.objectId),
				)
				this.files = this.isClerk ? await this.fetchFiles() : []
			} catch {
				this.error = this.t(
					'decidiq',
					'The summaries of this agenda item could not be read.',
				)
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper */
		async fetchFiles() {
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/decidiq/agenda-item/${this.objectId}/files`,
					),
				)
				const list = data?.results ?? data ?? []
				return Array.isArray(list) ? list : []
			} catch {
				return []
			}
		},

		/**
		 * @param {object} body The request body: fileId, kind and comparedFileId.
		 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
		 */
		async ask(body) {
			this.busy = true
			this.notice = ''
			try {
				const { data } = await axios.post(requestUrl(this.objectId), body)
				this.records = [
					{ ...data, '@self': { created: new Date().toISOString() } },
					...this.records,
				]
				this.notice = this.t(
					'decidiq',
					'Asked the AI provider. The summary appears here as a draft when it is ready.',
				)
			} catch (e) {
				this.notice =
					e?.response?.data?.message
					|| this.t('decidiq', 'The summary could not be asked for.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {object} summary The summary.
		 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-005-members-see-a-summary-only-after-a-clerk-shows-it
		 */
		startEdit(summary) {
			this.editing = summary.id
			this.draftText = summary.text || ''
		},

		/**
		 * @param {object} summary The summary.
		 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-005-members-see-a-summary-only-after-a-clerk-shows-it
		 */
		async saveText(summary) {
			await this.save(summary, editPayload(summary, this.draftText))
			this.editing = null
		},

		/**
		 * @param {object} summary The summary.
		 * @param {string} status `shown` or `hidden`, or the status to label.
		 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-005-members-see-a-summary-only-after-a-clerk-shows-it
		 */
		async review(summary, status) {
			const user = getCurrentUser()
			const reviewer = user?.displayName || user?.uid || ''
			await this.save(
				summary,
				reviewPayload(summary, status, reviewer, new Date()),
			)
		},

		/**
		 * @param {object} summary The summary.
		 * @param {object} payload The fields to save.
		 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-005-members-see-a-summary-only-after-a-clerk-shows-it
		 */
		async save(summary, payload) {
			this.busy = true
			this.notice = ''
			try {
				const { data } = await axios.put(
					generateUrl(
						`/apps/openregister/api/objects/decidiq/paper-summary/${summary.id}`,
					),
					payload,
				)
				this.records = this.records.map((record) =>
					record.id === summary.id
						? { ...record, ...payload, ...(data || {}) }
						: record,
				)
			} catch (e) {
				this.notice =
					e?.response?.data?.message
					|| this.t('decidiq', 'The summary could not be saved.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {object} summary The summary.
		 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-005-members-see-a-summary-only-after-a-clerk-shows-it
		 */
		actionsOf(summary) {
			return actionsFor(summary, this.isClerk)
		},

		/**
		 * @param {object} summary The summary.
		 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-004-the-ai-result-lands-as-a-draft-and-long-papers-are-summarised-in-parts
		 */
		retryBodyOf(summary) {
			return retryBody(summary)
		},

		/**
		 * @param {number} fileId The paper to leave out.
		 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
		 */
		paperOptionsFor(fileId) {
			return paperOptions(this.files, fileId)
		},

		/**
		 * @param {object} summary The summary.
		 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-002-a-clerk-asks-for-a-summary-or-a-comparison-of-a-paper
		 */
		headline(summary) {
			if (summary.kind === 'comparison') {
				return this.t('decidiq', 'Comparison of {paper} with {other}', {
					paper: summary.paperTitle || '',
					other: summary.comparedTitle || '',
				})
			}
			return this.t('decidiq', 'Summary of {paper}', {
				paper: summary.paperTitle || '',
			})
		},

		/**
		 * @param {object} summary The summary.
		 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-005-members-see-a-summary-only-after-a-clerk-shows-it
		 */
		checkedLabel(summary) {
			const date = summary.reviewedAt
				? new Date(summary.reviewedAt).toLocaleDateString()
				: ''
			return this.t(
				'decidiq',
				'AI-generated summary, checked by {name} on {date}',
				{
					name: summary.reviewedBy,
					date,
				},
			)
		},

		/**
		 * @param {string} status The status to label.
		 * @spec openspec/changes/agenda-ai-paper-summaries/specs/agenda-ai-paper-summaries/spec.md#requirement-req-aps-001-an-ai-summary-is-a-record-with-a-review-status
		 */
		statusLabel(status) {
			const labels = {
				requested: this.t('decidiq', 'Requested'),
				draft: this.t('decidiq', 'Draft'),
				shown: this.t('decidiq', 'Shown to members'),
				hidden: this.t('decidiq', 'Hidden'),
				failed: this.t('decidiq', 'Failed'),
			}
			return labels[status] || status
		},
	},
}
</script>

<style scoped>
.paper-summaries__papers,
.paper-summaries__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.paper-summaries__paper,
.paper-summaries__summary {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.paper-summaries__head,
.paper-summaries__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.paper-summaries__text {
	white-space: pre-line;
}

.paper-summaries__status,
.paper-summaries__label,
.paper-summaries__hint,
.paper-summaries__failure {
	color: var(--color-text-maxcontrast);
}

.paper-summaries__compare {
	min-width: 240px;
}
</style>
