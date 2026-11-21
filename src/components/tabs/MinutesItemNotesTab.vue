<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Minutes page widget: the per-item minutes editor (agenda-meeting-page-item-tools,
 REQ-AMP-005).

 A thin wrapper around MinutesPanel, the editor the live meeting screen uses,
 so a secretary can write or finish the minutes per agenda item from the
 minutes page instead of the live screen. It loads the minutes record, its
 meeting's regular agenda items and that meeting's participants, and hands the
 record's own id to the panel so the notes land on this record. The panel is
 editable while the minutes are in draft and read-only after that.
-->
<template>
	<div
		class="decidiq-tab decidiq-tab--item-notes"
		data-testid="minutes-item-notes-tab">
		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load the agenda of these minutes')">
			{{ error }}
		</CnNoteCard>
		<NcLoadingIcon v-else-if="loading" :size="32" />
		<p v-else-if="!meetingId" class="decidiq-tab__empty">
			{{
				t(
					'decidiq',
					'These minutes are not linked to a meeting, so there are no agenda items to take notes on.',
				)
			}}
		</p>
		<MinutesPanel
			v-else
			:meetingId="meetingId"
			:minutesId="String(objectId)"
			:agendaItems="agendaItems"
			:participants="participants" />
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { NcLoadingIcon } from '@nextcloud/vue'
import MinutesPanel from '../minutesEditor/MinutesPanel.vue'
import {
	meetingParticipants,
	regularAgendaItems,
} from '../minutesEditor/minutesEditor.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MinutesItemNotesTab',
	components: { CnNoteCard, MinutesPanel, NcLoadingIcon },

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			meetingId: '',
			agendaItems: [],
			participants: [],
		}
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/resolution-minutes/spec.md#requirement-req-amp-005-the-minutes-page-carries-the-per-item-minutes-editor */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/**
		 * Load the minutes' meeting, its regular agenda items and its
		 * participants.
		 *
		 * @spec openspec/specs/resolution-minutes/spec.md#requirement-req-amp-005-the-minutes-page-carries-the-per-item-minutes-editor
		 */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const minutes = await ensureRelationType('minutes').fetchObject(
					'minutes',
					this.objectId,
				)
				this.meetingId = minutes?.meeting ? String(minutes.meeting) : ''
				if (!this.meetingId) return
				const [items, participants] = await Promise.all([
					ensureRelationType('agenda-item').fetchCollection(
						'agenda-item',
						{
							meeting: this.meetingId,
							_limit: 200,
						},
					),
					ensureRelationType('participant').fetchCollection(
						'participant',
						{
							_limit: 200,
						},
					),
				])
				this.agendaItems = regularAgendaItems(items)
				this.participants = meetingParticipants(participants, this.meetingId)
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'Failed to load minutes.')
			} finally {
				this.loading = false
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

.decidiq-tab__empty {
	color: var(--color-text-maxcontrast);
}
</style>
