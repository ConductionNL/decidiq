<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: digital approval workflow on a Minutes record
 (minutes-ui-v1).

 Renders the lifecycle timeline (draft → review → approved → signed →
 published), the chair/secretary workflow actions (submit for review /
 approve / reject-with-comment / sign / publish — the server's guarded
 endpoints stay authoritative), the participant correction suggestions
 with accept/reject resolution, and the rejection history.

 @spec openspec/specs/resolution-minutes/spec.md
-->
<template>
	<div
		class="decidesk-tab decidesk-tab--approval"
		data-testid="minutes-approval-tab">
		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Approval workflow error')">
			{{ error }}
		</CnNoteCard>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else-if="minutes">
			<h3 class="decidesk-tab__title">
				{{ t('decidesk', 'Approval workflow') }}
			</h3>

			<CnTimelineStages
				:stages="stages"
				:current="currentStageIndex"
				:aria-label="t('decidesk', 'Minutes lifecycle')" />

			<div
				class="decidesk-tab__actions"
				data-testid="minutes-approval-actions">
				<template v-for="action in actions" :key="action.action">
					<NcButton
						:variant="action.action === 'reject' ? 'error' : 'primary'"
						:data-testid="`minutes-action-${action.action}`"
						:disabled="working"
						@click="runAction(action)">
						{{ actionLabel(action.action) }}
					</NcButton>
				</template>
				<p v-if="minutes.approvedAt" class="decidesk-tab__meta">
					{{
						t('decidesk', 'Approved at {date} by {names}', {
							date: minutes.approvedAt,
							names: signedByLabel,
						})
					}}
				</p>
			</div>

			<div
				v-if="lastRejection"
				class="decidesk-tab__rejection"
				data-testid="minutes-last-rejection">
				<CnNoteCard
					type="warning"
					:title="t('decidesk', 'Returned to draft')">
					{{ lastRejection.comment }}
				</CnNoteCard>
			</div>

			<div class="decidesk-tab__corrections">
				<div class="decidesk-tab__header">
					<h3 class="decidesk-tab__title">
						{{ t('decidesk', 'Correction suggestions') }}
						<span class="decidesk-tab__count"
							>({{ corrections.length }})</span
						>
					</h3>
					<NcButton
						v-if="canSuggest"
						data-testid="minutes-correction-add"
						:aria-label="t('decidesk', 'Suggest a correction')"
						@click="correctionModalOpen = true">
						{{ t('decidesk', 'Suggest a correction') }}
					</NcButton>
				</div>

				<p v-if="corrections.length === 0" class="decidesk-tab__empty">
					{{ t('decidesk', 'No corrections suggested.') }}
				</p>
				<ul v-else class="decidesk-tab__list" role="list">
					<li
						v-for="correction in corrections"
						:key="correction.id"
						class="decidesk-tab__correction"
						role="listitem">
						<div class="decidesk-tab__correction-body">
							<CnStatusBadge
								:label="statusLabel(correction.status)"
								:color-map="correctionColors" />
							<span class="decidesk-tab__correction-text">{{
								correction.text
							}}</span>
							<span class="decidesk-tab__meta">
								{{ correction.authorName || correction.author }}
							</span>
						</div>
						<div
							v-if="correction.status === 'proposed'"
							class="decidesk-tab__correction-actions">
							<NcButton
								size="small"
								variant="primary"
								:disabled="working"
								:aria-label="t('decidesk', 'Accept correction')"
								@click="resolveCorrection(correction, 'accepted')">
								{{ t('decidesk', 'Accept') }}
							</NcButton>
							<NcButton
								size="small"
								:disabled="working"
								:aria-label="t('decidesk', 'Reject correction')"
								@click="resolveCorrection(correction, 'rejected')">
								{{ t('decidesk', 'Dismiss') }}
							</NcButton>
						</div>
					</li>
				</ul>
			</div>
		</template>

		<MinutesRejectModal
			v-if="rejectModalOpen"
			@confirm="confirmReject"
			@close="rejectModalOpen = false" />
		<MinutesCorrectionModal
			v-if="correctionModalOpen"
			@confirm="confirmCorrection"
			@close="correctionModalOpen = false" />
	</div>
</template>

<script>
import {
	CnNoteCard,
	CnStatusBadge,
	CnTimelineStages,
} from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import MinutesRejectModal from '../../modals/MinutesRejectModal.vue'
import MinutesCorrectionModal from '../../modals/MinutesCorrectionModal.vue'
import { ensureRelationType } from './useRelationStore.js'
import {
	LIFECYCLE_STAGES,
	availableWorkflowActions,
	canSuggestCorrections,
} from '../minutesEditor/minutesEditor.js'

export default {
	name: 'MinutesApprovalTab',
	components: {
		CnNoteCard,
		CnStatusBadge,
		CnTimelineStages,
		MinutesCorrectionModal,
		MinutesRejectModal,
		NcButton,
		NcLoadingIcon,
	},
	props: {
		objectId: { type: [String, Number], default: '' },
	},
	data() {
		return {
			loading: false,
			working: false,
			error: '',
			minutes: null,
			rejectModalOpen: false,
			correctionModalOpen: false,
		}
	},
	computed: {
		/** @spec openspec/specs/resolution-minutes/spec.md */
		stages() {
			return LIFECYCLE_STAGES.map((stage) => ({
				id: stage,
				label: this.statusLabel(stage),
			}))
		},
		/** @spec openspec/specs/resolution-minutes/spec.md */
		currentStageIndex() {
			const index = LIFECYCLE_STAGES.indexOf(
				this.minutes?.lifecycle || 'draft',
			)
			return index === -1 ? 0 : index
		},
		/** @spec openspec/specs/resolution-minutes/spec.md */
		actions() {
			return availableWorkflowActions(this.minutes?.lifecycle || 'draft')
		},
		/** @spec openspec/specs/resolution-minutes/spec.md */
		corrections() {
			return Array.isArray(this.minutes?.corrections)
				? this.minutes.corrections
				: []
		},
		/** @spec openspec/specs/resolution-minutes/spec.md */
		canSuggest() {
			return canSuggestCorrections(this.minutes?.lifecycle || 'draft')
		},
		/** @spec openspec/specs/resolution-minutes/spec.md */
		lastRejection() {
			const comments = Array.isArray(this.minutes?.reviewComments)
				? this.minutes.reviewComments
				: []
			const rejections = comments.filter((c) => c?.action === 'rejected')
			return rejections.length ? rejections[rejections.length - 1] : null
		},
		/** @spec openspec/specs/resolution-minutes/spec.md */
		signedByLabel() {
			const signers = Array.isArray(this.minutes?.signedBy)
				? this.minutes.signedBy
				: []
			return signers.join(', ')
		},
		/** @spec openspec/specs/resolution-minutes/spec.md */
		correctionColors() {
			return {
				[this.statusLabel('proposed')]: 'warning',
				[this.statusLabel('accepted')]: 'success',
				[this.statusLabel('rejected')]: 'error',
			}
		},
	},
	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/resolution-minutes/spec.md */
			handler() {
				this.refresh()
			},
		},
	},
	methods: {
		/**
		 * Translated label for a lifecycle or correction status value.
		 *
		 * @param {string} value Lifecycle stage or correction status.
		 * @return {string} The translated label.
		 * @spec openspec/specs/resolution-minutes/spec.md
		 */
		statusLabel(value) {
			const labels = {
				draft: this.t('decidesk', 'Draft'),
				review: this.t('decidesk', 'In review'),
				approved: this.t('decidesk', 'Approved'),
				signed: this.t('decidesk', 'Signed'),
				published: this.t('decidesk', 'Published'),
				proposed: this.t('decidesk', 'Proposed'),
				accepted: this.t('decidesk', 'Accepted'),
				rejected: this.t('decidesk', 'Rejected'),
			}
			return labels[value] || value
		},
		/**
		 * Translated button label for a workflow action.
		 *
		 * @param {string} action Workflow action id.
		 * @return {string} The translated label.
		 * @spec openspec/specs/resolution-minutes/spec.md
		 */
		actionLabel(action) {
			const labels = {
				submit: this.t('decidesk', 'Submit for review'),
				approve: this.t('decidesk', 'Approve'),
				reject: this.t('decidesk', 'Reject…'),
				sign: this.t('decidesk', 'Sign'),
				publish: this.t('decidesk', 'Publish'),
			}
			return labels[action] || action
		},
		/** @spec openspec/specs/resolution-minutes/spec.md */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const store = ensureRelationType('minutes')
				this.minutes = await store.fetchObject('minutes', this.objectId)
			} catch (e) {
				this.error =
					e?.message || this.t('decidesk', 'Failed to load the minutes.')
			} finally {
				this.loading = false
			}
		},
		/**
		 * POST helper against the decidesk minutes API.
		 *
		 * @param {string} path Path under /apps/decidesk/api.
		 * @param {object} body JSON body.
		 * @param {string} method HTTP method.
		 * @return {Promise<object>} Parsed response body.
		 * @spec openspec/specs/resolution-minutes/spec.md
		 */
		async callApi(path, body = {}, method = 'POST') {
			const response = await fetch(generateUrl(`/apps/decidesk/api${path}`), {
				method,
				headers: {
					'Content-Type': 'application/json',
					requesttoken: window.OC?.requestToken,
				},
				body: JSON.stringify(body),
			})
			const data = await response.json().catch(() => ({}))
			if (!response.ok) {
				throw new Error(
					data.message || this.t('decidesk', 'The action failed.'),
				)
			}
			return data
		},
		/**
		 * Run a workflow action (reject opens the comment dialog instead).
		 *
		 * @param {{action: string, target: string}} action The workflow action.
		 * @spec openspec/specs/resolution-minutes/spec.md
		 */
		async runAction(action) {
			this.error = ''
			if (action.action === 'reject') {
				this.rejectModalOpen = true
				return
			}
			this.working = true
			try {
				if (action.action === 'submit') {
					await this.callApi(
						`/minutes/${this.objectId}/submit-for-approval`,
					)
				} else {
					await this.callApi(`/minutes/${this.objectId}/transition`, {
						lifecycle: action.target,
					})
				}
				await this.refresh()
			} catch (e) {
				this.error = e.message
			} finally {
				this.working = false
			}
		},
		/**
		 * Reject the minutes back to draft with the mandatory comment.
		 *
		 * @param {string} comment The rejection comment.
		 * @spec openspec/specs/resolution-minutes/spec.md
		 */
		async confirmReject(comment) {
			this.rejectModalOpen = false
			this.working = true
			this.error = ''
			try {
				await this.callApi(`/minutes/${this.objectId}/reject`, { comment })
				await this.refresh()
			} catch (e) {
				this.error = e.message
			} finally {
				this.working = false
			}
		},
		/**
		 * Submit a correction suggestion (author attributed server-side).
		 *
		 * @param {string} text The suggested correction.
		 * @spec openspec/specs/resolution-minutes/spec.md
		 */
		async confirmCorrection(text) {
			this.correctionModalOpen = false
			this.working = true
			this.error = ''
			try {
				await this.callApi(`/minutes/${this.objectId}/corrections`, { text })
				await this.refresh()
			} catch (e) {
				this.error = e.message
			} finally {
				this.working = false
			}
		},
		/**
		 * Accept or reject a correction suggestion (chair/secretary only).
		 *
		 * @param {object} correction The correction entry.
		 * @param {string} status 'accepted' or 'rejected'.
		 * @spec openspec/specs/resolution-minutes/spec.md
		 */
		async resolveCorrection(correction, status) {
			this.working = true
			this.error = ''
			try {
				await this.callApi(
					`/minutes/${this.objectId}/corrections/${correction.id}`,
					{ status },
					'PUT',
				)
				await this.refresh()
			} catch (e) {
				this.error = e.message
			} finally {
				this.working = false
			}
		},
	},
}
</script>

<style scoped>
.decidesk-tab {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline);
}

.decidesk-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.decidesk-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}

.decidesk-tab__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	margin-inline-start: 4px;
}

.decidesk-tab__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.decidesk-tab__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.decidesk-tab__correction {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.decidesk-tab__correction:last-child {
	border-bottom: none;
}

.decidesk-tab__correction-body {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.decidesk-tab__correction-text {
	flex: 1;
}

.decidesk-tab__correction-actions {
	display: flex;
	gap: var(--default-grid-baseline);
}

.decidesk-tab__meta,
.decidesk-tab__empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
