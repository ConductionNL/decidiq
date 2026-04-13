<template>
	<CnIndexPage
		v-bind="listView"
		:title="t('decidesk', 'Governance Bodies')"
		object-type="governanceBody"
		@row-click="onRowClick" />
</template>

<script>
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useGovernanceBodyStore } from '../store/modules/governanceBody.js'

export default {
	name: 'GovernanceBodies',
	components: { CnIndexPage },
	setup() {
		const governanceBodyStore = useGovernanceBodyStore()
		const listView = useListView('governanceBody', {
			objectStore: governanceBodyStore,
		})
		return { listView }
	},
	methods: {
		onRowClick(row) {
			this.$router.push({ name: 'GovernanceBodyDetail', params: { id: row.id } })
		},
	},
}
</script>
