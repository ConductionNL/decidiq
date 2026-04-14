<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-6.1
-->
<template>
	<CnIndexPage
		:title="t('decidesk', 'Governance Bodies')"
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
				:title="t('decidesk', 'Create Governance Body')"
				:object-store="objectStore"
				object-type="governance-body"
				@close="close"
				@saved="onSaved" />
		</template>
	</CnIndexPage>
</template>

<script>
import { CnIndexPage, CnSchemaFormDialog, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/store.js'

export default {
	name: 'GovernanceBodies',
	components: { CnIndexPage, CnSchemaFormDialog },
	setup() {
		const objectStore = useObjectStore()
		const listView = useListView('governance-body', {
			objectStore,
			sidebarState: null,
		})
		return { ...listView, objectStore }
	},
	data() {
		return {
			columns: ['name', 'bodyType', 'domain', 'termEnd'],
		}
	},
	methods: {
		onRowClick(row) {
			this.$router.push({ name: 'GovernanceBodyDetail', params: { id: row.id } })
		},
		onCreateClick() { /* handled by create-dialog slot */ },
		onSaved() {
			this.refresh()
		},
	},
}
</script>
