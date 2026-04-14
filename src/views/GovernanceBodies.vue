<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-6.1
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
import { useGovernanceBodyStore } from '../store/store.js'

/**
 * Index page for governance bodies.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-6.1
 */
export default {
	name: 'GovernanceBodies',
	components: { CnIndexPage },

	inject: {
		sidebarState: { default: () => ({ active: false }) },
	},

	setup() {
		const governanceBodyStore = useGovernanceBodyStore()
		const listView = useListView('governanceBody', {
			objectStore: governanceBodyStore,
		})
		return { listView }
	},

	methods: {
		onRowClick(row) {
			this.$router.push({ name: 'GovernanceBodyDetail', params: { id: row.id } })
		},
		onAdd() {
			this.$router.push({ name: 'GovernanceBodyDetail', params: { id: 'new' } })
		},
	},
}
</script>
