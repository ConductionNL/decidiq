<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-7
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
			<CnDetailCard :title="t('decidesk', 'Status')">
				<div class="decidesk-action-buttons">
					<NcButton
						v-if="object.taskStatus === 'open'"
						type="secondary"
						@click="updateStatus('in-progress')">
						{{ t('decidesk', 'In behandeling nemen') }}
					</NcButton>
					<NcButton
						v-if="object.taskStatus === 'in-progress'"
						type="primary"
						@click="updateStatus('completed')">
						{{ t('decidesk', 'Afronden') }}
					</NcButton>
				</div>
			</CnDetailCard>

			<CnDetailCard :title="t('decidesk', 'Eigenschappen')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>

			<CnDetailCard v-if="object.description" :title="t('decidesk', 'Omschrijving')">
				<p>{{ object.description }}</p>
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
import { NcButton } from '@nextcloud/vue'
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog, useDetailView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/store.js'

export default {
	name: 'ActionItemDetail',
	components: { NcButton, CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog },
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
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Status'), value: this.statusLabel },
				{ label: this.t('decidesk', 'Verantwoordelijke'), value: this.object.assignee },
				{ label: this.t('decidesk', 'Deadline'), value: this.formatDate(this.object.dueDate) },
				{ label: this.t('decidesk', 'Afgerond op'), value: this.formatDate(this.object.completedAt) },
			].filter((item) => item.value)
		},
		statusLabel() {
			const labels = {
				open: this.t('decidesk', 'Open'),
				'in-progress': this.t('decidesk', 'In behandeling'),
				completed: this.t('decidesk', 'Afgerond'),
				overdue: this.t('decidesk', 'Verlopen'),
			}
			return labels[this.object.taskStatus] || this.object.taskStatus
		},
	},
	methods: {
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('action-item', this.id)
		},
		async updateStatus(newStatus) {
			await this.objectStore.saveObject('action-item', {
				...this.object,
				taskStatus: newStatus,
				...(newStatus === 'completed' ? { completedAt: new Date().toISOString() } : {}),
			})
			this.objectStore.fetchObject('action-item', this.id)
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('nl-NL')
		},
	},
}
</script>

<style scoped>
.decidesk-action-buttons {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}
</style>
