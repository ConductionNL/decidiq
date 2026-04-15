<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-motion-and-voting/tasks.md#task-4.1
-->
<template>
	<CnIndexPage
		:title="t('decidesk', 'Motions')"
		:schema="schema"
		:objects="objects"
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
				:title="t('decidesk', 'Submit Motion')"
				:object-store="objectStore"
				object-type="motion"
				@close="close"
				@saved="onSaved" />
		</template>
	</CnIndexPage>
</template>

<script>
import { CnIndexPage, CnSchemaFormDialog, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/store.js'

export default {
	name: 'Motions',
	components: { CnIndexPage, CnSchemaFormDialog },
	setup() {
		const objectStore = useObjectStore()
		const listView = useListView('motion', {
			objectStore,
			sidebarState: null,
		})
		return { ...listView, objectStore }
	},
	data() {
		return {
			columns: ['title', 'motionType', 'proposer', 'lifecycle', 'submittedAt'],
		}
	},
	methods: {
		onRowClick(row) {
			this.$router.push({ name: 'MotionDetail', params: { id: row.id } })
		},
		onCreateClick() { /* handled by create-dialog slot */ },
		onSaved() {
			this.refresh()
		},
	},
}
</script>
