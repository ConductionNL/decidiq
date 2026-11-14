<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Motion page widget "Recommended changes": every change recommendation of the
 motion, oldest first, with its lines, its status and, opened, the tracked
 change. Accept and Reject show only to the chair or secretary of the
 motion's meeting, and only on a pending recommendation; the server refuses
 anyone else (motion-change-recommendations).

 @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-006-the-motion-page-lists-every-recommendation-with-its-status
-->
<template>
	<div
		class="decidiq-tab decidiq-tab--recommendations"
		data-testid="motion-change-recommendations-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Recommended changes') }}
				<span v-if="!loading" class="decidiq-tab__count"
					>({{ rows.length }})</span
				>
			</h3>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load the recommended changes')">
			{{ error }}
		</CnNoteCard>

		<p v-else-if="!loading && rows.length === 0" class="decidiq-tab__empty">
			{{ t('decidiq', 'No recommended changes for this motion yet.') }}
		</p>

		<ul v-else class="decidiq-recommendations">
			<li
				v-for="row in rows"
				:key="row.id"
				class="decidiq-recommendations__row"
				:data-testid="`change-recommendation-${row.id}`">
				<details>
					<summary class="decidiq-recommendations__summary">
						<span
							v-if="lineRange(row)"
							class="decidiq-recommendations__lines">
							{{
								t('decidiq', 'l. {lines}', { lines: lineRange(row) })
							}}
						</span>
						<span class="decidiq-recommendations__words">{{
							firstWords(row)
						}}</span>
						<CnStatusBadge
							:label="statusLabel(row)"
							:colorMap="statusColors" />
						<CnStatusBadge
							v-if="isPassageChanged(row, motionText)"
							:label="t('decidiq', 'Passage changed')"
							:colorMap="{
								[t('decidiq', 'Passage changed')]: 'warning',
							}"
							data-testid="change-recommendation-stale" />
					</summary>
					<AmendmentDiffView
						:originalText="row.targetPassage || ''"
						:proposedText="row.proposedText || ''" />
					<p v-if="row.reason" class="decidiq-recommendations__reason">
						{{ row.reason }}
					</p>
				</details>
				<div v-if="isPending(row)" class="decidiq-recommendations__actions">
					<template v-if="canDecide">
						<NcButton
							variant="primary"
							:disabled="busy || isPassageChanged(row, motionText)"
							data-testid="change-recommendation-accept"
							@click="decide(row, 'accept')">
							{{ t('decidiq', 'Accept') }}
						</NcButton>
						<NcButton
							:disabled="busy"
							data-testid="change-recommendation-reject"
							@click="decide(row, 'reject')">
							{{ t('decidiq', 'Reject') }}
						</NcButton>
					</template>
					<p v-else class="decidiq-recommendations__hint">
						{{
							t(
								'decidiq',
								'Only the chair or the secretary can take the next step.',
							)
						}}
					</p>
				</div>
			</li>
		</ul>

		<p v-if="decisionError" class="decidiq-error" role="alert">
			{{ decisionError }}
		</p>
	</div>
</template>

<script>
import { CnNoteCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import AmendmentDiffView from '../AmendmentDiffView.vue'
import {
	decisionPath,
	isPassageChanged,
	lineRange,
	oldestFirst,
} from '../../utils/changeRecommendations.js'
import { motionRecommendationContext } from './motionRecommendationContext.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MotionChangeRecommendationsTab',

	components: { AmendmentDiffView, CnNoteCard, CnStatusBadge, NcButton },

	mixins: [motionRecommendationContext],

	data() {
		return {
			loading: false,
			error: '',
			rows: [],
			busy: false,
			decisionError: '',
		}
	},

	computed: {
		/** @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-006-the-motion-page-lists-every-recommendation-with-its-status */
		statusColors() {
			return {
				[this.t('decidiq', 'Pending')]: 'info',
				[this.t('decidiq', 'Accepted')]: 'success',
				[this.t('decidiq', 'Rejected')]: 'error',
			}
		},
	},

	watch: {
		motionId: {
			immediate: true,
			/** @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-006-the-motion-page-lists-every-recommendation-with-its-status */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		isPassageChanged,
		lineRange,

		/** @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-006-the-motion-page-lists-every-recommendation-with-its-status */
		async refresh() {
			if (!this.motionId) return
			this.loading = true
			this.error = ''
			try {
				await this.loadMotionContext()
				const items = await ensureRelationType(
					'change-recommendation',
				).fetchCollection('change-recommendation', {
					motion: this.motionId,
					_limit: 200,
				})
				this.rows = oldestFirst(items)
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'Failed to load the recommended changes.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} row The recommendation.
		 * @return {boolean} Whether it still waits for a decision.
		 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-006-the-motion-page-lists-every-recommendation-with-its-status
		 */
		isPending(row) {
			return (row?.status || 'pending') === 'pending'
		},

		/**
		 * @param {object} row The recommendation.
		 * @return {string} Its status as shown.
		 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-006-the-motion-page-lists-every-recommendation-with-its-status
		 */
		statusLabel(row) {
			if (row?.status === 'accepted') return this.t('decidiq', 'Accepted')
			if (row?.status === 'rejected') return this.t('decidiq', 'Rejected')
			return this.t('decidiq', 'Pending')
		},

		/**
		 * @param {object} row The recommendation.
		 * @return {string} The first words of the new wording, or of the passage it deletes.
		 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-006-the-motion-page-lists-every-recommendation-with-its-status
		 */
		firstWords(row) {
			const words = String(row?.proposedText || row?.targetPassage || '')
				.trim()
				.split(/\s+/)
			return words.length > 8
				? `${words.slice(0, 8).join(' ')}…`
				: words.join(' ')
		},

		/**
		 * Accept or reject one recommendation, then reload the motion and the list.
		 *
		 * @param {object} row The recommendation.
		 * @param {'accept'|'reject'} action What to do.
		 * @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-003-the-chair-or-secretary-accepts-a-recommendation-into-the-text-without-a-vote
		 */
		async decide(row, action) {
			this.busy = true
			this.decisionError = ''
			try {
				await axios.post(generateUrl(decisionPath(row.id, action)))
				await this.refresh()
			} catch (e) {
				this.decisionError =
					e?.response?.data?.message
					|| this.t('decidiq', 'The decision could not be saved.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped src="./relationTab.css"></style>

<style scoped>
.decidiq-recommendations {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.decidiq-recommendations__row {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 2);
}

.decidiq-recommendations__summary {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	cursor: pointer;
}

.decidiq-recommendations__lines {
	font-weight: bold;
}

.decidiq-recommendations__actions {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-top: calc(var(--default-grid-baseline) * 2);
}

.decidiq-recommendations__hint,
.decidiq-recommendations__reason {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.decidiq-error {
	color: var(--color-error-text);
}
</style>
