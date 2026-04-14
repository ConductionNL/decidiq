<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-9.1
-->
<template>
	<CnIndexPage
		:title="t('decidesk', 'Agenda Items')"
		icon="mdi:format-list-bulleted"
		:schema="list.schema.value"
		:objects="list.objects.value"
		:loading="list.loading.value"
		:pagination="list.pagination.value"
		:sort-key="list.sortKey.value"
		:sort-order="list.sortOrder.value"
		:store="objectStore"
		object-type="agendaItem"
		mass-action-name-field="title"
		@row-click="onRowClick"
		@sort="list.onSort"
		@page-changed="list.onPageChange"
		@page-size-changed="list.onPageSizeChange"
		@refresh="list.refresh"
		@delete="onDelete" />
</template>

<script>
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/modules/object.js'

/**
 * Agenda item list view using CnIndexPage + useListView.
 * Default sort is orderNumber ascending.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-9.1
 */
export default {
	name: 'AgendaItemList',
	components: { CnIndexPage },

	setup() {
		const objectStore = useObjectStore()
		const list = useListView('agendaItem', {
			objectStore,
			defaultSort: { key: 'orderNumber', order: 'asc' },
		})
		return { list, objectStore }
	},

	methods: {
		/**
		 * Navigate to the agenda item detail page.
		 *
		 * @param {object} row The clicked row object.
		 */
		onRowClick(row) {
			this.$router.push({ name: 'AgendaItemDetail', params: { id: row.id } })
		},

		/**
		 * Delete an agenda item and refresh the list.
		 *
		 * @param {string} id The object ID to delete.
		 */
		async onDelete(id) {
			await this.objectStore.deleteObject('agendaItem', id)
			this.list.refresh()
		},
	},
}
</script>
