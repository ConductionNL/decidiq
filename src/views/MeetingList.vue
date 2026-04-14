<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-meeting-management/tasks.md#task-5
-->
<template>
	<CnIndexPage
		:title="t('decidesk', 'Meetings')"
		:schema-slug="'meeting'"
		:register-slug="'decidesk'"
		:columns="columns"
		:actions="actions"
		@row-click="onRowClick">
		<template #empty>
			<NcEmptyContent
				:name="t('decidesk', 'No meetings yet')"
				:description="t('decidesk', 'Create your first meeting to get started.')">
				<template #icon>
					<CalendarBlank :size="64" />
				</template>
			</NcEmptyContent>
		</template>
	</CnIndexPage>
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { NcEmptyContent } from '@nextcloud/vue'
import CalendarBlank from 'vue-material-design-icons/CalendarBlank.vue'

/**
 * Meeting list view using CnIndexPage with search, filter, and pagination.
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-5
 */
export default {
	name: 'MeetingList',

	components: {
		CnIndexPage,
		NcEmptyContent,
		CalendarBlank,
	},

	data() {
		return {
			columns: [
				{
					key: 'title',
					label: this.t('decidesk', 'Title'),
					sortable: true,
				},
				{
					key: 'meetingType',
					label: this.t('decidesk', 'Type'),
					sortable: true,
				},
				{
					key: 'scheduledDate',
					label: this.t('decidesk', 'Scheduled date'),
					sortable: true,
					type: 'datetime',
				},
				{
					key: 'meetingMode',
					label: this.t('decidesk', 'Mode'),
					sortable: true,
				},
				{
					key: 'lifecycle',
					label: this.t('decidesk', 'Status'),
					sortable: true,
				},
			],
			actions: [
				{
					label: this.t('decidesk', 'Add meeting'),
					callback: () => {
						this.$router.push({ name: 'MeetingDetail', params: { id: 'new' } })
					},
				},
			],
		}
	},

	methods: {
		/**
		 * Navigate to the meeting detail view on row click.
		 *
		 * @param {object} row The clicked row data
		 *
		 * @spec openspec/changes/p2-meeting-management/tasks.md#task-5
		 */
		onRowClick(row) {
			const id = row.id || row.uuid
			if (id) {
				this.$router.push({ name: 'MeetingDetail', params: { id } })
			}
		},
	},
}
</script>
