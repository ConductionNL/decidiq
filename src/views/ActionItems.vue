<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-7.1
 @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-7.3
-->
<template>
	<CnIndexPage
		:title="t('decidesk', 'Actiepunten')"
		:schema="schema"
		:objects="enrichedObjects"
		:loading="loading"
		:pagination="pagination"
		:search-term="searchTerm"
		:active-filters="activeFilters"
		:visible-columns="columns"
		@search="onSearch"
		@sort="onSort"
		@filter-change="onFilterChange"
		@page-change="onPageChange"
		@row-click="onRowClick"
		@create="onCreateClick"
		@refresh="refresh">
		<template #create-dialog="{ close }">
			<CnSchemaFormDialog
				:schema="schema"
				:title="t('decidesk', 'Actiepunt aanmaken')"
				:object-store="objectStore"
				object-type="action-item"
				@close="close"
				@saved="onSaved" />
		</template>
	</CnIndexPage>
</template>

<script>
import { CnIndexPage, CnSchemaFormDialog, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/store.js'

/**
 * Action item list view with overdue visual indicator.
 *
 * Overdue items are identified client-side: dueDate < today && taskStatus !== 'completed'.
 * The background job (OverdueActionItemsJob) is the authoritative persisted status sync.
 *
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-7.1
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-7.3
 */
export default {
	name: 'ActionItems',
	components: { CnIndexPage, CnSchemaFormDialog },
	setup() {
		const objectStore = useObjectStore()
		const listView = useListView('action-item', {
			objectStore,
			sidebarState: null,
		})
		return { ...listView, objectStore }
	},
	data() {
		return {
			columns: ['title', 'assignee', 'dueDate', 'taskStatus'],
		}
	},
	computed: {
		/**
		 * Enrich objects with client-side isOverdue flag for display.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-7.3
		 */
		enrichedObjects() {
			const today = new Date()
			return (this.objects || []).map((item) => {
				const isOverdue = item.dueDate
					&& item.taskStatus !== 'completed'
					&& new Date(item.dueDate) < today
				return { ...item, _isOverdue: isOverdue }
			})
		},
	},
	methods: {
		onRowClick(row) {
			this.$router.push({ name: 'ActionItemDetail', params: { id: row.id } })
		},
		onCreateClick() { /* handled by create-dialog slot */ },
		onSaved() {
			this.refresh()
		},
	},
}
</script>
