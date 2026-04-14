<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Motion detail view — shows motion text, timeline, co-signers, amendments, and voting round.
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-4.2
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
			<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-4.3 -->
			<CnDetailCard :title="t('decidesk', 'Lifecycle')">
				<CnTimelineStages :stages="lifecycleStages" :current="object.lifecycle" />
				<!-- Lifecycle action buttons -->
				<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-4.4 -->
				<div class="decidesk-lifecycle-actions">
					<NcButton
						v-if="canTransitionTo('debating')"
						type="primary"
						:disabled="transitioning"
						@click="transition('debating')">
						{{ t('decidesk', 'Debat openen') }}
					</NcButton>
					<NcButton
						v-if="canTransitionTo('voting')"
						type="primary"
						:disabled="transitioning"
						@click="transition('voting')">
						{{ t('decidesk', 'Stemronde openen') }}
					</NcButton>
					<NcButton
						v-if="canTransitionTo('withdrawn')"
						type="error"
						:disabled="transitioning"
						@click="transition('withdrawn')">
						{{ t('decidesk', 'Motie intrekken') }}
					</NcButton>
				</div>
				<p v-if="transitionError" class="decidesk-error">
					{{ transitionError }}
				</p>
			</CnDetailCard>

			<!-- Motion properties -->
			<CnDetailCard :title="t('decidesk', 'Properties')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>

			<!-- Budget impact panel (shown when amendment type and note exists) -->
			<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-4.6 -->
			<CnDetailCard
				v-if="budgetImpact"
				:title="t('decidesk', 'Budget Impact')">
				<CnDetailGrid :items="budgetImpactItems" />
			</CnDetailCard>
		</template>

		<template #relations>
			<!-- Co-signatories section -->
			<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-4.5 -->
			<CnDetailCard :title="t('decidesk', 'Medeondertekenaars')">
				<ul v-if="object.coSigners && object.coSigners.length" class="decidesk-cosigners">
					<li v-for="signer in object.coSigners" :key="signer">
						{{ signer }}
					</li>
				</ul>
				<p v-else class="decidesk-empty">
					{{ t('decidesk', 'Nog geen medeondertekenaars.') }}
				</p>
				<NcButton
					v-if="!isNew"
					type="secondary"
					@click="showCoSignDialog = true">
					{{ t('decidesk', 'Medeondertekenaars uitnodigen') }}
				</NcButton>
				<NcButton
					v-if="canCoSign"
					type="primary"
					@click="confirmCoSign">
					{{ t('decidesk', 'Ondersteunen') }}
				</NcButton>
			</CnDetailCard>

			<!-- Amendments list -->
			<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-5.1 -->
			<AmendmentList
				v-if="!isNew"
				:motion-id="id"
				:motion-lifecycle="object.lifecycle" />

			<!-- Voting round panel -->
			<!-- @spec openspec/changes/p2-motion-and-voting/tasks.md#task-6.1 -->
			<VotingRoundPanel
				v-if="!isNew"
				:motion-id="id"
				:motion-lifecycle="object.lifecycle"
				:meeting-id="meetingId" />
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
import { NcButton } from '@nextcloud/vue'
import { useObjectStore } from '../store/store.js'
import AmendmentList from '../components/AmendmentList.vue'
import VotingRoundPanel from '../components/VotingRoundPanel.vue'

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
			showCoSignDialog: false,
			showDeleteDialog: false,
			transitioning: false,
			transitionError: null,
		}
	},
	computed: {
		isNew() {
			return this.id === 'new'
		},
		schema() {
			return this.objectStore.getSchema('motion')
		},
		lifecycleStages() {
			return [
				{ key: 'submitted', label: this.t('decidesk', 'Ingediend') },
				{ key: 'debating', label: this.t('decidesk', 'Debat') },
				{ key: 'voting', label: this.t('decidesk', 'Stemronde') },
				{ key: 'adopted', label: this.t('decidesk', 'Aangenomen'), type: 'success' },
				{ key: 'rejected', label: this.t('decidesk', 'Verworpen'), type: 'error' },
				{ key: 'withdrawn', label: this.t('decidesk', 'Ingetrokken'), type: 'warning' },
			]
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Title'), value: this.object.title },
				{ label: this.t('decidesk', 'Type'), value: this.object.motionType },
				{ label: this.t('decidesk', 'Proposer'), value: this.object.proposer },
				{ label: this.t('decidesk', 'Lifecycle'), value: this.object.lifecycle },
				{ label: this.t('decidesk', 'Submitted'), value: this.object.submittedAt },
				{ label: this.t('decidesk', 'Motion text'), value: this.object.text },
			]
		},
		budgetImpact() {
			if (!this.object.notes) return null
			const note = this.object.notes.find(n => n.title === 'Budget impact')
			if (!note) return null
			try {
				return JSON.parse(note.body)
			} catch {
				return null
			}
		},
		budgetImpactItems() {
			if (!this.budgetImpact) return []
			return [
				{ label: this.t('decidesk', 'Begrotingspost'), value: this.budgetImpact.budgetLine },
				{ label: this.t('decidesk', 'Bedrag delta'), value: `€ ${this.budgetImpact.amountDelta}` },
				{ label: this.t('decidesk', 'Rationale'), value: this.budgetImpact.rationale },
			]
		},
		meetingId() {
			const relations = this.object.relations || []
			const meetingRel = relations.find(r => r.schema === 'meeting' || r.type === 'meeting')
			return meetingRel?.id || ''
		},
		canCoSign() {
			const user = window.OC?.getCurrentUser?.()
			if (!user) return false
			// pendingCoSignerUids stores Nextcloud UIDs (set by MotionService::requestCoSignature).
			// Compare against user.uid, not displayName, to match the stored value correctly.
			const pending = this.object.pendingCoSignerUids || []
			const confirmed = this.object.coSigners || []
			return pending.includes(user.uid) && !confirmed.includes(user.uid)
		},
	},
	methods: {
		canTransitionTo(state) {
			const allowedFrom = {
				debating: ['submitted'],
				voting: ['debating'],
				withdrawn: ['submitted', 'debating'],
			}
			return (allowedFrom[state] || []).includes(this.object.lifecycle)
		},
		async transition(newState) {
			this.transitioning = true
			this.transitionError = null
			try {
				const response = await fetch(
					OC.generateUrl(`/apps/decidesk/api/motions/${this.id}/transition`),
					{
						method: 'POST',
						headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
						body: JSON.stringify({ newState }),
					},
				)
				if (!response.ok) {
					const data = await response.json()
					this.transitionError = data.message || this.t('decidesk', 'Transitie mislukt')
				} else {
					await this.objectStore.fetchObject('motion', this.id)
				}
			} catch (e) {
				this.transitionError = this.t('decidesk', 'Transitie mislukt')
			} finally {
				this.transitioning = false
			}
		},
		async confirmCoSign() {
			try {
				await fetch(
					OC.generateUrl(`/apps/decidesk/api/motions/${this.id}/co-sign-confirm`),
					{
						method: 'POST',
						headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
						body: JSON.stringify({}),
					},
				)
				await this.objectStore.fetchObject('motion', this.id)
			} catch (e) {
				// ignore
			}
		},
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('motion', this.id)
		},
	},
}
</script>

<style scoped>
.decidesk-lifecycle-actions {
	display: flex;
	gap: var(--default-grid-baseline);
	flex-wrap: wrap;
	margin-top: var(--default-grid-baseline);
}

.decidesk-empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.decidesk-cosigners {
	list-style: none;
	margin: 0;
	padding: 0;
}

.decidesk-cosigners li {
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.decidesk-cosigners li:last-child {
	border-bottom: none;
}

.decidesk-error {
	color: var(--color-error);
	margin: var(--default-grid-baseline) 0 0;
}
</style>
