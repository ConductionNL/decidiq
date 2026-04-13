<template>
	<CnIndexPage v-bind="listView" @row-click="onRowClick" @add-click="onAddClick" />
</template>

<script>
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'MinutesIndex',
	components: { CnIndexPage },
	inject: ['sidebarState'],
	data() {
		return {
			listView: useListView('minutes', { objectStore: useObjectStore() }),
		}
	},
	methods: {
		onRowClick(row) {
			this.$router.push({ name: 'MinutesDetail', params: { id: row.id } })
		},
		onAddClick() {
			this.$router.push({ name: 'MinutesDetail', params: { id: 'new' } })
		},
	},
}
</script>
