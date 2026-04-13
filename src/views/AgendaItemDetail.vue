<template>
	<CnDetailPage
		v-bind="detailView"
		:title="detailView.object.value?.title || t('decidesk', 'Agenda Item')"
		object-type="agendaItem"
		@edit="detailView.editing.value = true"
		@delete="detailView.showDeleteDialog.value = true">
		<template #content>
			<CnDetailCard :title="t('decidesk', 'Properties')">
				<template #content>
					<dl class="decidesk-detail__properties">
						<dt>{{ t('decidesk', 'Title') }}</dt>
						<dd>{{ object.title }}</dd>
						<dt>{{ t('decidesk', 'Item Type') }}</dt>
						<dd>{{ object.itemType }}</dd>
						<dt>{{ t('decidesk', 'Order Number') }}</dt>
						<dd>{{ object.orderNumber }}</dd>
						<dt>{{ t('decidesk', 'Estimated Duration') }}</dt>
						<dd>{{ object.estimatedDuration ? object.estimatedDuration + ' min' : '' }}</dd>
						<dt>{{ t('decidesk', 'Actual Duration') }}</dt>
						<dd>{{ object.actualDuration ? object.actualDuration + ' min' : '' }}</dd>
						<dt>{{ t('decidesk', 'Description') }}</dt>
						<dd>{{ object.description }}</dd>
						<dt>{{ t('decidesk', 'Recurring') }}</dt>
						<dd>{{ object.isRecurring ? t('decidesk', 'Yes') : t('decidesk', 'No') }}</dd>
					</dl>
				</template>
			</CnDetailCard>

			<CnDetailCard :title="t('decidesk', 'Meeting')">
				<template #content>
					<ul v-if="relatedMeetings.length" class="decidesk-detail__related-list">
						<li
							v-for="item in relatedMeetings"
							:key="item.id"
							class="decidesk-detail__related-item"
							@click="$router.push({ name: 'MeetingDetail', params: { id: item.id } })">
							<span class="decidesk-detail__related-title">{{ item.title || item.id }}</span>
							<span v-if="item.lifecycle" class="decidesk-detail__related-meta">{{ item.lifecycle }}</span>
						</li>
					</ul>
					<p v-else class="decidesk-detail__empty">
						{{ t('decidesk', 'No meeting linked to this agenda item.') }}
					</p>
				</template>
			</CnDetailCard>
		</template>

		<template #sidebar>
			<CnObjectSidebar
				v-if="object.id"
				:object-type="'agendaItem'"
				:object-id="object.id"
				:object-store="agendaItemStore" />
		</template>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage, CnDetailCard, CnObjectSidebar, useDetailView } from '@conduction/nextcloud-vue'
import { useAgendaItemStore } from '../store/modules/agendaItem.js'
import { useMeetingStore } from '../store/modules/meeting.js'

export default {
	name: 'AgendaItemDetail',
	components: { CnDetailPage, CnDetailCard, CnObjectSidebar },
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const agendaItemStore = useAgendaItemStore()
		const meetingStore = useMeetingStore()
		const detailView = useDetailView('agendaItem', props.id, {
			objectStore: () => agendaItemStore,
			listRouteName: 'AgendaItems',
			detailRouteName: 'AgendaItemDetail',
		})
		return { detailView, agendaItemStore, meetingStore }
	},
	data() {
		return {
			relatedMeetings: [],
		}
	},
	computed: {
		object() {
			return this.detailView.object.value || {}
		},
	},
	watch: {
		'object.id': {
			immediate: true,
			async handler(id) {
				if (!id) return
				const meetings = await this.agendaItemStore.fetchUses('agendaItem', id, { _schema: 'meeting' })
				this.relatedMeetings = meetings || []
			},
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
