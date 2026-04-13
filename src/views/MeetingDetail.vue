<template>
	<CnDetailPage
		v-bind="detailView"
		:title="detailView.object.value?.title || t('decidesk', 'Meeting')"
		object-type="meeting"
		@edit="detailView.editing.value = true"
		@delete="detailView.showDeleteDialog.value = true">
		<template #content>
			<CnDetailCard :title="t('decidesk', 'Properties')">
				<template #content>
					<dl class="decidesk-detail__properties">
						<dt>{{ t('decidesk', 'Title') }}</dt>
						<dd>{{ object.title }}</dd>
						<dt>{{ t('decidesk', 'Meeting Type') }}</dt>
						<dd>{{ object.meetingType }}</dd>
						<dt>{{ t('decidesk', 'Scheduled Date') }}</dt>
						<dd>{{ object.scheduledDate }}</dd>
						<dt>{{ t('decidesk', 'End Date') }}</dt>
						<dd>{{ object.endDate }}</dd>
						<dt>{{ t('decidesk', 'Location') }}</dt>
						<dd>{{ object.location }}</dd>
						<dt>{{ t('decidesk', 'Meeting Mode') }}</dt>
						<dd>{{ object.meetingMode }}</dd>
						<dt>{{ t('decidesk', 'Lifecycle') }}</dt>
						<dd>{{ object.lifecycle }}</dd>
						<dt>{{ t('decidesk', 'Quorum Required') }}</dt>
						<dd>{{ object.quorumRequired }}</dd>
					</dl>
				</template>
			</CnDetailCard>
		</template>

		<template #sidebar>
			<CnObjectSidebar
				v-if="object.id"
				:object-type="'meeting'"
				:object-id="object.id"
				:object-store="meetingStore" />
		</template>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage, CnDetailCard, CnObjectSidebar, useDetailView } from '@conduction/nextcloud-vue'
import { useMeetingStore } from '../store/modules/meeting.js'

export default {
	name: 'MeetingDetail',
	components: { CnDetailPage, CnDetailCard, CnObjectSidebar },
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const meetingStore = useMeetingStore()
		const detailView = useDetailView('meeting', props.id, {
			objectStore: () => meetingStore,
			listRouteName: 'Meetings',
			detailRouteName: 'MeetingDetail',
		})
		return { detailView, meetingStore }
	},
	computed: {
		object() {
			return this.detailView.object.value || {}
		},
	},
}
</script>

<style scoped>
.decidesk-detail__properties {
	display: grid;
	grid-template-columns: 1fr 2fr;
	gap: 8px 16px;
}

.decidesk-detail__properties dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}
</style>
