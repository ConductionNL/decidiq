<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Action-item capture shortcut for the real-time minutes editor
 (minutes-ui-v1). Opened from the per-agenda-item toolbar in
 MinutesPanel.vue; creates an action-item object linked to the meeting
 (and noting the agenda item in the description) so it appears in the
 action tracking surfaces, per the resolution-minutes spec scenario
 "Record action items during minute-taking".

 @spec openspec/specs/resolution-minutes/spec.md
-->
<template>
	<NcDialog
		:name="t('decidesk', 'Add action item')"
		data-testid="minutes-action-item-modal"
		@closing="$emit('close')">
		<template #default>
			<div class="action-item-modal__form">
				<NcTextField
					v-model="title"
					data-testid="minutes-action-item-title"
					:label="t('decidesk', 'Action item title')"
					:placeholder="t('decidesk', 'e.g. Prepare budget proposal')" />
				<NcSelect
					v-model="assignee"
					:input-label="t('decidesk', 'Owner')"
					:options="assigneeOptions"
					:placeholder="t('decidesk', 'Pick a participant')" />
				<NcDateTimePickerNative
					id="minutes-action-item-due"
					v-model="dueDate"
					type="date"
					:label="t('decidesk', 'Deadline')" />
				<p v-if="error" class="action-item-modal__error" role="alert">
					{{ error }}
				</p>
			</div>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="minutes-action-item-save"
				:disabled="saving || !title.trim()"
				@click="save">
				{{ t('decidesk', 'Add action item') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ t('decidesk', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDateTimePickerNative, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import { createActionItem } from '../services/actionItemApi.js'

export default {
	name: 'ActionItemCaptureModal',
	components: { NcButton, NcDateTimePickerNative, NcDialog, NcSelect, NcTextField },
	props: {
		meetingId: { type: String, required: true },
		agendaItem: { type: Object, default: null },
		participants: { type: Array, default: () => [] },
	},
	data() {
		return {
			title: '',
			assignee: null,
			dueDate: null,
			saving: false,
			error: '',
		}
	},
	computed: {
		/** @spec openspec/specs/resolution-minutes/spec.md */
		assigneeOptions() {
			return this.participants
				.map(p => p.displayName || p.name)
				.filter(Boolean)
		},
	},
	methods: {
		/**
		 * Persist the action item linked to the meeting and agenda item.
		 *
		 * @spec openspec/specs/resolution-minutes/spec.md
		 */
		async save() {
			if (!this.title.trim() || this.saving) return
			this.saving = true
			this.error = ''
			try {
				const payload = {
					title: this.title.trim(),
					taskStatus: 'open',
					meeting: this.meetingId,
				}
				if (this.assignee) payload.assignee = this.assignee
				if (this.dueDate) {
					payload.dueDate = new Date(this.dueDate).toISOString()
				}
				if (this.agendaItem) {
					// The action-item schema relates to meeting/decision; the agenda
					// item context is kept in the description for traceability.
					payload.description = this.t(
						'decidesk',
						'Recorded during minute-taking on agenda item: {title}',
						{ title: this.agendaItem.title || this.agendaItem.id },
					)
				}
				await createActionItem(payload)
				this.$emit('saved')
				this.$emit('close')
			} catch (e) {
				this.error = e?.message || this.t('decidesk', 'Could not create the action item.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.action-item-modal__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline) 0;
}

.action-item-modal__error {
	color: var(--color-error);
	margin: 0;
}
</style>
