<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-9.1
-->
<template>
	<CnIndexPage
		:list-view="listView"
		:sidebar-state="sidebarState"
		@row-click="onRowClick"
		@add="onAdd" />
</template>

<script>
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useAgendaItemStore } from '../store/store.js'

/**
 * Index page for agenda items with default sort by orderNumber ascending.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-9.1
 */
export default {
	name: 'AgendaItems',
	components: { CnIndexPage },

	inject: {
		sidebarState: { default: () => ({ active: false }) },
	},

	setup() {
		const agendaItemStore = useAgendaItemStore()
		const listView = useListView('agendaItem', {
			objectStore: agendaItemStore,
			defaultSort: { field: 'orderNumber', direction: 'asc' },
		})
		return { listView }
	},

	methods: {
		onRowClick(row) {
			this.$router.push({ name: 'AgendaItemDetail', params: { id: row.id } })
		},
		onAdd() {
			this.$router.push({ name: 'AgendaItemDetail', params: { id: 'new' } })
		},
	},
}
</script>
