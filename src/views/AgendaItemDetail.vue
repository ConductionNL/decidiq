<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-9.2
-->
<template>
	<div>
		<CnDetailPage
			v-if="!isNew && detailView"
			:detail-view="detailView"
			:title="detailView.data?.title || t('decidesk', 'Agenda Item')">
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

			<CnDetailCard :title="t('decidesk', 'Linked Meeting')">
				<p>{{ t('decidesk', 'Related meeting is shown via object relations.') }}</p>
			</CnDetailCard>

			<template #sidebar>
				<CnObjectSidebar
					:object-store="agendaItemStore"
					:object-id="entityId" />
			</template>
		</CnDetailPage>

		<CnFormDialog
			v-if="isEditing || isNew"
			:open="isEditing || isNew"
			:object-store="agendaItemStore"
			:object="isNew ? {} : detailView?.data"
			:title="isNew ? t('decidesk', 'New Agenda Item') : t('decidesk', 'Edit Agenda Item')"
			@close="onFormClose"
			@saved="onSaved" />

		<CnDeleteDialog
			v-if="showDelete"
			:open="showDelete"
			:object-store="agendaItemStore"
			:object="detailView?.data"
			@close="showDelete = false"
			@deleted="$router.push({ name: 'AgendaItems' })" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnFormDialog, CnDeleteDialog, useDetailView } from '@conduction/nextcloud-vue'
import { useAgendaItemStore } from '../store/store.js'

/**
 * Detail page for an agenda item with linked meeting.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-9.2
 */
export default {
	name: 'AgendaItemDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		CnDetailGrid,
		CnObjectSidebar,
		CnFormDialog,
		CnDeleteDialog,
	},

	props: {
		entityId: { type: String, required: true },
	},

	setup(props) {
		const agendaItemStore = useAgendaItemStore()
		const detailView = props.entityId !== 'new'
			? useDetailView('agendaItem', { objectStore: agendaItemStore, id: props.entityId })
			: null
		return { detailView, agendaItemStore }
	},

	data() {
		return {
			isEditing: false,
			showDelete: false,
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
				{ label: this.t('decidesk', 'Type'), value: d.itemType },
				{ label: this.t('decidesk', 'Order Number'), value: d.orderNumber },
				{ label: this.t('decidesk', 'Estimated Duration'), value: d.estimatedDuration },
				{ label: this.t('decidesk', 'Actual Duration'), value: d.actualDuration },
				{ label: this.t('decidesk', 'Description'), value: d.description },
				{ label: this.t('decidesk', 'Is Recurring'), value: d.isRecurring },
			]
		},
	},

	methods: {
		onFormClose() {
			if (this.isNew) {
				this.$router.push({ name: 'AgendaItems' })
			}
			this.isEditing = false
		},
		onSaved(savedObject) {
			if (this.isNew && savedObject?.id) {
				this.$router.replace({ name: 'AgendaItemDetail', params: { id: savedObject.id } })
			}
			this.isEditing = false
		},
	},
}
</script>
