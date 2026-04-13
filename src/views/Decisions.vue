<template>
	<CnIndexPage
		:list-view="listView"
		:sidebar-state="sidebarState"
		:title="t('decidesk', 'Besluiten')"
		@row-click="onRowClick">
		<template #filters>
			<CnFilterBar
				:filters="filters"
				@filter-change="onFilterChange" />
		</template>
		<template #columns>
			<th>{{ t('decidesk', 'Title') }}</th>
			<th>{{ t('decidesk', 'Outcome') }}</th>
			<th>{{ t('decidesk', 'Decision date') }}</th>
			<th>{{ t('decidesk', 'Published') }}</th>
		</template>
		<template #rows="{ items }">
			<tr v-for="item in items"
				:key="item.id"
				class="clickable-row"
				@click="onRowClick(item)">
				<td>{{ item.title }}</td>
				<td>
					<CnStatusBadge :label="item.outcome || ''" />
				</td>
				<td>{{ formatDate(item.decisionDate) }}</td>
				<td>
					<CnStatusBadge
						:label="item.isPublished ? t('decidesk', 'Published') : t('decidesk', 'Not published')"
						:variant="item.isPublished ? 'success' : 'default'" />
				</td>
			</tr>
		</template>
	</CnIndexPage>
</template>

<script>
import { CnFilterBar, CnIndexPage, CnStatusBadge, useListView } from '@conduction/nextcloud-vue'
import { useDecisionStore } from '../store/modules/decision.js'

export default {
	name: 'Decisions',
	components: {
		CnFilterBar,
		CnIndexPage,
		CnStatusBadge,
	},
	inject: ['sidebarState'],
	setup() {
		const decisionStore = useDecisionStore()
		const listView = useListView('decision', {
			objectStore: decisionStore,
		})
		return { listView }
	},
	data() {
		return {
			filters: [
				{
					key: 'outcome',
					label: this.t('decidesk', 'Outcome'),
					options: [
						{ value: 'adopted', label: this.t('decidesk', 'Adopted') },
						{ value: 'rejected', label: this.t('decidesk', 'Rejected') },
					],
				},
				{
					key: 'isPublished',
					label: this.t('decidesk', 'Published'),
					options: [
						{ value: 'true', label: this.t('decidesk', 'Published') },
						{ value: 'false', label: this.t('decidesk', 'Not published') },
					],
				},
			],
		}
	},
	methods: {
		onRowClick(item) {
			this.$router.push({ name: 'DecisionDetail', params: { id: item.id } })
		},
		onFilterChange(filters) {
			this.listView?.applyFilters?.(filters)
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.clickable-row {
	cursor: pointer;
}

.clickable-row:hover {
	background-color: var(--color-background-hover);
}
</style>
