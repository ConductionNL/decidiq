<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-8.2
-->
<template>
	<div>
		<CnDetailPage
			v-if="!isNew && detailView"
			:detail-view="detailView"
			:title="detailView.data?.displayName || t('decidesk', 'Participant')">
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

			<CnDetailCard :title="t('decidesk', 'Governance Body')">
				<p>{{ t('decidesk', 'Related governance body is shown via object relations.') }}</p>
			</CnDetailCard>

			<template #sidebar>
				<CnObjectSidebar
					:object-store="participantStore"
					:object-id="entityId" />
			</template>
		</CnDetailPage>

		<CnFormDialog
			v-if="isEditing || isNew"
			:open="isEditing || isNew"
			:object-store="participantStore"
			:object="isNew ? {} : detailView?.data"
			:title="isNew ? t('decidesk', 'New Participant') : t('decidesk', 'Edit Participant')"
			@close="onFormClose"
			@saved="onSaved" />

		<CnDeleteDialog
			v-if="showDelete"
			:open="showDelete"
			:object-store="participantStore"
			:object="detailView?.data"
			@close="showDelete = false"
			@deleted="$router.push({ name: 'Participants' })" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnObjectSidebar, CnFormDialog, CnDeleteDialog, useDetailView } from '@conduction/nextcloud-vue'
import { useParticipantStore } from '../store/store.js'

/**
 * Detail page for a participant with related governance body.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-8.2
 */
export default {
	name: 'ParticipantDetail',
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
		const participantStore = useParticipantStore()
		const detailView = props.entityId !== 'new'
			? useDetailView('participant', { objectStore: participantStore, id: props.entityId })
			: null
		return { detailView, participantStore }
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
				{ label: this.t('decidesk', 'Display Name'), value: d.displayName },
				{ label: this.t('decidesk', 'Role'), value: d.role },
				{ label: this.t('decidesk', 'Party'), value: d.party },
				{ label: this.t('decidesk', 'Email'), value: d.email },
				{ label: this.t('decidesk', 'Joined At'), value: d.joinedAt },
				{ label: this.t('decidesk', 'Left At'), value: d.leftAt },
				{ label: this.t('decidesk', 'Voting Weight'), value: d.votingWeight },
			]
		},
	},

	methods: {
		onFormClose() {
			if (this.isNew) {
				this.$router.push({ name: 'Participants' })
			}
			this.isEditing = false
		},
		onSaved(savedObject) {
			if (this.isNew && savedObject?.id) {
				this.$router.replace({ name: 'ParticipantDetail', params: { id: savedObject.id } })
			}
			this.isEditing = false
		},
	},
}
</script>
