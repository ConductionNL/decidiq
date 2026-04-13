<template>
	<CnIndexPage
		v-bind="listView"
		:title="t('decidesk', 'Participants')"
		object-type="participant"
		@row-click="onRowClick" />
</template>

<script>
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useParticipantStore } from '../store/modules/participant.js'

export default {
	name: 'Participants',
	components: { CnIndexPage },
	setup() {
		const participantStore = useParticipantStore()
		const listView = useListView('participant', {
			objectStore: participantStore,
		})
		return { listView }
	},
	methods: {
		onRowClick(row) {
			this.$router.push({ name: 'ParticipantDetail', params: { id: row.id } })
		},
	},
}
</script>
