<template>
	<CnIndexPage v-bind="listView" @row-click="onRowClick" @add-click="onAddClick" />
</template>

<script>
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'OfferIndex',
	components: { CnIndexPage },
	inject: ['sidebarState'],
	data() {
		return {
			listView: useListView('offer', { objectStore: useObjectStore() }),
		}
	},
	methods: {
		onRowClick(row) {
			this.$router.push({ name: 'OfferDetail', params: { id: row.id } })
		},
		onAddClick() {
			this.$router.push({ name: 'OfferDetail', params: { id: 'new' } })
		},
	},
}
</script>
