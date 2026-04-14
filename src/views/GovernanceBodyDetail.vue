<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-6.2
-->
<template>
	<div>
		<CnDetailPage
			v-if="!isNew && detailView"
			:detail-view="detailView"
			:title="detailView.data?.name || t('decidesk', 'Governance Body')">
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

			<CnDetailCard :title="t('decidesk', 'Related Meetings')">
				<CnDataTable
					v-if="relatedMeetings.length"
					:columns="meetingColumns"
					:rows="relatedMeetings"
					@row-click="(row) => $router.push({ name: 'MeetingDetail', params: { id: row.id } })" />
				<p v-else>{{ t('decidesk', 'No meetings linked to this governance body.') }}</p>
			</CnDetailCard>

			<CnDetailCard :title="t('decidesk', 'Related Participants')">
				<CnDataTable
					v-if="relatedParticipants.length"
					:columns="participantColumns"
					:rows="relatedParticipants"
					@row-click="(row) => $router.push({ name: 'ParticipantDetail', params: { id: row.id } })" />
				<p v-else>{{ t('decidesk', 'No participants linked to this governance body.') }}</p>
			</CnDetailCard>

			<template #sidebar>
				<CnObjectSidebar
					:object-store="governanceBodyStore"
					:object-id="entityId" />
			</template>
		</CnDetailPage>

		<CnFormDialog
			v-if="isEditing || isNew"
			:open="isEditing || isNew"
			:object-store="governanceBodyStore"
			:object="isNew ? {} : detailView?.data"
			:title="isNew ? t('decidesk', 'New Governance Body') : t('decidesk', 'Edit Governance Body')"
			@close="onFormClose"
			@saved="onSaved" />

		<CnDeleteDialog
			v-if="showDelete"
			:open="showDelete"
			:object-store="governanceBodyStore"
			:object="detailView?.data"
			@close="showDelete = false"
			@deleted="$router.push({ name: 'GovernanceBodies' })" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnDetailPage, CnDetailCard, CnDetailGrid, CnDataTable, CnObjectSidebar, CnFormDialog, CnDeleteDialog, useDetailView } from '@conduction/nextcloud-vue'
import { useGovernanceBodyStore } from '../store/store.js'

/**
 * Detail page for a governance body with related meetings and participants.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-6.2
 */
export default {
	name: 'GovernanceBodyDetail',
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
		const governanceBodyStore = useGovernanceBodyStore()
		const detailView = props.entityId !== 'new'
			? useDetailView('governanceBody', { objectStore: governanceBodyStore, id: props.entityId })
			: null
		return { detailView, governanceBodyStore }
	},

	data() {
		return {
			isEditing: false,
			showDelete: false,
			relatedMeetings: [],
			relatedParticipants: [],
			meetingColumns: [
				{ key: 'title', label: this.t('decidesk', 'Title') },
				{ key: 'meetingType', label: this.t('decidesk', 'Type') },
				{ key: 'scheduledDate', label: this.t('decidesk', 'Date') },
				{ key: 'lifecycle', label: this.t('decidesk', 'Status') },
			],
			participantColumns: [
				{ key: 'displayName', label: this.t('decidesk', 'Name') },
				{ key: 'role', label: this.t('decidesk', 'Role') },
				{ key: 'party', label: this.t('decidesk', 'Party') },
				{ key: 'email', label: this.t('decidesk', 'Email') },
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
				{ label: this.t('decidesk', 'Name'), value: d.name },
				{ label: this.t('decidesk', 'Body Type'), value: d.bodyType },
				{ label: this.t('decidesk', 'Domain'), value: d.domain },
				{ label: this.t('decidesk', 'Voting Default'), value: d.votingDefault },
				{ label: this.t('decidesk', 'Term Start'), value: d.termStart },
				{ label: this.t('decidesk', 'Term End'), value: d.termEnd },
			]
		},
	},

	methods: {
		onFormClose() {
			if (this.isNew) {
				this.$router.push({ name: 'GovernanceBodies' })
			}
			this.isEditing = false
		},
		onSaved(savedObject) {
			if (this.isNew && savedObject?.id) {
				this.$router.replace({ name: 'GovernanceBodyDetail', params: { id: savedObject.id } })
			}
			this.isEditing = false
		},
	},
}
</script>
