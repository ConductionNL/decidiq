<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Staff moderation queue for citizen-participation consultation reactions
 (citizen-participation). Lists pending ConsultationReaction objects (read via
 useObjectStore / the OpenRegister object API per ADR-022) and approves or
 rejects them through the participation ACTION endpoints. Approve/reject use
 dedicated modals in src/modals/.

 @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
-->
<template>
	<div class="moderation-queue" data-testid="moderation-queue-page">
		<header class="moderation-queue__header">
			<h2>{{ t('decidesk', 'Reaction moderation queue') }}</h2>
			<p>{{ t('decidesk', 'Approve or reject citizen reactions before they count toward a consultation.') }}</p>
		</header>

		<NcLoadingIcon v-if="loading" :size="44" />

		<NcEmptyContent
			v-else-if="pending.length === 0"
			:name="t('decidesk', 'No reactions awaiting moderation')"
			data-testid="moderation-queue-empty">
			<template #icon>
				<CheckIcon :size="48" />
			</template>
		</NcEmptyContent>

		<ul v-else class="moderation-queue__list">
			<li
				v-for="reaction in pending"
				:key="reaction.id"
				class="moderation-queue__item"
				data-testid="moderation-queue-item">
				<p class="moderation-queue__body">{{ reaction.body }}</p>
				<p class="moderation-queue__meta">{{ reaction.submittedAt }}</p>
				<div class="moderation-queue__actions">
					<NcButton
						type="success"
						data-testid="moderation-approve"
						@click="openApprove(reaction)">
						{{ t('decidesk', 'Approve') }}
					</NcButton>
					<NcButton
						type="error"
						data-testid="moderation-reject"
						@click="openReject(reaction)">
						{{ t('decidesk', 'Reject') }}
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
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import ReactionApproveModal from '../../modals/ReactionApproveModal.vue'
import ReactionRejectModal from '../../modals/ReactionRejectModal.vue'
import { useObjectStore } from '../../store/store.js'
import { approveReaction, rejectReaction } from '../../services/participationApi.js'

export default {
	name: 'ModerationQueuePage',
	components: { NcButton, NcEmptyContent, NcLoadingIcon, CheckIcon, ReactionApproveModal, ReactionRejectModal },
	data() {
		return {
			loading: true,
			pending: [],
			approving: null,
			rejecting: null,
		}
	},
	mounted() {
		this.load()
	},
	methods: {
		async load() {
			this.loading = true
			try {
				const store = useObjectStore()
				const result = await store.fetchCollection('consultation-reaction', { moderationStatus: 'pending', _limit: 200 })
				const list = Array.isArray(result) ? result : ((result && result.results) ? result.results : [])
				this.pending = list.filter((r) => (r.moderationStatus || 'pending') === 'pending')
			} catch (e) {
				showError(t('decidesk', 'Could not load the moderation queue'))
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
		async confirmApprove(note) {
			const reaction = this.approving
			this.approving = null
			try {
				await approveReaction(reaction.id, note)
				showSuccess(t('decidesk', 'Reaction approved'))
				this.pending = this.pending.filter((r) => r.id !== reaction.id)
			} catch (e) {
				showError(t('decidesk', 'Could not approve the reaction'))
			}
		},
		async confirmReject(reason) {
			const reaction = this.rejecting
			this.rejecting = null
			try {
				await rejectReaction(reaction.id, reason)
				showSuccess(t('decidesk', 'Reaction rejected'))
				this.pending = this.pending.filter((r) => r.id !== reaction.id)
			} catch (e) {
				showError(t('decidesk', 'Could not reject the reaction'))
			}
		},
	},
}
</script>

<style scoped>
.moderation-queue {
	padding: 16px;
	max-width: 760px;
}

.moderation-queue__header {
	margin-bottom: 16px;
}

.moderation-queue__list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.moderation-queue__item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.moderation-queue__body {
	white-space: pre-wrap;
}

.moderation-queue__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin: 4px 0 8px;
}

.moderation-queue__actions {
	display: flex;
	gap: 8px;
}
</style>
