<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-4
-->
<template>
	<CnDetailPage
		:object="object"
		:loading="loading"
		:title="object.title || t('decidesk', 'Motion')"
		:show-sidebar="true"
		@edit="editing = true"
		@delete="showDeleteDialog = true">
		<!-- Lifecycle timeline -->
		<template #header-extra>
			<CnTimelineStages
				:stages="lifecycleStages"
				:active-stage="object.lifecycle || 'submitted'" />
		</template>

		<template #properties>
			<CnDetailCard :title="t('decidesk', 'Properties')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>

			<!-- Lifecycle action buttons -->
			<CnDetailCard :title="t('decidesk', 'Actions')">
				<div class="decidesk-motion-actions">
					<NcButton
						v-if="canTransition('debating')"
						type="primary"
						:aria-label="t('decidesk', 'Open debate')"
						@click="transitionTo('debating')">
						{{ t('decidesk', 'Debat openen') }}
					</NcButton>
					<NcButton
						v-if="canTransition('voting')"
						type="primary"
						:aria-label="t('decidesk', 'Open voting round')"
						@click="transitionTo('voting')">
						{{ t('decidesk', 'Stemronde openen') }}
					</NcButton>
					<NcButton
						v-if="canTransition('withdrawn')"
						type="error"
						:aria-label="t('decidesk', 'Withdraw motion')"
						@click="transitionTo('withdrawn')">
						{{ t('decidesk', 'Motie intrekken') }}
					</NcButton>
				</div>
			</CnDetailCard>

			<!-- Co-signatories section -->
			<CnDetailCard :title="t('decidesk', 'Co-signatories')">
				<div class="decidesk-cosigners">
					<p v-if="!coSigners.length" class="decidesk-empty">
						{{ t('decidesk', 'No co-signatories yet.') }}
					</p>
					<ul v-else class="decidesk-cosigners-list" aria-label="Co-signatories">
						<li v-for="name in coSigners" :key="name">{{ name }}</li>
					</ul>
					<NcButton
						v-if="canCoSign"
						type="secondary"
						:aria-label="t('decidesk', 'Confirm co-signature')"
						@click="confirmCoSign">
						{{ t('decidesk', 'Ondersteunen') }}
					</NcButton>
					<NcButton
						v-if="canInviteCoSigners"
						type="secondary"
						:aria-label="t('decidesk', 'Invite co-signatories')"
						@click="showCoSignDialog = true">
						{{ t('decidesk', 'Medeondertekenaars uitnodigen') }}
					</NcButton>
				</div>
				<!-- Co-sign invite dialog -->
				<NcDialog
					v-if="showCoSignDialog"
					:name="t('decidesk', 'Invite co-signatories')"
					@closing="showCoSignDialog = false">
					<div class="decidesk-cosign-dialog">
						<label for="cosign-participants">{{ t('decidesk', 'Participant IDs (comma-separated)') }}</label>
						<NcInputField
							id="cosign-participants"
							v-model="coSignParticipantInput"
							:label="t('decidesk', 'Participant IDs')"
							:placeholder="t('decidesk', 'user1, user2, ...')" />
					</div>
					<template #actions>
						<NcButton type="primary" @click="sendCoSignRequests">
							{{ t('decidesk', 'Send invitations') }}
						</NcButton>
					</template>
				</NcDialog>
			</CnDetailCard>

			<!-- Budget impact section (amendment motions only) -->
			<CnDetailCard
				v-if="object.motionType === 'amendment' || budgetImpact"
				:title="t('decidesk', 'Budget impact')">
				<div v-if="budgetImpact" class="decidesk-budget-impact">
					<p><strong>{{ t('decidesk', 'Budget line') }}:</strong> {{ budgetImpact.budgetLine }}</p>
					<p><strong>{{ t('decidesk', 'Amount delta') }}:</strong> &euro; {{ budgetImpact.amountDelta }}</p>
					<p><strong>{{ t('decidesk', 'Rationale') }}:</strong> {{ budgetImpact.rationale }}</p>
				</div>
				<div v-else>
					<p class="decidesk-empty">{{ t('decidesk', 'No budget impact recorded.') }}</p>
				</div>
				<NcButton
					v-if="editing === false"
					type="secondary"
					:aria-label="t('decidesk', 'Add budget impact')"
					@click="showBudgetDialog = true">
					{{ t('decidesk', 'Budget impact toevoegen') }}
				</NcButton>
				<!-- Budget impact dialog -->
				<NcDialog
					v-if="showBudgetDialog"
					:name="t('decidesk', 'Budget impact')"
					@closing="showBudgetDialog = false">
					<div class="decidesk-budget-dialog">
						<NcInputField
							v-model="budgetForm.budgetLine"
							:label="t('decidesk', 'Budget line')"
							:placeholder="t('decidesk', 'e.g. 4.2 Jeugdzorg')" />
						<NcInputField
							v-model.number="budgetForm.amountDelta"
							type="number"
							:label="t('decidesk', 'Amount delta (€)')"
							:placeholder="t('decidesk', '0.00')" />
						<NcTextArea
							v-model="budgetForm.rationale"
							:label="t('decidesk', 'Rationale')"
							:placeholder="t('decidesk', 'Policy rationale')" />
					</div>
					<template #actions>
						<NcButton type="primary" @click="saveBudgetImpact">
							{{ t('decidesk', 'Save') }}
						</NcButton>
					</template>
				</NcDialog>
			</CnDetailCard>
		</template>

		<!-- Amendments list -->
		<template #relations>
			<AmendmentList :motion-id="object.id" :motion-lifecycle="object.lifecycle" />
			<VotingRoundPanel :motion-id="object.id" :meeting-id="object.meetingId" />
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
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, CnTimelineStages, useDetailView } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog, NcInputField, NcTextArea } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { useObjectStore } from '../store/store.js'
import AmendmentList from '../components/AmendmentList.vue'
import VotingRoundPanel from '../components/VotingRoundPanel.vue'

/**
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-4
 */
export default {
	name: 'MotionDetail',
	components: {
		CnDetailPage,
		CnDetailCard,
		CnDetailGrid,
		CnObjectSidebar,
		CnSchemaFormDialog,
		CnDeleteDialog,
		CnTimelineStages,
		NcButton,
		NcDialog,
		NcInputField,
		NcTextArea,
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
			listRouteName: 'MotionIndex',
			detailRouteName: 'MotionDetail',
		})
		return { ...detailView, objectStore }
	},
	data() {
		return {
			showCoSignDialog: false,
			showBudgetDialog: false,
			coSignParticipantInput: '',
			budgetForm: { budgetLine: '', amountDelta: 0, rationale: '' },
		}
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('motion')
		},
		lifecycleStages() {
			return [
				{ id: 'submitted', label: this.t('decidesk', 'Ingediend') },
				{ id: 'debating', label: this.t('decidesk', 'Debat') },
				{ id: 'voting', label: this.t('decidesk', 'Stemronde') },
				{
					id: 'terminal',
					label: this.terminalLabel,
					terminal: true,
					state: this.terminalState,
				},
			]
		},
		terminalLabel() {
			if (this.object.lifecycle === 'adopted') return this.t('decidesk', 'Aangenomen')
			if (this.object.lifecycle === 'rejected') return this.t('decidesk', 'Verworpen')
			if (this.object.lifecycle === 'withdrawn') return this.t('decidesk', 'Ingetrokken')
			return this.t('decidesk', 'Aangenomen / Verworpen')
		},
		terminalState() {
			if (this.object.lifecycle === 'adopted') return 'success'
			if (this.object.lifecycle === 'rejected') return 'error'
			if (this.object.lifecycle === 'withdrawn') return 'warning'
			return 'default'
		},
		coSigners() {
			return (this.object.coSigners ?? [])
		},
		budgetImpact() {
			const note = (this.object.notes ?? []).find(n => n.title === 'Budget impact')
			if (!note) return null
			try { return JSON.parse(note.body) } catch { return null }
		},
		canCoSign() {
			// Current user can confirm co-sign if they are not already a co-signer.
			const uid = window.OC?.currentUser ?? ''
			return uid && !this.coSigners.includes(uid)
		},
		canInviteCoSigners() {
			return ['submitted', 'debating'].includes(this.object.lifecycle)
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Title'), value: this.object.title },
				{ label: this.t('decidesk', 'Type'), value: this.object.motionType },
				{ label: this.t('decidesk', 'Proposer'), value: this.object.proposer },
				{ label: this.t('decidesk', 'Lifecycle'), value: this.object.lifecycle },
				{ label: this.t('decidesk', 'Submitted At'), value: this.object.submittedAt },
			]
		},
	},
	methods: {
		canTransition(state) {
			const transitions = {
				submitted: ['debating', 'withdrawn'],
				debating: ['voting', 'withdrawn'],
				voting: [],
			}
			return (transitions[this.object.lifecycle] ?? []).includes(state)
		},
		async transitionTo(state) {
			try {
				await axios.post(
					generateUrl(`/apps/decidesk/api/motions/${this.id}/transition`),
					{ newState: state }
				)
				await this.refresh()
			} catch (e) {
				console.error('Lifecycle transition failed', e)
			}
		},
		async confirmCoSign() {
			try {
				await axios.post(
					generateUrl(`/apps/decidesk/api/motions/${this.id}/co-sign-confirm`),
					{ displayName: window.OC?.currentUser ?? '' }
				)
				await this.refresh()
			} catch (e) {
				console.error('Co-sign confirmation failed', e)
			}
		},
		async sendCoSignRequests() {
			const ids = this.coSignParticipantInput.split(',').map(s => s.trim()).filter(Boolean)
			if (!ids.length) return
			try {
				await axios.post(
					generateUrl(`/apps/decidesk/api/motions/${this.id}/co-sign-request`),
					{ participantIds: ids }
				)
				this.showCoSignDialog = false
				this.coSignParticipantInput = ''
			} catch (e) {
				console.error('Co-sign request failed', e)
			}
		},
		async saveBudgetImpact() {
			try {
				await axios.post(
					generateUrl(`/apps/decidesk/api/motions/${this.id}/budget-impact`),
					this.budgetForm
				)
				this.showBudgetDialog = false
				await this.refresh()
			} catch (e) {
				console.error('Budget impact save failed', e)
			}
		},
	},
}
</script>

<style scoped>
.decidesk-motion-actions {
	display: flex;
	gap: var(--default-grid-baseline, 8px);
	flex-wrap: wrap;
}

.decidesk-cosigners-list {
	list-style: disc;
	padding-left: 1.5rem;
	margin-bottom: 0.5rem;
}

.decidesk-empty {
	color: var(--color-text-maxcontrast);
}

.decidesk-budget-impact p {
	margin: 0.25rem 0;
}

.decidesk-cosign-dialog,
.decidesk-budget-dialog {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 8px);
	padding: 1rem 0;
}
</style>
