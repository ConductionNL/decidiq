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

			<CnDetailCard :title="t('decidesk', 'Agenda Items')">
				<template #content>
					<ul v-if="relatedAgendaItems.length" class="decidesk-detail__related-list">
						<li
							v-for="item in relatedAgendaItems"
							:key="item.id"
							class="decidesk-detail__related-item"
							@click="$router.push({ name: 'AgendaItemDetail', params: { id: item.id } })">
							<span v-if="item.orderNumber" class="decidesk-detail__related-order">{{ item.orderNumber }}.</span>
							<span class="decidesk-detail__related-title">{{ item.title || item.id }}</span>
							<span v-if="item.itemType" class="decidesk-detail__related-meta">{{ item.itemType }}</span>
						</li>
					</ul>
					<p v-else class="decidesk-detail__empty">
						{{ t('decidesk', 'No agenda items linked to this meeting.') }}
					</p>
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
import { useAgendaItemStore } from '../store/modules/agendaItem.js'

export default {
	name: 'MeetingDetail',
	components: { CnDetailPage, CnDetailCard, CnObjectSidebar },
	props: {
		id: { type: String, required: true },
	},
	setup(props) {
		const meetingStore = useMeetingStore()
		const agendaItemStore = useAgendaItemStore()
		const detailView = useDetailView('meeting', props.id, {
			objectStore: () => meetingStore,
			listRouteName: 'Meetings',
			detailRouteName: 'MeetingDetail',
		})
		return { detailView, meetingStore, agendaItemStore }
	},
	data() {
		return {
			relatedAgendaItems: [],
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
				const items = await this.meetingStore.fetchUsed('meeting', id, {
					_schema: 'agenda-item',
					_sort: 'orderNumber',
					_order: 'asc',
				})
				this.relatedAgendaItems = items || []
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

.decidesk-detail__related-list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.decidesk-detail__related-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
	cursor: pointer;
}

.decidesk-detail__related-item:last-child {
	border-bottom: none;
}

.decidesk-detail__related-item:hover {
	color: var(--color-primary-element);
}

.decidesk-detail__related-order {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	min-width: 24px;
}

.decidesk-detail__related-title {
	flex: 1;
}

.decidesk-detail__related-meta {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.decidesk-detail__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
