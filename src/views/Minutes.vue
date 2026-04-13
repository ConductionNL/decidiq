<template>
	<CnIndexPage
		:list-view="listView"
		:sidebar-state="sidebarState"
		:title="t('decidesk', 'Notulen')"
		@row-click="onRowClick">
		<template #columns>
			<th>{{ t('decidesk', 'Title') }}</th>
			<th>{{ t('decidesk', 'Lifecycle') }}</th>
			<th>{{ t('decidesk', 'Version') }}</th>
			<th>{{ t('decidesk', 'Approved at') }}</th>
		</template>
		<template #rows="{ items }">
			<tr v-for="item in items"
				:key="item.id"
				class="clickable-row"
				@click="onRowClick(item)">
				<td>{{ item.title }}</td>
				<td>
					<CnStatusBadge :label="item.lifecycle || ''" />
				</td>
				<td>{{ item.version }}</td>
				<td>{{ formatDate(item.approvedAt) }}</td>
			</tr>
		</template>
	</CnIndexPage>
</template>

<script>
import { CnIndexPage, CnStatusBadge, useListView } from '@conduction/nextcloud-vue'
import { useMinutesStore } from '../store/modules/minutes.js'

export default {
	name: 'Minutes',
	components: {
		CnIndexPage,
		CnStatusBadge,
	},
	inject: ['sidebarState'],
	setup() {
		const minutesStore = useMinutesStore()
		const listView = useListView('minutes', {
			objectStore: minutesStore,
		})
		return { listView }
	},
	methods: {
		onRowClick(item) {
			this.$router.push({ name: 'MinutesDetail', params: { id: item.id } })
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
