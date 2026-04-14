<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
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
		<!-- Timeline: lifecycle stages -->
		<template #header-extra>
			<CnTimelineStages
				:stages="lifecycleStages"
				:current="object.lifecycle || 'submitted'" />
		</template>

		<template #properties>
			<CnDetailCard :title="t('decidesk', 'Motion Details')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>

			<!-- Co-signers -->
			<CnDetailCard :title="t('decidesk', 'Co-Signatories')">
				<p v-if="!object.coSigners || !object.coSigners.length" class="decidesk-empty">
					{{ t('decidesk', 'No co-signatories yet.') }}
				</p>
				<ul v-else class="decidesk-relations">
					<li v-for="name in object.coSigners" :key="name">
						{{ name }}
					</li>
				</ul>
				<div class="decidesk-actions">
					<NcButton
						v-if="canManageCoSign"
						type="secondary"
						@click="showCoSignDialog = true">
						{{ t('decidesk', 'Invite Co-Signatories') }}
					</NcButton>
					<NcButton
						v-if="canCoSign"
						type="primary"
						@click="confirmCoSign">
						{{ t('decidesk', 'Support this motion') }}
					</NcButton>
				</div>
			</CnDetailCard>

			<!-- Budget Impact (shown if note present and motionType is amendment) -->
			<CnDetailCard
				v-if="budgetImpact"
				:title="t('decidesk', 'Budget Impact')">
				<CnDetailGrid :items="budgetImpactItems" />
			</CnDetailCard>

			<!-- Lifecycle action buttons -->
			<CnDetailCard :title="t('decidesk', 'Actions')">
				<div class="decidesk-actions">
					<NcButton
						v-if="canTransitionTo('debating')"
						type="secondary"
						:disabled="transitioning"
						@click="transition('debating')">
						{{ t('decidesk', 'Open Debate') }}
					</NcButton>
					<NcButton
						v-if="canTransitionTo('voting')"
						type="secondary"
						:disabled="transitioning"
						@click="showVotingDialog = true">
						{{ t('decidesk', 'Open Voting Round') }}
					</NcButton>
					<NcButton
						v-if="canTransitionTo('withdrawn')"
						type="error"
						:disabled="transitioning"
						@click="transition('withdrawn')">
						{{ t('decidesk', 'Withdraw Motion') }}
					</NcButton>
				</div>
			</CnDetailCard>
		</template>

		<template #relations>
			<!-- Amendments list -->
			<AmendmentList
				:motion-id="id"
				:motion-lifecycle="object.lifecycle" />

			<!-- Voting round panel -->
			<VotingRoundPanel
				:motion-id="id"
				:motion-lifecycle="object.lifecycle" />
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
import { NcButton } from '@nextcloud/vue'
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, CnTimelineStages, useDetailView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/store.js'
import AmendmentList from '../components/AmendmentList.vue'
import VotingRoundPanel from '../components/VotingRoundPanel.vue'

export default {
	name: 'MotionDetail',
	components: {
		NcButton,
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
			transitioning: false,
			showCoSignDialog: false,
			showVotingDialog: false,
		}
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('motion')
		},
		lifecycleStages() {
			return [
				{ id: 'submitted', label: this.t('decidesk', 'Submitted') },
				{ id: 'debating', label: this.t('decidesk', 'Debate') },
				{ id: 'voting', label: this.t('decidesk', 'Voting') },
				{
					id: 'final',
					label: this.t('decidesk', 'Result'),
					terminal: true,
					variants: [
						{ id: 'adopted', label: this.t('decidesk', 'Adopted'), type: 'success' },
						{ id: 'rejected', label: this.t('decidesk', 'Rejected'), type: 'error' },
						{ id: 'withdrawn', label: this.t('decidesk', 'Withdrawn'), type: 'warning' },
					],
				},
			]
		},
		budgetImpact() {
			const notes = this.object.notes ?? []
			return notes.find(n => n.title === 'Budget impact') ?? null
		},
		budgetImpactItems() {
			if (!this.budgetImpact) return []
			let parsed = {}
			try { parsed = JSON.parse(this.budgetImpact.body ?? '{}') } catch { /* ignore */ }
			return [
				{ label: this.t('decidesk', 'Budget Line'), value: parsed.budgetLine ?? '' },
				{ label: this.t('decidesk', 'Amount Delta (€)'), value: parsed.amountDelta ?? '' },
				{ label: this.t('decidesk', 'Rationale'), value: parsed.rationale ?? '' },
			]
		},
		canManageCoSign() {
			return ['submitted', 'debating'].includes(this.object.lifecycle)
		},
		canCoSign() {
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
		canTransitionTo(state) {
			const allowed = {
				submitted: ['debating', 'withdrawn'],
				debating: ['voting', 'withdrawn'],
				voting: ['withdrawn'],
			}
			return (allowed[this.object.lifecycle] ?? []).includes(state)
		},
		async transition(newState) {
			this.transitioning = true
			try {
				await fetch(`/index.php/apps/decidesk/api/motions/${this.id}/transition`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
					body: JSON.stringify({ newState }),
				})
				await this.objectStore.fetchObject('motion', this.id)
			} finally {
				this.transitioning = false
			}
		},
		async confirmCoSign() {
			try {
				await fetch(`/index.php/apps/decidesk/api/motions/${this.id}/co-sign-confirm`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
					body: JSON.stringify({}),
				})
				await this.objectStore.fetchObject('motion', this.id)
			} catch { /* ignore */ }
		},
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('motion', this.id)
		},
	},
}
</script>

<style scoped>
.decidesk-empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.decidesk-relations {
	list-style: none;
	margin: 0;
	padding: 0;
}

.decidesk-relations li {
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.decidesk-relations li:last-child {
	border-bottom: none;
}

.decidesk-actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
	margin-top: var(--default-grid-baseline);
}
</style>
