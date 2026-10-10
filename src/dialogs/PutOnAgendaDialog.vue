<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  Dialog: put an incoming document on a meeting's agenda. The griffier picks
  a meeting; the parent saves the agenda item with that meeting on @submit
  (agenda-incoming-documents-list, age-13).

  @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#scenario-putting-a-letter-on-the-agenda
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Put on agenda')"
		data-testid="put-on-agenda-dialog"
		@closing="$emit('close')">
		<template #default>
			<p>
				{{
					t('decidiq', 'Choose the meeting that deals with "{title}".', {
						title: documentTitle,
					})
				}}
			</p>
			<NcSelect
				v-model="meeting"
				:inputLabel="t('decidiq', 'Meeting')"
				:options="meetingOptions"
				label="label"
				:clearable="false" />
			<CnNoteCard v-if="meetings.length === 0" type="info">
				{{ t('decidiq', 'There is no upcoming meeting to put it on.') }}
			</CnNoteCard>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="put-on-agenda-confirm"
				:disabled="!meeting || saving"
				@click="$emit('submit', meeting.id)">
				{{ t('decidiq', 'Put on agenda') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'

export default {
	name: 'PutOnAgendaDialog',

	components: { CnNoteCard, NcButton, NcDialog, NcSelect },

	props: {
		/** Title of the incoming document (for the dialog copy) */
		documentTitle: { type: String, default: '' },
		/** Meetings to choose from: objects with id, title and scheduledDate */
		meetings: { type: Array, default: () => [] },
		/** True while the parent saves */
		saving: { type: Boolean, default: false },
	},

	emits: ['submit', 'close'],

	data() {
		return {
			meeting: null,
		}
	},

	computed: {
		/** @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#scenario-putting-a-letter-on-the-agenda */
		meetingOptions() {
			return this.meetings.map((m) => ({
				id: m.id,
				label: m.scheduledDate
					? `${m.title} (${new Date(m.scheduledDate).toLocaleDateString()})`
					: m.title,
			}))
		},
	},
}
</script>
