<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-7.2
-->
<template>
	<div>
		<CnDetailPage
			v-if="!isNew && detailView"
			:detail-view="detailView"
			:title="detailView.data?.title || t('decidesk', 'Meeting')">
			<template #header-actions>
				<NcButton type="secondary" @click="isEditing = true">
					{{ t('decidesk', 'Edit') }}
				</NcButton>
				<NcButton type="error" @click="showDelete = true">
					{{ t('decidesk', 'Delete') }}
				</NcButton>
			</template>

			<CnDetailCard :title="t('decidesk', 'Properties')">
				<CnDetailGrid :items="propertyItems" />
			</CnDetailCard>

			<CnDetailCard :title="t('decidesk', 'Agenda Items')">
				<CnDataTable
					v-if="relatedAgendaItems.length"
					:columns="agendaItemColumns"
					:rows="relatedAgendaItems"
					@row-click="(row) => $router.push({ name: 'AgendaItemDetail', params: { id: row.id } })" />
				<p v-else>{{ t('decidesk', 'No agenda items linked to this meeting.') }}</p>
			</CnDetailCard>

			<template #sidebar>
				<CnObjectSidebar
					:object-store="meetingStore"
					:object-id="entityId" />
			</template>
		</CnDetailPage>

		<CnFormDialog
			v-if="isEditing || isNew"
			:open="isEditing || isNew"
			:object-store="meetingStore"
			:object="isNew ? {} : detailView?.data"
			:title="isNew ? t('decidesk', 'New Meeting') : t('decidesk', 'Edit Meeting')"
			@close="onFormClose"
			@saved="onSaved" />

		<CnDeleteDialog
			v-if="showDelete"
			:open="showDelete"
			:object-store="meetingStore"
			:object="detailView?.data"
			@close="showDelete = false"
			@deleted="$router.push({ name: 'Meetings' })" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnDataTable, CnObjectSidebar, CnFormDialog, CnDeleteDialog, useDetailView } from '@conduction/nextcloud-vue'
import { useMeetingStore } from '../store/store.js'

/**
 * Detail page for a meeting with related agenda items.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-7.2
 */
export default {
	name: 'MeetingDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		CnDetailGrid,
		CnDataTable,
		CnObjectSidebar,
		CnFormDialog,
		CnDeleteDialog,
	},

	props: {
		entityId: { type: String, required: true },
	},

	setup(props) {
		const meetingStore = useMeetingStore()
		const detailView = props.entityId !== 'new'
			? useDetailView('meeting', { objectStore: meetingStore, id: props.entityId })
			: null
		return { detailView, meetingStore }
	},

	data() {
		return {
			isEditing: false,
			showDelete: false,
			relatedAgendaItems: [],
			agendaItemColumns: [
				{ key: 'orderNumber', label: this.t('decidesk', 'Order') },
				{ key: 'title', label: this.t('decidesk', 'Title') },
				{ key: 'itemType', label: this.t('decidesk', 'Type') },
				{ key: 'estimatedDuration', label: this.t('decidesk', 'Duration (min)') },
			],
		}
	},

	computed: {
		isNew() {
			return this.entityId === 'new'
		},
		propertyItems() {
			const d = this.detailView?.data
			if (!d) return []
			return [
				{ label: this.t('decidesk', 'Title'), value: d.title },
				{ label: this.t('decidesk', 'Type'), value: d.meetingType },
				{ label: this.t('decidesk', 'Scheduled Date'), value: d.scheduledDate },
				{ label: this.t('decidesk', 'End Date'), value: d.endDate },
				{ label: this.t('decidesk', 'Location'), value: d.location },
				{ label: this.t('decidesk', 'Mode'), value: d.meetingMode },
				{ label: this.t('decidesk', 'Lifecycle'), value: d.lifecycle },
				{ label: this.t('decidesk', 'Quorum Required'), value: d.quorumRequired },
			]
		},
	},

	methods: {
		onFormClose() {
			if (this.isNew) {
				this.$router.push({ name: 'Meetings' })
			}
			this.isEditing = false
		},
		onSaved(savedObject) {
			if (this.isNew && savedObject?.id) {
				this.$router.replace({ name: 'MeetingDetail', params: { id: savedObject.id } })
			}
			this.isEditing = false
		},
	},
}
</script>
