<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-7.1
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
import { useMeetingStore } from '../store/store.js'

/**
 * Index page for meetings.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-7.1
 */
export default {
	name: 'Meetings',
	components: { CnIndexPage },

	inject: {
		sidebarState: { default: () => ({ active: false }) },
	},

	setup() {
		const meetingStore = useMeetingStore()
		const listView = useListView('meeting', {
			objectStore: meetingStore,
		})
		return { listView }
	},

	methods: {
		onRowClick(row) {
			this.$router.push({ name: 'MeetingDetail', params: { id: row.id } })
		},
		onAdd() {
			this.$router.push({ name: 'MeetingDetail', params: { id: 'new' } })
		},
	},
}
</script>
