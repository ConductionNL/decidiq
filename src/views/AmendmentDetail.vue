<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-5.2
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-5.3
-->
<template>
	<CnDetailPage
		:object="object"
		:loading="loading"
		:title="object.title || t('decidesk', 'Amendment')"
		:show-sidebar="true"
		@edit="editing = true"
		@delete="showDeleteDialog = true">
		<template #properties>
			<!-- Conflict detection warning -->
			<div v-if="hasConflict" class="decidesk-conflict-warning" role="alert">
				<span class="decidesk-conflict-warning__icon" aria-hidden="true">⚠</span>
				{{ t('decidesk', 'Possible conflict with another amendment — consult the clerk (Mogelijk conflict met ander amendement — raadpleeg de griffier)') }}
			</div>

			<!-- Lifecycle timeline -->
			<CnDetailCard :title="t('decidesk', 'Lifecycle')">
				<CnTimelineStages
					:stages="lifecycleStages"
					:current-stage="currentStageIndex"
					:aria-label="t('decidesk', 'Amendment lifecycle')" />
			</CnDetailCard>

			<!-- Lifecycle action buttons (chair/secretary) -->
			<CnDetailCard v-if="isChairOrSecretary" :title="t('decidesk', 'Actions')">
				<div class="decidesk-motion-actions">
					<NcButton
						v-if="object.lifecycle === 'submitted'"
						type="primary"
						:aria-label="t('decidesk', 'Open Debate')"
						@click="transitionTo('debating')">
						{{ t('decidesk', 'Debat openen') }}
					</NcButton>
					<NcButton
						v-if="object.lifecycle === 'debating'"
						type="primary"
						:aria-label="t('decidesk', 'Open Voting Round')"
						@click="transitionTo('voting')">
						{{ t('decidesk', 'Stemronde openen') }}
					</NcButton>
				</div>
			</CnDetailCard>

			<!-- Properties -->
			<CnDetailCard :title="t('decidesk', 'Properties')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>

			<!-- Parent Motion link -->
			<CnDetailCard :title="t('decidesk', 'Parent Motion')">
				<p v-if="!parentMotions.length" class="decidesk-empty">
					{{ t('decidesk', 'No parent motion linked.') }}
				</p>
				<ul v-else class="decidesk-relations">
					<li v-for="motion in parentMotions" :key="motion.id || motion">
						<router-link :to="{ name: 'MotionDetail', params: { id: motion.id || motion } }">
							{{ motion.title || motion.name || motion.id || motion }}
						</router-link>
					</li>
				</ul>
			</CnDetailCard>
		</template>

		<template #relations>
			<!-- Voting round panel for amendments -->
			<CnDetailCard :title="t('decidesk', 'Voting Round')">
				<VotingRoundPanel
					:motion-id="object.id"
					:motion-lifecycle="object.lifecycle"
					:current-participant-id="currentUserId"
					:current-role="currentRole"
					:member-count="memberCount" />
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
				:title="t('decidesk', 'Edit Amendment')"
				:object-store="objectStore"
				object-type="amendment"
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
import { useObjectStore } from '../store/store.js'
import VotingRoundPanel from '../components/VotingRoundPanel.vue'

/**
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-5.2
 */
export default {
	name: 'AmendmentDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		CnDetailGrid,
		CnObjectSidebar,
		CnSchemaFormDialog,
		CnDeleteDialog,
		CnTimelineStages,
		VotingRoundPanel,
	},
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const objectStore = useObjectStore()
		const detailView = useDetailView('amendment', props.id, {
			objectStore,
			listRouteName: 'AgendaItems',
			detailRouteName: 'AmendmentDetail',
		})
		return { ...detailView, objectStore }
	},
	data() {
		return {
			currentRole: 'member',
			currentUserId: '',
			memberCount: 0,
		}
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('amendment')
		},
		isChairOrSecretary() {
			return ['chair', 'vice-chair', 'secretary'].includes(this.currentRole)
		},
		hasConflict() {
			return (this.object.notes ?? []).some((n) => (n.title ?? '').startsWith('Conflict:'))
		},
		parentMotions() {
			return this.object.relations?.motion ?? []
		},
		lifecycleStages() {
			return [
				{ label: this.t('decidesk', 'Ingediend'), id: 'submitted' },
				{ label: this.t('decidesk', 'Debat'), id: 'debating' },
				{ label: this.t('decidesk', 'Stemronde'), id: 'voting' },
				{ label: this.t('decidesk', 'Aangenomen / Verworpen'), id: 'terminal', isTerminal: true },
			]
		},
		currentStageIndex() {
			const stageMap = { submitted: 0, debating: 1, voting: 2, adopted: 3, rejected: 3 }
			return stageMap[this.object.lifecycle] ?? 0
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Title'), value: this.object.title },
				{ label: this.t('decidesk', 'Proposer'), value: this.object.proposer },
				{ label: this.t('decidesk', 'Lifecycle'), value: this.object.lifecycle },
				{ label: this.t('decidesk', 'Submitted At'), value: this.object.submittedAt },
			]
		},
	},
	methods: {
		async transitionTo(newState) {
			try {
				const url = generateUrl(`/apps/decidesk/api/amendments/${this.object.id}/transition`)
				await axios.post(url, { newState })
				showSuccess(this.t('decidesk', 'Amendment status updated.'))
				await this.objectStore.fetchObject('amendment', this.object.id)
			} catch (e) {
				showError(e.response?.data?.message || e.message)
			}
		},
	},
}
</script>

<style scoped>
.decidesk-conflict-warning {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
	background: var(--color-warning-background);
	color: var(--color-warning);
	border-radius: var(--border-radius);
	margin-block-end: var(--default-grid-baseline);
	font-weight: var(--font-weight-bold);
}
.decidesk-conflict-warning__icon { font-size: 1.2em; }
.decidesk-motion-actions { display: flex; gap: var(--default-grid-baseline); flex-wrap: wrap; }
.decidesk-empty { color: var(--color-text-maxcontrast); }
.decidesk-relations { list-style: none; padding: 0; }
</style>
