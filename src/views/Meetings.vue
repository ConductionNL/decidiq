<template>
	<CnIndexPage
		v-bind="listView"
		:title="t('decidesk', 'Meetings')"
		object-type="meeting"
		@row-click="onRowClick" />
</template>

<script>
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useMeetingStore } from '../store/modules/meeting.js'

export default {
	name: 'Meetings',
	components: { CnIndexPage },
	setup() {
		const meetingStore = useMeetingStore()
		const listView = useListView('meeting', {
			objectStore: meetingStore,
		})
		return { listView }
	},
	methods: {
		onRowClick(row) {
			this.$router.push({ name: 'MeetingDetail', params: { id: row.id } })
		},
	},
}
</script>
