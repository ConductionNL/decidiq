<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: participants of a Meeting and their attendance.

 The rows are the members of the meeting's body plus any guest added to
 this meeting. Attendance is recorded per meeting as one meeting-attendance
 object per participant (meeting-attendance-per-meeting, pla-09), so marking
 someone on this meeting leaves their other meetings unchanged.
-->
<template>
	<div
		class="decidiq-tab decidiq-tab--participants"
		data-testid="meeting-participants-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Participants') }}
				<span v-if="!loading" class="decidiq-tab__count"
					>({{ rows.length }})</span
				>
			</h3>
			<div class="decidiq-tab__actions">
				<NcButton
					data-testid="meeting-participants-everyone-present"
					:disabled="saving || rows.length === 0"
					@click="markEveryonePresent">
					<template #icon>
						<AccountCheck :size="20" />
					</template>
					{{ t('decidiq', 'Everyone present') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="meeting-participants-add"
					:aria-label="t('decidiq', 'Add participant')"
					@click="addDialogOpen = true">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('decidiq', 'Add participant') }}
				</NcButton>
			</div>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load participants')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="tableRows"
			:loading="loading"
			rowKey="id"
			:emptyText="t('decidiq', 'No participants linked to this meeting yet.')"
			:loadingText="t('decidiq', 'Loading participants…')">
			<template #row-actions="{ row }">
				<CnRowActions :row="row" :actions="actionsFor(row)" />
			</template>
		</CnDataTable>

		<MeetingParticipantAddDialog
			v-if="addDialogOpen"
			:candidates="candidates"
			:loading="loadingCandidates"
			@select="linkParticipant"
			@close="addDialogOpen = false" />

		<CnDeleteDialog
			v-if="removeTarget"
			ref="removeDialog"
			:item="removeTarget"
			nameField="displayName"
			:dialogTitle="t('decidiq', 'Remove from meeting')"
			@confirm="confirmRemove"
			@close="removeTarget = null" />
	</div>
</template>

<script>
import {
	CnDataTable,
	CnDeleteDialog,
	CnNoteCard,
	CnRowActions,
} from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import AccountCheck from 'vue-material-design-icons/AccountCheck.vue'
import AccountClock from 'vue-material-design-icons/AccountClock.vue'
import AccountMinus from 'vue-material-design-icons/AccountMinus.vue'
import AccountSwitch from 'vue-material-design-icons/AccountSwitch.vue'
import LinkOff from 'vue-material-design-icons/LinkOff.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import MeetingParticipantAddDialog from '../../dialogs/MeetingParticipantAddDialog.vue'
import {
	attendancePayload,
	attendanceRows,
	everyonePresent,
	refId,
} from '../../utils/meetingAttendance.js'
import { ensureRelationType } from './useRelationStore.js'

const ATTENDANCE = 'meeting-attendance'

export default {
	name: 'MeetingParticipantsTab',
	components: {
		AccountCheck,
		CnDataTable,
		CnDeleteDialog,
		CnNoteCard,
		CnRowActions,
		MeetingParticipantAddDialog,
		NcButton,
		Plus,
	},

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			saving: false,
			error: '',
			members: [],
			records: [],
			everyone: [],
			addDialogOpen: false,
			loadingCandidates: false,
			candidates: [],
			removeTarget: null,
		}
	},

	computed: {
		/** @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting */
		meetingId() {
			return String(this.objectId || '')
		},

		/** @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting */
		rows() {
			return attendanceRows(
				this.members,
				this.records,
				this.meetingId,
				this.everyone,
			)
		},

		/** @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting */
		tableRows() {
			return this.rows.map((row) => ({
				...row,
				attendanceLabel: this.statusLabel(row.attendance),
			}))
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		columns() {
			return [
				{ key: 'displayName', label: this.t('decidiq', 'Name') },
				{ key: 'role', label: this.t('decidiq', 'Role') },
				{ key: 'party', label: this.t('decidiq', 'Party') },
				{ key: 'attendanceLabel', label: this.t('decidiq', 'Attendance') },
			]
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/relation-tab-ui/spec.md */
			handler() {
				this.refresh()
			},
		},

		/**
		 * @param open
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		addDialogOpen(open) {
			if (open) this.loadCandidates()
		},
	},

	methods: {
		/**
		 * @param {string} status The attendance status
		 * @return {string} The label
		 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
		 */
		statusLabel(status) {
			return (
				{
					present: this.t('decidiq', 'Present'),
					absent: this.t('decidiq', 'Absent'),
					excused: this.t('decidiq', 'Sent apologies'),
					proxy: this.t('decidiq', 'Represented by proxy'),
				}[status] || this.t('decidiq', 'Not recorded')
			)
		},

		/**
		 * @param {object} row The widget row
		 * @return {Array<object>} The row actions
		 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
		 */
		actionsFor(row) {
			const set = (status) => () => this.setStatus(row, status)
			const actions = [
				{
					label: this.t('decidiq', 'Mark present'),
					icon: AccountCheck,
					handler: set('present'),
				},
				{
					label: this.t('decidiq', 'Mark absent'),
					icon: AccountMinus,
					handler: set('absent'),
				},
				{
					label: this.t('decidiq', 'Mark as sent apologies'),
					icon: AccountClock,
					handler: set('excused'),
				},
				{
					label: this.t('decidiq', 'Mark as represented by proxy'),
					icon: AccountSwitch,
					handler: set('proxy'),
				},
			]
			const isMember = this.members.some((m) => refId(m) === refId(row))
			if (!isMember && row.attendanceRecord) {
				actions.push({
					label: this.t('decidiq', 'Remove from meeting'),
					icon: LinkOff,
					destructive: true,
					handler: () => {
						this.removeTarget = { ...row }
					},
				})
			}
			return actions
		},

		/** @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting */
		async refresh() {
			if (!this.meetingId) return
			this.loading = true
			this.error = ''
			try {
				const meetingStore = ensureRelationType('meeting')
				const meeting = await meetingStore.fetchObject(
					'meeting',
					this.meetingId,
				)
				const bodyId = refId(meeting?.governanceBody)
				const store = ensureRelationType('participant')
				const attendanceStore = ensureRelationType(ATTENDANCE)
				const [participants, records] = await Promise.all([
					store.fetchCollection('participant', { _limit: 500 }),
					attendanceStore.fetchCollection(ATTENDANCE, {
						meeting: this.meetingId,
						_limit: 500,
					}),
				])
				this.everyone = participants || []
				this.members = bodyId
					? this.everyone.filter((p) => refId(p.governanceBody) === bodyId)
					: []
				this.records = records || []
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'Failed to load participants.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} payload The attendance object
		 * @return {Promise<object>} The saved object
		 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
		 */
		async saveRecord(payload) {
			const store = ensureRelationType(ATTENDANCE)
			return store.saveObject(ATTENDANCE, payload)
		},

		/**
		 * @param {object} row The widget row
		 * @param {string} status The attendance status
		 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
		 */
		async setStatus(row, status) {
			this.saving = true
			this.error = ''
			try {
				await this.saveRecord(
					attendancePayload(row.attendanceRecord, {
						meetingId: this.meetingId,
						participantId: refId(row),
						status,
						now: new Date().toISOString(),
					}),
				)
				await this.refresh()
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'The attendance could not be saved.')
			} finally {
				this.saving = false
			}
		},

		/** @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting */
		async markEveryonePresent() {
			this.saving = true
			this.error = ''
			try {
				const now = new Date().toISOString()
				for (const payload of everyonePresent(
					this.rows,
					this.meetingId,
					now,
				)) {
					await this.saveRecord(payload)
				}
				await this.refresh()
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'The attendance could not be saved.')
			} finally {
				this.saving = false
			}
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async loadCandidates() {
			this.loadingCandidates = true
			try {
				const store = ensureRelationType('participant')
				const items = await store.fetchCollection('participant', {
					_limit: 500,
				})
				const listed = new Set(this.rows.map((row) => refId(row)))
				this.candidates = (items || []).filter((p) => !listed.has(refId(p)))
			} catch {
				this.candidates = []
			} finally {
				this.loadingCandidates = false
			}
		},

		/**
		 * A guest joins this meeting as present.
		 *
		 * @param {object} participant The participant picked in the dialog
		 * @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting
		 */
		async linkParticipant(participant) {
			this.addDialogOpen = false
			await this.setStatus(
				{ ...participant, attendanceRecord: null },
				'present',
			)
		},

		/** @spec openspec/specs/meeting-attendees/spec.md#requirement-req-mapm-001-attendance-is-recorded-per-meeting */
		async confirmRemove() {
			const store = ensureRelationType(ATTENDANCE)
			const record = this.removeTarget?.attendanceRecord
			try {
				await store.deleteObject(ATTENDANCE, refId(record))
				this.$refs.removeDialog?.setResult({ success: true })
				this.refresh()
			} catch (e) {
				this.$refs.removeDialog?.setResult({
					error: e?.message || this.t('decidiq', 'Remove failed.'),
				})
			}
		},
	},
}
</script>

<style scoped>
.decidiq-tab {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
}

.decidiq-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.decidiq-tab__actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.decidiq-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}

.decidiq-tab__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	margin-inline-start: 4px;
}
</style>
