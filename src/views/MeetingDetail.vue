<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-meeting-management/tasks.md#task-6
-->
<template>
	<div class="meeting-detail">
		<CnDetailPage
			v-if="!loading && meeting.id"
			:title="meeting.title || t('decidesk', 'Meeting')"
			:description="meetingSubtitle">
			<template #header-actions>
				<NcButton v-if="!isNew"
					type="secondary"
					@click="showEditDialog = true">
					{{ t('decidesk', 'Edit') }}
				</NcButton>
				<NcButton v-if="!isNew"
					type="error"
					@click="showDeleteDialog = true">
					{{ t('decidesk', 'Delete') }}
				</NcButton>
			</template>

			<!-- Meeting properties card -->
			<CnDetailCard :title="t('decidesk', 'Meeting details')">
				<CnDetailGrid :items="detailItems" />
			</CnDetailCard>

			<!-- Lifecycle transition card -->
			<CnDetailCard :title="t('decidesk', 'Lifecycle')">
				<div class="meeting-detail__lifecycle">
					<CnStatusBadge
						:label="lifecycleLabel"
						:variant="lifecycleVariant" />
					<div v-if="isChairOrSecretary && availableTransitions.length > 0"
						class="meeting-detail__transitions">
						<NcButton
							v-for="tr in availableTransitions"
							:key="tr.action"
							:type="tr.primary ? 'primary' : 'secondary'"
							:disabled="meetingStore.loading"
							@click="doTransition(tr.action)">
							{{ tr.label }}
						</NcButton>
					</div>
					<p v-else-if="!isChairOrSecretary && availableTransitions.length > 0"
						class="meeting-detail__hint">
						{{ t('decidesk', 'Only the chair or secretary can change the meeting state.') }}
					</p>
					<p v-if="transitionError" class="meeting-detail__error">
						{{ transitionError }}
					</p>
				</div>
			</CnDetailCard>

			<!-- Sidebar for files, notes, audit trail -->
			<template #sidebar>
				<CnObjectSidebar
					:object-id="meetingId"
					:schema-slug="'meeting'"
					:register-slug="'decidesk'" />
			</template>
		</CnDetailPage>

		<!-- New meeting form -->
		<CnFormDialog
			v-if="isNew"
			:title="t('decidesk', 'New meeting')"
			:schema-slug="'meeting'"
			:register-slug="'decidesk'"
			:open="true"
			@close="$router.push({ name: 'MeetingList' })"
			@submit="onCreateSubmit" />

		<!-- Edit dialog -->
		<CnFormDialog
			v-if="showEditDialog && meeting.id"
			:title="t('decidesk', 'Edit meeting')"
			:schema-slug="'meeting'"
			:register-slug="'decidesk'"
			:object="meeting"
			:open="true"
			@close="showEditDialog = false"
			@submit="onEditSubmit" />

		<!-- Delete dialog -->
		<CnDeleteDialog
			v-if="showDeleteDialog && meeting.id"
			:open="true"
			:title="t('decidesk', 'Delete meeting')"
			:description="t('decidesk', 'Are you sure you want to delete this meeting? This action cannot be undone.')"
			@close="showDeleteDialog = false"
			@confirm="onDeleteConfirm" />

		<!-- Loading state -->
		<div v-if="loading" class="meeting-detail__loading">
			<NcLoadingIcon :size="64" />
		</div>
	</div>
</template>

<script>
import {
	CnDetailCard,
	CnDetailGrid,
	CnDetailPage,
	CnDeleteDialog,
	CnFormDialog,
	CnObjectSidebar,
	CnStatusBadge,
} from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { useObjectStore } from '../store/modules/object.js'
import { useMeetingStore } from '../store/modules/meeting.js'

/**
 * Lifecycle transition definitions per state.
 */
const TRANSITION_MAP = {
	draft: [
		{ action: 'schedule', labelKey: 'Schedule', primary: true },
	],
	scheduled: [
		{ action: 'open', labelKey: 'Open meeting', primary: true },
	],
	opened: [
		{ action: 'pause', labelKey: 'Pause', primary: false },
		{ action: 'adjourn', labelKey: 'Adjourn', primary: false },
		{ action: 'close', labelKey: 'Close meeting', primary: true },
	],
	paused: [
		{ action: 'resume', labelKey: 'Resume', primary: true },
	],
	adjourned: [
		{ action: 'resume', labelKey: 'Resume', primary: true },
	],
}

/**
 * Meeting detail view with lifecycle management, edit/delete, and sidebar.
 *
 * @spec openspec/changes/p2-meeting-management/tasks.md#task-6
 */
export default {
	name: 'MeetingDetail',

	components: {
		CnDetailCard,
		CnDetailGrid,
		CnDetailPage,
		CnDeleteDialog,
		CnFormDialog,
		CnObjectSidebar,
		CnStatusBadge,
		NcButton,
		NcLoadingIcon,
	},

	props: {
		id: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			meeting: {},
			loading: true,
			showEditDialog: false,
			showDeleteDialog: false,
			transitionError: '',
			currentUserRole: 'none',
		}
	},

	computed: {
		meetingId() {
			return this.id
		},

		isNew() {
			return this.meetingId === 'new'
		},

		meetingStore() {
			return useMeetingStore()
		},

		meetingSubtitle() {
			const parts = []
			if (this.meeting.meetingType) {
				parts.push(this.typeLabel(this.meeting.meetingType))
			}
			if (this.meeting.scheduledDate) {
				parts.push(new Date(this.meeting.scheduledDate).toLocaleString())
			}
			if (this.meeting.location) {
				parts.push(this.meeting.location)
			}
			return parts.join(' — ')
		},

		detailItems() {
			return [
				{ label: this.t('decidesk', 'Title'), value: this.meeting.title || '' },
				{ label: this.t('decidesk', 'Type'), value: this.typeLabel(this.meeting.meetingType) },
				{ label: this.t('decidesk', 'Scheduled date'), value: this.meeting.scheduledDate ? new Date(this.meeting.scheduledDate).toLocaleString() : '' },
				{ label: this.t('decidesk', 'End date'), value: this.meeting.endDate ? new Date(this.meeting.endDate).toLocaleString() : '' },
				{ label: this.t('decidesk', 'Location'), value: this.meeting.location || '' },
				{ label: this.t('decidesk', 'Mode'), value: this.modeLabel(this.meeting.meetingMode) },
				{ label: this.t('decidesk', 'Quorum required'), value: this.meeting.quorumRequired ? String(this.meeting.quorumRequired) : '' },
				{ label: this.t('decidesk', 'Series'), value: this.meeting.series || '' },
			]
		},

		lifecycleLabel() {
			const labels = {
				draft: this.t('decidesk', 'Draft'),
				scheduled: this.t('decidesk', 'Scheduled'),
				opened: this.t('decidesk', 'Opened'),
				paused: this.t('decidesk', 'Paused'),
				adjourned: this.t('decidesk', 'Adjourned'),
				closed: this.t('decidesk', 'Closed'),
			}
			return labels[this.meeting.lifecycle] || this.meeting.lifecycle || ''
		},

		lifecycleVariant() {
			const variants = {
				draft: 'default',
				scheduled: 'primary',
				opened: 'success',
				paused: 'info',
				adjourned: 'warning',
				closed: 'error',
			}
			return variants[this.meeting.lifecycle] || 'default'
		},

		availableTransitions() {
			const state = this.meeting.lifecycle || 'draft'
			const transitions = TRANSITION_MAP[state] || []
			return transitions.map((tr) => ({
				...tr,
				label: this.t('decidesk', tr.labelKey),
			}))
		},

		isChairOrSecretary() {
			return this.currentUserRole === 'chair'
				|| this.currentUserRole === 'voorzitter'
				|| this.currentUserRole === 'secretary'
				|| this.currentUserRole === 'secretaris'
		},
	},

	async created() {
		if (!this.isNew) {
			await this.fetchMeeting()
			await this.fetchUserRole()
		}
		this.loading = false
	},

	methods: {
		/**
		 * Fetch the meeting object from OpenRegister.
		 *
		 * @spec openspec/changes/p2-meeting-management/tasks.md#task-6
		 */
		async fetchMeeting() {
			const objectStore = useObjectStore()
			try {
				const results = await objectStore.fetchObjects('meeting', { id: this.meetingId })
				if (Array.isArray(results) && results.length > 0) {
					this.meeting = results[0]
				} else if (results && !Array.isArray(results)) {
					this.meeting = results
				}
			} catch (error) {
				console.error('Failed to fetch meeting:', error)
			}
		},

		/**
		 * Fetch the current user's role for this meeting.
		 *
		 * @spec openspec/changes/p2-meeting-management/tasks.md#task-6
		 */
		async fetchUserRole() {
			const meetingStore = useMeetingStore()
			this.currentUserRole = await meetingStore.fetchUserRole(this.meetingId)
		},

		/**
		 * Execute a lifecycle transition.
		 *
		 * @param {string} transition The transition name
		 *
		 * @spec openspec/changes/p2-meeting-management/tasks.md#task-6
		 */
		async doTransition(transition) {
			this.transitionError = ''
			const meetingStore = useMeetingStore()
			try {
				const result = await meetingStore.transitionLifecycle(this.meetingId, transition)
				if (result && result.success) {
					this.meeting = { ...this.meeting, lifecycle: result.currentState }
				}
			} catch (error) {
				this.transitionError = error.message || this.t('decidesk', 'Transition failed')
			}
		},

		/**
		 * Handle new meeting creation.
		 *
		 * @param {object} data The form data
		 *
		 * @spec openspec/changes/p2-meeting-management/tasks.md#task-6
		 */
		onCreateSubmit(data) {
			const id = data.id || data.uuid
			if (id) {
				this.$router.replace({ name: 'MeetingDetail', params: { id } })
			}
		},

		/**
		 * Handle meeting edit submission.
		 *
		 * @param {object} data The updated form data
		 *
		 * @spec openspec/changes/p2-meeting-management/tasks.md#task-6
		 */
		async onEditSubmit(data) {
			this.showEditDialog = false
			this.meeting = { ...this.meeting, ...data }
		},

		/**
		 * Handle meeting deletion.
		 *
		 * @spec openspec/changes/p2-meeting-management/tasks.md#task-6
		 */
		async onDeleteConfirm() {
			const objectStore = useObjectStore()
			try {
				const meetingId = this.meeting.id || this.meeting.uuid
				await objectStore.deleteObject('meeting', meetingId)
				this.$router.push({ name: 'MeetingList' })
			} catch (error) {
				console.error('Failed to delete meeting:', error)
			}
		},

		/**
		 * Get a human-readable label for a meeting type.
		 *
		 * @param {string} type The meeting type
		 * @return {string} The label
		 */
		typeLabel(type) {
			const labels = {
				regular: this.t('decidesk', 'Regular'),
				extraordinary: this.t('decidesk', 'Extraordinary'),
				committee: this.t('decidesk', 'Committee'),
				'public hearing': this.t('decidesk', 'Public hearing'),
			}
			return labels[type] || type || ''
		},

		/**
		 * Get a human-readable label for a meeting mode.
		 *
		 * @param {string} mode The meeting mode
		 * @return {string} The label
		 */
		modeLabel(mode) {
			const labels = {
				'in-person': this.t('decidesk', 'In-person'),
				digital: this.t('decidesk', 'Digital'),
				hybrid: this.t('decidesk', 'Hybrid'),
			}
			return labels[mode] || mode || ''
		},
	},
}
</script>

<style scoped>
.meeting-detail__loading {
	display: flex;
	justify-content: center;
	align-items: center;
	height: 100%;
	min-height: 200px;
}

.meeting-detail__lifecycle {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.meeting-detail__transitions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.meeting-detail__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.meeting-detail__error {
	color: var(--color-error);
	margin: 0;
}
</style>
