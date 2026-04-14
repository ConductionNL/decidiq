<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-5
-->
<template>
	<CnDetailPage
		:object="object"
		:loading="loading"
		:title="object.title || t('decidesk', 'Amendment')"
		:show-sidebar="true"
		@edit="editing = true"
		@delete="showDeleteDialog = true">
		<!-- Lifecycle timeline (mirrors Motion) -->
		<template #header-extra>
			<CnTimelineStages
				:stages="lifecycleStages"
				:active-stage="object.lifecycle || 'submitted'" />
		</template>

		<template #properties>
			<!-- Conflict detection notice -->
			<div
				v-if="conflictNote"
				class="decidesk-conflict-banner"
				role="alert"
				aria-live="polite">
				<span class="decidesk-conflict-icon" aria-hidden="true">⚠</span>
				{{ t('decidesk', 'Mogelijk conflict met ander amendement — raadpleeg de griffier') }}
			</div>

			<CnDetailCard :title="t('decidesk', 'Properties')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>

			<!-- Parent motion link -->
			<CnDetailCard :title="t('decidesk', 'Parent Motion')">
				<router-link
					v-if="object.motionId"
					:to="{ name: 'MotionDetail', params: { id: object.motionId } }">
					{{ t('decidesk', 'View parent motion') }}
				</router-link>
				<p v-else class="decidesk-empty">
					{{ t('decidesk', 'No parent motion linked.') }}
				</p>
			</CnDetailCard>

			<!-- Lifecycle action buttons (chair) -->
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
				</div>
			</CnDetailCard>
		</template>

		<template #relations>
			<VotingRoundPanel
				v-if="object.id"
				:motion-id="object.id"
				:meeting-id="object.meetingId"
				motion-type="amendment" />
		</template>

		<template #sidebar>
			<CnObjectSidebar :object="object" :loading="loading" :tabs="['audit']" />
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
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, CnTimelineStages, useDetailView } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { useObjectStore } from '../store/store.js'
import VotingRoundPanel from '../components/VotingRoundPanel.vue'

/**
 * @spec openspec/changes/p2-motion-and-voting/tasks.md#task-5
 */
export default {
	name: 'AmendmentDetail',
	components: {
		CnDetailPage,
		CnDetailCard,
		CnDetailGrid,
		CnObjectSidebar,
		CnSchemaFormDialog,
		CnDeleteDialog,
		CnTimelineStages,
		NcButton,
		VotingRoundPanel,
	},
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const objectStore = useObjectStore()
		const detailView = useDetailView('amendment', props.id, {
			objectStore,
			listRouteName: 'MotionIndex',
			detailRouteName: 'AmendmentDetail',
		})
		return { ...detailView, objectStore }
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('amendment')
		},
		lifecycleStages() {
			return [
				{ id: 'submitted', label: this.t('decidesk', 'Ingediend') },
				{ id: 'debating', label: this.t('decidesk', 'Debat') },
				{ id: 'voting', label: this.t('decidesk', 'Stemronde') },
				{ id: 'terminal', label: this.terminalLabel, terminal: true, state: this.terminalState },
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
		conflictNote() {
			return (this.object.notes ?? []).find(n => (n.title ?? '').startsWith('Conflict:'))
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
					generateUrl(`/apps/decidesk/api/amendments/${this.id}/transition`),
					{ newState: state }
				)
				await this.refresh()
			} catch (e) {
				console.error('Lifecycle transition failed', e)
			}
		},
	},
}
</script>

<style scoped>
.decidesk-conflict-banner {
	background: var(--color-warning);
	color: var(--color-main-text);
	border: 1px solid var(--color-warning-border, #e9b400);
	border-radius: var(--border-radius);
	padding: 0.75rem 1rem;
	margin-bottom: 1rem;
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.decidesk-motion-actions {
	display: flex;
	gap: var(--default-grid-baseline, 8px);
	flex-wrap: wrap;
}

.decidesk-empty {
	color: var(--color-text-maxcontrast);
}
</style>
