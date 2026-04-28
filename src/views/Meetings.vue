<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/p2-meeting-management-core-t1/tasks.md#task-4.1 -->

<template>
	<div class="meetings-view">
		<!-- Filter and search toolbar -->
		<div class="meetings-toolbar">
			<NcInputField
				v-model="searchQuery"
				type="text"
				:placeholder="t('decidesk', 'Search meetings...')"
				class="search-input"
				@input="onSearch" />

			<NcSelect
				v-model="selectedLifecycle"
				:options="lifecycleOptions"
				:placeholder="t('decidesk', 'Filter by lifecycle')"
				multiple
				@input="onFilterChange" />

			<NcButton
				type="secondary"
				@click="onCreateClick">
				{{ t('decidesk', 'Add Meeting') }}
			</NcButton>

			<NcButton
				type="tertiary"
				@click="resetFilters">
				{{ t('decidesk', 'Reset Filters') }}
			</NcButton>
		</div>

		<!-- Main content area -->
		<CnIndexPage
			:title="t('decidesk', 'Meetings')"
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
			@refresh="refresh">
			<template #create-dialog="{ close }">
				<CnSchemaFormDialog
					:schema="schema"
					:title="t('decidesk', 'Create Meeting')"
					:object-store="objectStore"
					object-type="meeting"
					@close="close"
					@saved="onSaved" />
			</template>
		</CnIndexPage>
	</div>
</template>

<script>
import { CnIndexPage, CnSchemaFormDialog, useListView } from '@conduction/nextcloud-vue'
import { NcButton, NcInputField, NcSelect } from '@nextcloud/vue'
import { useObjectStore } from '../store/store.js'

export default {
	name: 'Meetings',
	components: { CnIndexPage, CnSchemaFormDialog, NcButton, NcInputField, NcSelect },
	setup() {
		const objectStore = useObjectStore()
		const listView = useListView('meeting', {
			objectStore,
			sidebarState: null,
		})
		return { ...listView, objectStore }
	},
	data() {
		return {
			columns: ['title', 'meetingType', 'scheduledDate', 'meetingMode', 'lifecycle'],
			searchQuery: '',
			selectedLifecycle: [],
			lifecycleOptions: [
				{ id: 'draft', label: 'Draft' },
				{ id: 'scheduled', label: 'Scheduled' },
				{ id: 'opened', label: 'Opened' },
				{ id: 'paused', label: 'Paused' },
				{ id: 'adjourned', label: 'Adjourned' },
				{ id: 'closed', label: 'Closed' },
			],
		}
	},
	methods: {
		onRowClick(row) {
			this.$router.push({ name: 'MeetingDetail', params: { id: row.id } })
		},
		onCreateClick() {
			this.$router.push({ name: 'MeetingDetail', params: { id: 'new' } })
		},
		onSaved() {
			this.refresh()
		},
		onSearch(query) {
			this.searchQuery = query
			this.refresh()
		},
		onFilterChange() {
			this.refresh()
		},
		resetFilters() {
			this.searchQuery = ''
			this.selectedLifecycle = []
			this.refresh()
		},
	},
}
</script>

<style scoped>
.meetings-view {
	padding: 20px;
}

.meetings-toolbar {
	display: flex;
	gap: 10px;
	margin-bottom: 20px;
	align-items: center;
}

.search-input {
	flex: 1;
	min-width: 200px;
}
</style>
