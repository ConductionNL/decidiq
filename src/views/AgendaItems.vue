<template>
	<CnIndexPage
		v-bind="listView"
		:title="t('decidesk', 'Agenda Items')"
		object-type="agendaItem"
		@row-click="onRowClick" />
</template>

<script>
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useAgendaItemStore } from '../store/modules/agendaItem.js'

export default {
	name: 'AgendaItems',
	components: { CnIndexPage },
	setup() {
		const agendaItemStore = useAgendaItemStore()
		const listView = useListView('agendaItem', {
			objectStore: agendaItemStore,
			defaultSort: { key: 'orderNumber', order: 'asc' },
		})
		return { listView }
	},
	methods: {
		onRowClick(row) {
			this.$router.push({ name: 'AgendaItemDetail', params: { id: row.id } })
		},
	},
}
</script>
