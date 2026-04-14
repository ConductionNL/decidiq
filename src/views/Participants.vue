<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-8.1
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
import { useParticipantStore } from '../store/store.js'

/**
 * Index page for participants.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-8.1
 */
export default {
	name: 'Participants',
	components: { CnIndexPage },

	inject: {
		sidebarState: { default: () => ({ active: false }) },
	},

	setup() {
		const participantStore = useParticipantStore()
		const listView = useListView('participant', {
			objectStore: participantStore,
		})
		return { listView }
	},

	methods: {
		onRowClick(row) {
			this.$router.push({ name: 'ParticipantDetail', params: { id: row.id } })
		},
		onAdd() {
			this.$router.push({ name: 'ParticipantDetail', params: { id: 'new' } })
		},
	},
}
</script>
