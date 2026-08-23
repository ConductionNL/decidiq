<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 Reaction moderation surface for consultations (consultations-engagement-hub).
 Used in TWO places via the same component:
  - per-consultation: as the "Reactions" tab on the consultation detail page,
    scoped to that consultation via the `objectId` prop;
  - hub-wide: the Moderation queue page mounts it with no `objectId` to list
    all pending reactions across every consultation.
 Reactions are read via useObjectStore (OpenRegister object API, ADR-022) and
 approved/rejected through the participation ACTION endpoints + dedicated modals.

 @spec openspec/specs/p3-citizen-participation/spec.md
-->
<template>
	<div class="consultation-reactions" data-testid="consultation-reactions-tab">
		<NcLoadingIcon v-if="loading" :size="44" />

		<NcEmptyContent
			v-else-if="pending.length === 0"
			:name="t('decidiq', 'No reactions awaiting moderation')"
			data-testid="consultation-reactions-empty">
			<template #icon>
				<CheckIcon :size="48" />
			</template>
		</NcEmptyContent>

		<ul v-else class="consultation-reactions__list">
			<li
				v-for="reaction in pending"
				:key="reaction.id"
				class="consultation-reactions__item"
				data-testid="consultation-reactions-item">
				<p class="consultation-reactions__body">{{ reaction.body }}</p>
				<p class="consultation-reactions__meta">
					{{ reaction.submittedAt }}
				</p>
				<div class="consultation-reactions__actions">
					<NcButton
						variant="success"
						data-testid="consultation-reactions-approve"
						@click="openApprove(reaction)">
						{{ t('decidiq', 'Approve') }}
					</NcButton>
					<NcButton
						variant="error"
						data-testid="consultation-reactions-reject"
						@click="openReject(reaction)">
						{{ t('decidiq', 'Reject') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<ReactionApproveModal
			v-if="approving"
			@confirm="confirmApprove"
			@close="approving = null" />
		<ReactionRejectModal
			v-if="rejecting"
			@confirm="confirmReject"
			@close="rejecting = null" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import ReactionApproveModal from '../../modals/ReactionApproveModal.vue'
import ReactionRejectModal from '../../modals/ReactionRejectModal.vue'
import { approveReaction, rejectReaction } from '../../services/participationApi.js'
import { useObjectStore } from '../../store/store.js'

export default {
	name: 'ConsultationReactionsTab',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		CheckIcon,
		ReactionApproveModal,
		ReactionRejectModal,
	},

	props: {
		/** Consultation id to scope the queue to. Empty = hub-wide (all pending reactions). */
		objectId: { type: [String, Number], default: '' },
		/** Register slug (provided by the detail page; unused for the action endpoints). */
		register: { type: String, default: '' },
		/** Schema slug (provided by the detail page; unused for the action endpoints). */
		schema: { type: String, default: '' },
	},

	data() {
		return {
			loading: true,
			pending: [],
			approving: null,
			rejecting: null,
		}
	},

	watch: {
		objectId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/** Load pending reactions, scoped to this consultation when objectId is set. *
		 * @spec openspec/specs/citizen-participation/spec.md#requirement-reaction-moderation-queue
		 */
		async load() {
			this.loading = true
			try {
				const store = useObjectStore()
				const filter = { moderationStatus: 'pending', _limit: 200 }
				if (this.objectId) {
					// Reactions link to their consultation via OR's generic relations
					// array (set server-side by ReactionIntakeService), not a plain
					// property — so scope via the relation filter key.
					filter['_relations.public-consultation'] = this.objectId
				}
				const result = await store.fetchCollection(
					'consultation-reaction',
					filter,
				)
				const list = Array.isArray(result)
					? result
					: result && result.results
						? result.results
						: []
				this.pending = list.filter(
					(r) => (r.moderationStatus || 'pending') === 'pending',
				)
			} catch (e) {
				showError(t('decidiq', 'Could not load the moderation queue'))
				this.pending = []
			} finally {
				this.loading = false
			}
		},

		openApprove(reaction) {
			this.approving = reaction
		},

		openReject(reaction) {
			this.rejecting = reaction
		},

		/**
		 * @spec openspec/specs/citizen-participation/spec.md#requirement-reaction-moderation-queue
		 */
		async confirmApprove(note) {
			const reaction = this.approving
			this.approving = null
			try {
				await approveReaction(reaction.id, note)
				showSuccess(t('decidiq', 'Reaction approved'))
				this.pending = this.pending.filter((r) => r.id !== reaction.id)
			} catch (e) {
				showError(t('decidiq', 'Could not approve the reaction'))
			}
		},

		/**
		 * @spec openspec/specs/citizen-participation/spec.md#requirement-reaction-moderation-queue
		 */
		async confirmReject(reason) {
			const reaction = this.rejecting
			this.rejecting = null
			try {
				await rejectReaction(reaction.id, reason)
				showSuccess(t('decidiq', 'Reaction rejected'))
				this.pending = this.pending.filter((r) => r.id !== reaction.id)
			} catch (e) {
				showError(t('decidiq', 'Could not reject the reaction'))
			}
		},
	},
}
</script>

<style scoped>
.consultation-reactions {
	padding: 8px 0;
}

.consultation-reactions__list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.consultation-reactions__item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.consultation-reactions__body {
	white-space: pre-wrap;
}

.consultation-reactions__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin: 4px 0 8px;
}

.consultation-reactions__actions {
	display: flex;
	gap: 8px;
}
</style>
