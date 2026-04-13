<template>
	<CnIndexPage
		:list-view="listView"
		:sidebar-state="sidebarState"
		:title="t('decidesk', 'Actiepunten')"
		@row-click="onRowClick">
		<template #filters>
			<CnFilterBar
				:filters="filters"
				@filter-change="onFilterChange" />
		</template>
		<template #columns>
			<th>{{ t('decidesk', 'Title') }}</th>
			<th>{{ t('decidesk', 'Assignee') }}</th>
			<th>{{ t('decidesk', 'Due date') }}</th>
			<th>{{ t('decidesk', 'Status') }}</th>
		</template>
		<template #rows="{ items }">
			<tr v-for="item in items"
				:key="item.id"
				class="clickable-row"
				@click="onRowClick(item)">
				<td>{{ item.title }}</td>
				<td>{{ item.assignee || '-' }}</td>
				<td>{{ formatDate(item.dueDate) }}</td>
				<td>
					<CnStatusBadge
						:label="getStatusLabel(item)"
						:variant="getStatusVariant(item)" />
				</td>
			</tr>
		</template>
	</CnIndexPage>
</template>

<script>
import { CnFilterBar, CnIndexPage, CnStatusBadge, useListView } from '@conduction/nextcloud-vue'
import { useActionItemStore } from '../store/modules/actionItem.js'

export default {
	name: 'ActionItems',
	components: {
		CnFilterBar,
		CnIndexPage,
		CnStatusBadge,
	},
	inject: ['sidebarState'],
	setup() {
		const actionItemStore = useActionItemStore()
		const listView = useListView('action-item', {
			objectStore: actionItemStore,
		})
		return { listView, actionItemStore }
	},
	data() {
		return {
			filters: [
				{
					key: 'taskStatus',
					label: this.t('decidesk', 'Status'),
					options: [
						{ value: 'open', label: this.t('decidesk', 'Open') },
						{ value: 'in-progress', label: this.t('decidesk', 'In progress') },
						{ value: 'completed', label: this.t('decidesk', 'Completed') },
						{ value: 'overdue', label: this.t('decidesk', 'Overdue') },
					],
				},
				{
					key: 'assignee',
					label: this.t('decidesk', 'Assignee'),
					options: [],
				},
			],
		}
	},
	async created() {
		await this.loadAssigneeOptions()
	},
	methods: {
		async loadAssigneeOptions() {
			try {
				const items = await this.actionItemStore.fetchObjects?.('action-item') || []
				const uniqueAssignees = [...new Set(
					(Array.isArray(items) ? items : [])
						.map((item) => item.assignee)
						.filter(Boolean),
				)]
				const assigneeFilter = this.filters.find((f) => f.key === 'assignee')
				if (assigneeFilter) {
					assigneeFilter.options = uniqueAssignees.map((a) => ({ value: a, label: a }))
				}
			} catch (e) {
				// Non-fatal: filter remains empty if assignees cannot be loaded.
			}
		},
		onRowClick(item) {
			this.$router.push({ name: 'ActionItemDetail', params: { id: item.id } })
		},
		onFilterChange(filters) {
			this.listView?.applyFilters?.(filters)
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString()
		},
		isOverdue(item) {
			if (item.taskStatus === 'completed') return false
			if (!item.dueDate) return false
			return new Date(item.dueDate) < new Date()
		},
		getStatusLabel(item) {
			if (this.isOverdue(item) && item.taskStatus !== 'overdue') {
				return this.t('decidesk', 'Overdue')
			}
			return item.taskStatus || ''
		},
		getStatusVariant(item) {
			if (this.isOverdue(item) || item.taskStatus === 'overdue') {
				return 'error'
			}
			if (item.taskStatus === 'completed') return 'success'
			if (item.taskStatus === 'in-progress') return 'warning'
			return 'default'
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
