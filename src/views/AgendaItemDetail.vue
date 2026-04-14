<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-9.2
-->
<template>
	<CnDetailPage
		:object="object"
		:loading="loading"
		:title="object.title || t('decidesk', 'Agenda Item')"
		:show-sidebar="true"
		@edit="editing = true"
		@delete="showDeleteDialog = true">
		<template #properties>
			<CnDetailCard :title="t('decidesk', 'Properties')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>
		</template>

		<template #relations>
			<CnDetailCard :title="t('decidesk', 'Linked Meeting')">
				<p v-if="!object.relations?.meeting?.length" class="decidesk-empty">
					{{ t('decidesk', 'No linked meeting.') }}
				</p>
				<ul v-else class="decidesk-relations">
					<li v-for="meeting in object.relations.meeting" :key="meeting.id || meeting">
						<router-link :to="{ name: 'MeetingDetail', params: { id: meeting.id || meeting } }">
							{{ meeting.title || meeting.name || meeting.id || meeting }}
						</router-link>
					</li>
				</ul>
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
				:title="t('decidesk', 'Edit Agenda Item')"
				:object-store="objectStore"
				object-type="agenda-item"
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
import { useObjectStore } from '../store/store.js'

export default {
	name: 'AgendaItemDetail',
	components: { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnSchemaFormDialog, CnDeleteDialog },
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const objectStore = useObjectStore()
		const detailView = useDetailView('agenda-item', props.id, {
			objectStore,
			listRouteName: 'AgendaItems',
			detailRouteName: 'AgendaItemDetail',
		})
		return { ...detailView, objectStore }
	},
	computed: {
		schema() {
			return this.objectStore.getSchema('agenda-item')
		},
		propertyItems() {
			return [
				{ label: this.t('decidesk', 'Title'), value: this.object.title },
				{ label: this.t('decidesk', 'Type'), value: this.object.itemType },
				{ label: this.t('decidesk', 'Order'), value: this.object.orderNumber },
				{ label: this.t('decidesk', 'Estimated Duration'), value: this.object.estimatedDuration ? `${this.object.estimatedDuration} min` : '' },
				{ label: this.t('decidesk', 'Actual Duration'), value: this.object.actualDuration ? `${this.object.actualDuration} min` : '' },
				{ label: this.t('decidesk', 'Description'), value: this.object.description },
				{ label: this.t('decidesk', 'Recurring'), value: this.object.isRecurring ? this.t('decidesk', 'Yes') : this.t('decidesk', 'No') },
			]
		},
	},
	methods: {
		onEditSaved() {
			this.editing = false
			this.objectStore.fetchObject('agenda-item', this.id)
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
</style>
