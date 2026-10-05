<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
  IncomingDocumentsView: the incoming documents that wait for a meeting.
  An incoming document is an agenda item whose type is an incoming document
  type and that has no meeting yet. Put on agenda picks a meeting and saves
  the item with that meeting and the next free position, after which it
  leaves this list and shows on the meeting's Incoming documents widget
  (agenda-incoming-documents-list, age-13).

  type:"custom" because "type is one of the flagged types AND meeting is
  empty" is a two-object condition no declarative index filter can express.

  @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#requirement-req-aidl-001-incoming-documents-reach-the-agenda
-->
<template>
	<div class="incoming-documents" data-testid="incoming-documents">
		<h2 class="incoming-documents__title">
			{{ t('decidiq', 'Incoming documents') }}
		</h2>
		<p class="incoming-documents__intro">
			{{
				t(
					'decidiq',
					'Letters and documents that came in and wait for a meeting.',
				)
			}}
		</p>

		<CnNoteCard v-if="error" type="error">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			rowKey="id"
			:emptyText="t('decidiq', 'No incoming documents wait for a meeting.')"
			:loadingText="t('decidiq', 'Loading incoming documents…')">
			<template #column-actions="{ row }">
				<NcButton data-testid="put-on-agenda" @click.stop="choose(row)">
					{{ t('decidiq', 'Put on agenda') }}
				</NcButton>
			</template>
		</CnDataTable>

		<PutOnAgendaDialog
			v-if="chosen"
			:documentTitle="chosen.title"
			:meetings="meetings"
			:saving="saving"
			@submit="putOnAgenda"
			@close="chosen = null" />
	</div>
</template>

<script>
import { CnDataTable, CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import PutOnAgendaDialog from '../../dialogs/PutOnAgendaDialog.vue'
import { ensureRelationType } from '../../components/tabs/useRelationStore.js'
import {
	incomingRows,
	incomingTypes,
	putOnAgendaPayload,
	waitingItems,
} from '../../utils/incomingDocuments.js'

export default {
	name: 'IncomingDocumentsView',

	components: { CnDataTable, CnNoteCard, NcButton, PutOnAgendaDialog },

	data() {
		return {
			loading: false,
			saving: false,
			error: '',
			waiting: [],
			types: new Map(),
			meetings: [],
			chosen: null,
		}
	},

	computed: {
		/** @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#requirement-req-aidl-001-incoming-documents-reach-the-agenda */
		columns() {
			return [
				{
					key: 'typeLabel',
					label: this.t('decidiq', 'Type'),
					widget: 'badge',
				},
				{ key: 'title', label: this.t('decidiq', 'Title') },
				{
					key: 'lifecycle',
					label: this.t('decidiq', 'Status'),
					widget: 'badge',
				},
				{ key: 'actions', label: this.t('decidiq', 'Actions') },
			]
		},

		/** @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#requirement-req-aidl-001-incoming-documents-reach-the-agenda */
		rows() {
			return incomingRows(this.waiting, this.types)
		},
	},

	mounted() {
		this.refresh()
	},

	methods: {
		/** @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#requirement-req-aidl-001-incoming-documents-reach-the-agenda */
		async refresh() {
			this.loading = true
			this.error = ''
			try {
				const [types, items, meetings] = await Promise.all([
					ensureRelationType('agenda-item-type').fetchCollection(
						'agenda-item-type',
						{ _limit: 200 },
					),
					ensureRelationType('agenda-item').fetchCollection(
						'agenda-item',
						{ _limit: 500 },
					),
					ensureRelationType('meeting').fetchCollection('meeting', {
						_order: { scheduledDate: 'asc' },
						_limit: 100,
					}),
				])
				this.types = incomingTypes(types)
				this.waiting = waitingItems(items, this.types)
				const now = Date.now()
				this.meetings = (meetings || []).filter(
					(m) =>
						!m.scheduledDate
						|| new Date(m.scheduledDate).getTime() >= now,
				)
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'Failed to load incoming documents.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open the dialog for one waiting document.
		 *
		 * @param {object} row The table row.
		 * @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#scenario-putting-a-letter-on-the-agenda
		 */
		choose(row) {
			this.chosen = this.waiting.find((item) => item.id === row.id) || null
		},

		/**
		 * Save the chosen document on the meeting's agenda, after its last item.
		 *
		 * @param {string} meetingId The meeting picked in the dialog.
		 * @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#scenario-putting-a-letter-on-the-agenda
		 */
		async putOnAgenda(meetingId) {
			if (!this.chosen || !meetingId) return
			this.saving = true
			this.error = ''
			try {
				const store = ensureRelationType('agenda-item')
				const onMeeting = await store.fetchCollection('agenda-item', {
					meeting: meetingId,
					_limit: 500,
				})
				await store.saveObject('agenda-item', {
					...putOnAgendaPayload(this.chosen, meetingId, onMeeting),
					id: this.chosen.id,
				})
				this.chosen = null
				await this.refresh()
			} catch (e) {
				this.error =
					e?.message
					|| this.t(
						'decidiq',
						'The document could not be put on the agenda.',
					)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.incoming-documents {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 4);
}

.incoming-documents__title {
	margin: 0;
}

.incoming-documents__intro {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
