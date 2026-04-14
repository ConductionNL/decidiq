<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-7.1
-->
<template>
	<CnIndexPage
		:title="t('decidesk', 'Meetings')"
		icon="mdi:calendar-blank"
		:schema="list.schema.value"
		:objects="list.objects.value"
		:loading="list.loading.value"
		:pagination="list.pagination.value"
		:sort-key="list.sortKey.value"
		:sort-order="list.sortOrder.value"
		:store="objectStore"
		object-type="meeting"
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
 * Meeting list view using CnIndexPage + useListView.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-7.1
 */
export default {
	name: 'MeetingList',
	components: { CnIndexPage },

	setup() {
		const objectStore = useObjectStore()
		const list = useListView('meeting', { objectStore })
		return { list, objectStore }
	},

	methods: {
		/**
		 * Navigate to the meeting detail page.
		 *
		 * @param {object} row The clicked row object.
		 */
		onRowClick(row) {
			this.$router.push({ name: 'MeetingDetail', params: { id: row.id } })
		},

		/**
		 * Delete a meeting and refresh the list.
		 *
		 * @param {string} id The object ID to delete.
		 */
		async onDelete(id) {
			await this.objectStore.deleteObject('meeting', id)
			this.list.refresh()
		},
	},
}
</script>
