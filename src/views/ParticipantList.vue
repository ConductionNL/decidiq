<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-8.1
-->
<template>
	<CnIndexPage
		:title="t('decidesk', 'Participants')"
		icon="mdi:account-group-outline"
		:schema="list.schema.value"
		:objects="list.objects.value"
		:loading="list.loading.value"
		:pagination="list.pagination.value"
		:sort-key="list.sortKey.value"
		:sort-order="list.sortOrder.value"
		:store="objectStore"
		object-type="participant"
		mass-action-name-field="displayName"
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
 * Participant list view using CnIndexPage + useListView.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-8.1
 */
export default {
	name: 'ParticipantList',
	components: { CnIndexPage },

	setup() {
		const objectStore = useObjectStore()
		const list = useListView('participant', { objectStore })
		return { list, objectStore }
	},

	methods: {
		/**
		 * Navigate to the participant detail page.
		 *
		 * @param {object} row The clicked row object.
		 */
		onRowClick(row) {
			this.$router.push({ name: 'ParticipantDetail', params: { id: row.id } })
		},

		/**
		 * Delete a participant and refresh the list.
		 *
		 * @param {string} id The object ID to delete.
		 */
		async onDelete(id) {
			await this.objectStore.deleteObject('participant', id)
			this.list.refresh()
		},
	},
}
</script>
