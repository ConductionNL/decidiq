<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-6.1
-->
<template>
	<CnIndexPage
		:title="t('decidesk', 'Besluiten')"
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
				:title="t('decidesk', 'Nieuw besluit')"
				:object-store="objectStore"
				object-type="decision"
				@close="close"
				@saved="onSaved" />
		</template>
	</CnIndexPage>
</template>

<script>
import { CnIndexPage, CnSchemaFormDialog, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/store.js'

export default {
	name: 'Decisions',
	components: { CnIndexPage, CnSchemaFormDialog },
	setup() {
		const objectStore = useObjectStore()
		const listView = useListView('decision', {
			objectStore,
			sidebarState: null,
		})
		return { ...listView, objectStore }
	},
	data() {
		return {
			columns: ['title', 'outcome', 'decisionDate', 'isPublished'],
		}
	},
	methods: {
		onRowClick(row) {
			this.$router.push({ name: 'DecisionDetail', params: { id: row.id } })
		},
		onCreateClick() { /* handled by create-dialog slot */ },
		onSaved() {
			this.refresh()
		},
	},
}
</script>
