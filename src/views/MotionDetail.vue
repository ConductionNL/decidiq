<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-4.2
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-4.3
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-4.4
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-4.5
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-4.6
-->
<template>
	<CnDetailPage
		:object="object"
		:loading="loading"
		:title="object.title || t('decidesk', 'Motion')"
		:show-sidebar="true"
		@edit="editing = true"
		@delete="showDeleteDialog = true">
		<template #properties>
			<!-- Lifecycle timeline -->
			<CnDetailCard :title="t('decidesk', 'Lifecycle')">
				<CnTimelineStages
					:stages="lifecycleStages"
					:current-stage="currentStageIndex"
					:aria-label="t('decidesk', 'Motion lifecycle')" />
			</CnDetailCard>

			<!-- Lifecycle action buttons -->
			<CnDetailCard v-if="showLifecycleActions" :title="t('decidesk', 'Actions')">
				<div class="decidesk-motion-actions">
					<NcButton
						v-if="canOpenDebate"
						type="primary"
						:aria-label="t('decidesk', 'Open Debate')"
						@click="transitionTo('debating')">
						{{ t('decidesk', 'Debat openen') }}
					</NcButton>
					<NcButton
						v-if="canOpenVoting"
						type="primary"
						:aria-label="t('decidesk', 'Open Voting Round')"
						@click="transitionTo('voting')">
						{{ t('decidesk', 'Stemronde openen') }}
					</NcButton>
					<NcButton
						v-if="canWithdraw"
						type="error"
						:aria-label="t('decidesk', 'Withdraw Motion')"
						@click="transitionTo('withdrawn')">
						{{ t('decidesk', 'Motie intrekken') }}
					</NcButton>
					<p v-if="transitionError" class="decidesk-error" role="alert">{{ transitionError }}</p>
				</div>
			</CnDetailCard>

			<!-- Motion properties -->
			<CnDetailCard :title="t('decidesk', 'Properties')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>

			<!-- Co-signatories section -->
			<CnDetailCard :title="t('decidesk', 'Co-Signatories')">
				<div class="decidesk-cosign">
					<ul v-if="object.coSigners && object.coSigners.length" class="decidesk-cosign__list">
						<li v-for="(signer, i) in object.coSigners" :key="i">{{ signer }}</li>
					</ul>
					<p v-else class="decidesk-empty">{{ t('decidesk', 'No co-signatories yet.') }}</p>

					<!-- Invite section (chair/secretary) -->
					<div v-if="isChairOrSecretary" class="decidesk-cosign__invite">
						<NcTextField
							v-model="coSignInviteIds"
							:label="t('decidesk', 'Participant IDs (comma-separated)')"
							:aria-label="t('decidesk', 'Participant IDs to invite for co-signature')" />
						<NcButton
							type="secondary"
							:aria-label="t('decidesk', 'Send Co-Signature Invitation')"
							@click="requestCoSignature">
							{{ t('decidesk', 'Medeondertekenaars uitnodigen') }}
						</NcButton>
					</div>

					<!-- Confirm co-signature (non-chair members) -->
					<NcButton
						v-if="!isChairOrSecretary && canConfirmCoSign"
						type="secondary"
						:aria-label="t('decidesk', 'Confirm Co-Signature')"
						@click="confirmCoSignature">
						{{ t('decidesk', 'Ondersteunen') }}
					</NcButton>
				</div>
			</CnDetailCard>

			<!-- Budget impact panel (for amendment motion type) -->
			<CnDetailCard v-if="hasBudgetImpact || object.motionType === 'amendment'" :title="t('decidesk', 'Budget Impact')">
				<div v-if="budgetImpact" class="decidesk-budget-impact">
					<dl>
						<dt>{{ t('decidesk', 'Budget Line') }}</dt>
						<dd>{{ budgetImpact.budgetLine }}</dd>
						<dt>{{ t('decidesk', 'Amount Delta') }}</dt>
						<dd>€ {{ budgetImpact.amountDelta.toLocaleString('nl-NL') }}</dd>
						<dt>{{ t('decidesk', 'Rationale') }}</dt>
						<dd>{{ budgetImpact.rationale }}</dd>
					</dl>
				</div>
				<div v-if="object.motionType === 'amendment'" class="decidesk-budget-impact__form">
					<NcTextField
						v-model="budgetForm.budgetLine"
						:label="t('decidesk', 'Budget Line')"
						:aria-label="t('decidesk', 'Budget line reference')" />
					<NcTextField
						v-model="budgetForm.amountDelta"
						type="number"
						:label="t('decidesk', 'Amount Delta (EUR)')"
						:aria-label="t('decidesk', 'Financial impact in euros')" />
					<NcTextField
						v-model="budgetForm.rationale"
						:label="t('decidesk', 'Rationale')"
						:aria-label="t('decidesk', 'Policy rationale')" />
					<NcButton
						type="secondary"
						:aria-label="t('decidesk', 'Save Budget Impact')"
						@click="saveBudgetImpact">
						{{ t('decidesk', 'Budget impact toevoegen') }}
					</NcButton>
				</div>
			</CnDetailCard>
		</template>

		<template #relations>
			<!-- Amendments list -->
			<AmendmentList
				:motion-id="object.id"
				:motion-lifecycle="object.lifecycle"
				:current-role="currentRole"
				@amendment-created="reload" />

			<!-- Voting round panel -->
			<CnDetailCard :title="t('decidesk', 'Voting Round')">
				<VotingRoundPanel
					:motion-id="object.id"
					:motion-lifecycle="object.lifecycle"
					:current-participant-id="currentUserId"
					:current-role="currentRole"
					:member-count="memberCount"
					@round-opened="reload"
					@round-closed="reload" />
			</CnDetailCard>
		</template>

		<template #sidebar>
			<CnObjectSidebar :object="object" :loading="loading" />
		</template>

		<template #edit-dialog>
			<CnSchemaFormDialog
				v-if="editing"
				:schema="schema"
				:object="object"
				:title="t('decidesk', 'Edit Motion')"
				:object-store="objectStore"
				object-type="motion"
				@close="editing = false"
				@saved="onEditSaved" />
		</template>

		<template #delete-dialog>
			<CnDeleteDialog
				v-if="showDeleteDialog"
				:object-name="object.title || ''"
				@confirm="confirmDelete"
				@close="showDeleteDialog = false" />
		</template>
	</CnDetailPage>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { NcButton, NcTextField } from '@nextcloud/vue'
import {
	CnDetailPage,
	CnDetailCard,
	CnDetailGrid,
	CnObjectSidebar,
	CnSchemaFormDialog,
	CnDeleteDialog,
	CnTimelineStages,
	useDetailView,
} from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/store.js'
import AmendmentList from '../components/AmendmentList.vue'
import VotingRoundPanel from '../components/VotingRoundPanel.vue'

/**
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-4.2
 */
export default {
	name: 'MotionDetail',
	components: {
		NcButton,
		NcTextField,
		CnDetailPage,
		CnDetailCard,
		CnDetailGrid,
		CnObjectSidebar,
		CnSchemaFormDialog,
		CnDeleteDialog,
		CnTimelineStages,
		AmendmentList,
		VotingRoundPanel,
	},
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const objectStore = useObjectStore()
		const detailView = useDetailView('motion', props.id, {
			objectStore,
			listRouteName: 'Motions',
			detailRouteName: 'MotionDetail',
		})
		return { ...detailView, objectStore }
	},
	data() {
		return {
			transitionError: null,
			coSignInviteIds: '',
			canConfirmCoSign: false,
			memberCount: 0,
			currentRole: 'member',
			currentUserId: '',
			budgetForm: { budgetLine: '', amountDelta: 0, rationale: '' },
		}
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('motion')
		},
		lifecycleStages() {
			return [
				{
					label: this.t('decidesk', 'Ingediend'),
					id: 'submitted',
					icon: 'FileDocumentOutline',
				},
				{
					label: this.t('decidesk', 'Debat'),
					id: 'debating',
					icon: 'Forum',
				},
				{
					label: this.t('decidesk', 'Stemronde'),
					id: 'voting',
					icon: 'BallotOutline',
				},
				{
					label: this.t('decidesk', 'Aangenomen / Verworpen / Ingetrokken'),
					id: 'terminal',
					icon: 'CheckCircle',
					isTerminal: true,
				},
			]
		},
		currentStageIndex() {
			const lifecycle = this.object.lifecycle || 'submitted'
			const stageMap = { submitted: 0, debating: 1, voting: 2, adopted: 3, rejected: 3, withdrawn: 3 }
			return stageMap[lifecycle] ?? 0
		},
		isChairOrSecretary() {
			return ['chair', 'vice-chair', 'secretary'].includes(this.currentRole)
		},
		showLifecycleActions() {
			return this.isChairOrSecretary
				|| (this.object.proposer && this.object.lifecycle !== 'adopted' && this.object.lifecycle !== 'rejected')
		},
		canOpenDebate() {
			return this.isChairOrSecretary && this.object.lifecycle === 'submitted'
		},
		canOpenVoting() {
			return this.isChairOrSecretary && this.object.lifecycle === 'debating'
		},
		canWithdraw() {
			return ['submitted', 'debating'].includes(this.object.lifecycle)
		},
		hasBudgetImpact() {
			return (this.object.notes ?? []).some((n) => n.title === 'Budget impact')
		},
		budgetImpact() {
			const note = (this.object.notes ?? []).find((n) => n.title === 'Budget impact')
			if (!note) return null
			try {
				return JSON.parse(note.body)
			} catch {
				return null
			}
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Title'), value: this.object.title },
				{ label: this.t('decidesk', 'Motion Type'), value: this.object.motionType },
				{ label: this.t('decidesk', 'Proposer'), value: this.object.proposer },
				{ label: this.t('decidesk', 'Lifecycle'), value: this.lifecycleLabel(this.object.lifecycle) },
				{ label: this.t('decidesk', 'Submitted At'), value: this.object.submittedAt },
			]
		},
	},
	methods: {
		async transitionTo(newState) {
			this.transitionError = null
			try {
				const url = generateUrl(`/apps/decidesk/api/motions/${this.object.id}/transition`)
				await axios.post(url, { newState })
				showSuccess(this.t('decidesk', 'Motion status updated.'))
				await this.reload()
			} catch (e) {
				this.transitionError = e.response?.data?.message || e.message
				showError(this.transitionError)
			}
		},
		async requestCoSignature() {
			const ids = this.coSignInviteIds.split(',').map((s) => s.trim()).filter(Boolean)
			try {
				const url = generateUrl(`/apps/decidesk/api/motions/${this.object.id}/co-sign-request`)
				await axios.post(url, { participantIds: ids })
				showSuccess(this.t('decidesk', 'Invitations sent.'))
				this.coSignInviteIds = ''
			} catch (e) {
				showError(e.response?.data?.message || e.message)
			}
		},
		async confirmCoSignature() {
			try {
				const url = generateUrl(`/apps/decidesk/api/motions/${this.object.id}/co-sign-confirm`)
				await axios.post(url, { displayName: this.currentUserId })
				showSuccess(this.t('decidesk', 'Co-signature confirmed.'))
				await this.reload()
			} catch (e) {
				showError(e.response?.data?.message || e.message)
			}
		},
		async saveBudgetImpact() {
			try {
				const url = generateUrl(`/apps/decidesk/api/motions/${this.object.id}/budget-impact`)
				await axios.post(url, this.budgetForm)
				showSuccess(this.t('decidesk', 'Budget impact saved.'))
				await this.reload()
			} catch (e) {
				showError(e.response?.data?.message || e.message)
			}
		},
		async reload() {
			await this.objectStore.fetchObject('motion', this.object.id)
		},
		lifecycleLabel(lifecycle) {
			const labels = {
				submitted: this.t('decidesk', 'Ingediend'),
				debating: this.t('decidesk', 'Debat'),
				voting: this.t('decidesk', 'Stemronde'),
				adopted: this.t('decidesk', 'Aangenomen'),
				rejected: this.t('decidesk', 'Verworpen'),
				withdrawn: this.t('decidesk', 'Ingetrokken'),
			}
			return labels[lifecycle] || lifecycle
		},
	},
}
</script>

<style scoped>
.decidesk-motion-actions { display: flex; gap: var(--default-grid-baseline); flex-wrap: wrap; }
.decidesk-cosign__list { list-style: none; padding: 0; margin-block-end: var(--default-grid-baseline); }
.decidesk-cosign__invite { display: flex; flex-direction: column; gap: var(--default-grid-baseline); margin-block-start: var(--default-grid-baseline); }
.decidesk-budget-impact dl { display: grid; grid-template-columns: auto 1fr; gap: 4px var(--default-grid-baseline); }
.decidesk-budget-impact__form { display: flex; flex-direction: column; gap: var(--default-grid-baseline); margin-block-start: var(--default-grid-baseline); }
.decidesk-empty { color: var(--color-text-maxcontrast); }
.decidesk-error { color: var(--color-error); }
</style>
