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
		<!-- Lifecycle timeline header (task-4.3) -->
		<template #header>
			<CnTimelineStages
				v-if="object.lifecycle"
				:stages="lifecycleStages"
				:current-stage="object.lifecycle"
				:aria-label="t('decidesk', 'Motion lifecycle')" />
		</template>

		<template #properties>
			<!-- Lifecycle action buttons (task-4.4) -->
			<CnDetailCard :title="t('decidesk', 'Actions')">
				<div class="decidesk-motion-actions">
					<NcButton
						v-if="canOpenDebate"
						type="secondary"
						:aria-label="t('decidesk', 'Open debate')"
						@click="transitionTo('debating')">
						{{ t('decidesk', 'Debat openen') }}
					</NcButton>
					<NcButton
						v-if="canOpenVoting"
						type="primary"
						:aria-label="t('decidesk', 'Open voting round')"
						@click="openVotingRound = true">
						{{ t('decidesk', 'Stemronde openen') }}
					</NcButton>
					<NcButton
						v-if="canWithdraw"
						type="error"
						:aria-label="t('decidesk', 'Withdraw motion')"
						@click="transitionTo('withdrawn')">
						{{ t('decidesk', 'Motie intrekken') }}
					</NcButton>
				</div>
			</CnDetailCard>

			<CnDetailCard :title="t('decidesk', 'Properties')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>

			<!-- Co-signatories section (task-4.5) -->
			<CnDetailCard :title="t('decidesk', 'Medeondertekenaars')">
				<div class="decidesk-cosigners">
					<ul v-if="object.coSigners && object.coSigners.length" class="decidesk-relations">
						<li v-for="(signer, idx) in object.coSigners" :key="idx">
							{{ signer }}
						</li>
					</ul>
					<p v-else class="decidesk-empty">
						{{ t('decidesk', 'Nog geen medeondertekenaars.') }}
					</p>
					<NcButton
						v-if="object.lifecycle === 'submitted' || object.lifecycle === 'debating'"
						type="secondary"
						:aria-label="t('decidesk', 'Invite co-signers')"
						@click="showCoSignDialog = true">
						{{ t('decidesk', 'Medeondertekenaars uitnodigen') }}
					</NcButton>
					<!-- Confirm own co-signature -->
					<NcButton
						v-if="canCoSign"
						type="primary"
						:aria-label="t('decidesk', 'Co-sign this motion')"
						@click="confirmCoSign">
						{{ t('decidesk', 'Ondersteunen') }}
					</NcButton>
				</div>
			</CnDetailCard>

			<!-- Budget impact section (task-4.6) -->
			<CnDetailCard
				v-if="budgetImpact"
				:title="t('decidesk', 'Budget impact')">
				<CnDetailGrid :items="budgetImpactItems" />
			</CnDetailCard>

			<NcButton
				v-if="object.motionType === 'amendment' && (object.lifecycle === 'submitted' || object.lifecycle === 'debating')"
				type="secondary"
				:aria-label="t('decidesk', 'Add budget impact')"
				class="decidesk-budget-toggle"
				@click="showBudgetDialog = true">
				{{ t('decidesk', 'Budget impact toevoegen') }}
			</NcButton>
		</template>

		<template #relations>
			<!-- Amendments list (task-4.2 / task-5.1) -->
			<AmendmentList
				:motion-id="id"
				:motion-lifecycle="object.lifecycle"
				:object-store="objectStore" />

			<!-- VotingRound panel (task-4.2 / task-6.1) -->
			<VotingRoundPanel
				:motion-id="id"
				:motion-lifecycle="object.lifecycle"
				:object-store="objectStore" />
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
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { useObjectStore } from '../store/store.js'
import AmendmentList from '../components/AmendmentList.vue'
import VotingRoundPanel from '../components/VotingRoundPanel.vue'

export default {
	name: 'MotionDetail',
	/**
	 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-4.2
	 */
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
			showCoSignDialog: false,
			showBudgetDialog: false,
			openVotingRound: false,
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
				{ id: 'adopted', label: this.t('decidesk', 'Aangenomen'), type: 'success' },
				{ id: 'rejected', label: this.t('decidesk', 'Verworpen'), type: 'error' },
				{ id: 'withdrawn', label: this.t('decidesk', 'Ingetrokken'), type: 'warning' },
			]
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
		budgetImpact() {
			return (this.object.notes ?? []).find(n => n.title === 'Budget impact') ?? null
		},
		budgetImpactItems() {
			if (!this.budgetImpact) return []
			try {
				const data = JSON.parse(this.budgetImpact.body)
				return [
					{ label: this.t('decidesk', 'Budget Line'), value: data.budgetLine },
					{ label: this.t('decidesk', 'Amount Delta'), value: `€ ${data.amountDelta}` },
					{ label: this.t('decidesk', 'Rationale'), value: data.rationale },
				]
			} catch {
				return []
			}
		},
		canOpenDebate() {
			return this.object.lifecycle === 'submitted'
		},
		canOpenVoting() {
			return this.object.lifecycle === 'debating'
		},
		canWithdraw() {
			return ['submitted', 'debating'].includes(this.object.lifecycle)
		},
		canCoSign() {
			// Show "Ondersteunen" for invited participants not yet confirmed.
			return ['submitted', 'debating'].includes(this.object.lifecycle)
		},
	},
	methods: {
		async transitionTo(newState) {
			try {
				const appBaseUrl = generateUrl('/apps/decidesk')
				const response = await fetch(`${appBaseUrl}/api/motions/${this.id}/transition`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ newState }),
				})
				if (response.ok) {
					this.objectStore.fetchObject('motion', this.id)
				}
			} catch (e) {
				console.error('Transition failed', e)
			}
		},
		async confirmCoSign() {
			try {
				const user = getCurrentUser()?.uid
				const appBaseUrl = generateUrl('/apps/decidesk')
				await fetch(`${appBaseUrl}/api/motions/${this.id}/co-sign-confirm`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ displayName: user }),
				})
				this.objectStore.fetchObject('motion', this.id)
			} catch (e) {
				console.error('Co-sign failed', e)
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

.decidesk-motion-actions {
	display: flex;
	gap: var(--default-grid-baseline);
	flex-wrap: wrap;
	margin-bottom: var(--default-grid-baseline);
}

.decidesk-cosigners {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.decidesk-budget-toggle {
	margin-top: var(--default-grid-baseline);
}
</style>
