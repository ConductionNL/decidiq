<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-7.2
-->
<template>
	<CnDetailPage
		:object="object"
		:loading="loading"
		:title="object.title || t('decidesk', 'Actiepunt')"
		:show-sidebar="true"
		@edit="editing = true"
		@delete="showDeleteDialog = true">
		<template #properties>
			<CnDetailCard :title="t('decidesk', 'Eigenschappen')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>

			<!-- Status transition buttons -->
			<CnDetailCard :title="t('decidesk', 'Status bijwerken')">
				<div class="decidesk-status-actions">
					<NcButton
						v-if="object.taskStatus === 'open'"
						type="secondary"
						@click="transitionStatus('in-progress')">
						{{ t('decidesk', 'In behandeling') }}
					</NcButton>
					<NcButton
						v-if="object.taskStatus === 'in-progress'"
						type="primary"
						@click="completeActionItem">
						{{ t('decidesk', 'Afgerond') }}
					</NcButton>
				</div>
			</CnDetailCard>

			<!-- Related Decision -->
			<CnDetailCard v-if="relatedDecision" :title="t('decidesk', 'Gekoppeld besluit')">
				<router-link :to="{ name: 'DecisionDetail', params: { id: relatedDecision.id || relatedDecision } }">
					{{ relatedDecision.title || relatedDecision.name || relatedDecision.id || relatedDecision }}
				</router-link>
			</CnDetailCard>

			<!-- Related Meeting -->
			<CnDetailCard v-if="relatedMeeting" :title="t('decidesk', 'Gekoppelde vergadering')">
				<router-link :to="{ name: 'MeetingDetail', params: { id: relatedMeeting.id || relatedMeeting } }">
					{{ relatedMeeting.title || relatedMeeting.name || relatedMeeting.id || relatedMeeting }}
				</router-link>
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
				:title="t('decidesk', 'Actiepunt bewerken')"
				:object-store="objectStore"
				object-type="action-item"
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
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, useDetailView } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import { useObjectStore } from '../store/store.js'

/**
 * Action item detail view with status transitions and related entity links.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-7.2
 */
export default {
	name: 'ActionItemDetail',
	components: {
		CnDetailPage,
		CnDetailCard,
		CnDetailGrid,
		CnObjectSidebar,
		CnSchemaFormDialog,
		CnDeleteDialog,
		NcButton,
	},
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const objectStore = useObjectStore()
		const detailView = useDetailView('action-item', props.id, {
			objectStore,
			listRouteName: 'ActionItems',
			detailRouteName: 'ActionItemDetail',
		})
		return { ...detailView, objectStore }
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('action-item')
		},
		relatedDecision() {
			return this.object.relations?.decision
				?? this.object.decision
				?? null
		},
		relatedMeeting() {
			return this.object.relations?.meeting
				?? this.object.meeting
				?? null
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Titel'), value: this.object.title },
				{ label: this.t('decidesk', 'Omschrijving'), value: this.object.description },
				{ label: this.t('decidesk', 'Verantwoordelijke'), value: this.object.assignee },
				{ label: this.t('decidesk', 'Deadline'), value: this.object.dueDate },
				{ label: this.t('decidesk', 'Status'), value: this.object.taskStatus },
				{ label: this.t('decidesk', 'Afgerond op'), value: this.object.completedAt },
			]
		},
	},
	methods: {
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('action-item', this.id)
		},
		/**
		 * Transition the action item to the given status.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-7.2
		 */
		async transitionStatus(newStatus) {
			try {
				await this.objectStore.saveObject('action-item', this.id, {
					...this.object,
					taskStatus: newStatus,
				})
				await this.objectStore.fetchObject('action-item', this.id)
			} catch (error) {
				console.error('Failed to transition action item status:', error)
			}
		},
		/**
		 * Mark the action item as completed and set completedAt to now.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-7.2
		 */
		async completeActionItem() {
			const now = new Date().toISOString()
			try {
				await this.objectStore.saveObject('action-item', this.id, {
					...this.object,
					taskStatus: 'completed',
					completedAt: now,
				})
				await this.objectStore.fetchObject('action-item', this.id)
			} catch (error) {
				console.error('Failed to complete action item:', error)
			}
		},
	},
}
</script>

<style scoped>
.decidesk-status-actions {
	display: flex;
	gap: var(--default-grid-baseline);
	flex-wrap: wrap;
}
</style>
