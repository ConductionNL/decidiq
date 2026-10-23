<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  Meeting-scoped facet: the incoming documents on this meeting's agenda.
  Read-only. Since documents-as-agenda-items an incoming letter or council
  information letter is an agenda item whose type is an incoming document
  type, so this widget reads the meeting's agenda items and the agenda item
  types, and keeps the items of an incoming type (src/utils/incomingDocuments.js).
  It used to read the retired raadsinformatiebrief and ingekomen-stuk schemas
  and showed nothing new (agenda-incoming-documents-list, age-13).

  Uses the `cnObjectContext` inject (the same channel CnObjectListWidget
  itself resolves `@objectId` from) rather than relying solely on an
  `objectId` prop, so the fetch does not depend on which body-widget render
  path mounts it.

  @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#requirement-req-aidl-001-incoming-documents-reach-the-agenda
-->
<template>
	<div
		class="decidiq-tab decidiq-tab--routed-documents"
		data-testid="meeting-routed-documents-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Incoming documents') }}
				<span v-if="!loading" class="decidiq-tab__count"
					>({{ rows.length }})</span
				>
			</h3>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load routed documents')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			rowKey="id"
			:emptyText="
				t('decidiq', 'No incoming documents routed to this meeting yet.')
			"
			:loadingText="t('decidiq', 'Loading routed documents…')"
			@rowClick="openDetail" />
	</div>
</template>

<script>
import { CnDataTable, CnNoteCard } from '@conduction/nextcloud-vue'
import {
	incomingItems,
	incomingRows,
	incomingTypes,
} from '../../utils/incomingDocuments.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MeetingRoutedDocumentsTab',

	components: { CnDataTable, CnNoteCard },

	inject: {
		cnObjectContext: { default: null },
	},

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			rows: [],
		}
	},

	computed: {
		/**
		 * The current meeting's object id. Prefers an explicit `objectId`
		 * prop; falls back to the CnDetailPage-provided `cnObjectContext`.
		 *
		 * @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#requirement-req-aidl-001-incoming-documents-reach-the-agenda
		 * @return {string}
		 */
		resolvedObjectId() {
			if (this.objectId) return String(this.objectId)
			const ctx = this.cnObjectContext
			const value =
				ctx && typeof ctx === 'object' && 'value' in ctx ? ctx.value : ctx
			return (value && value.objectId) || ''
		},

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
			]
		},
	},

	watch: {
		resolvedObjectId: {
			immediate: true,
			/** @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#requirement-req-aidl-001-incoming-documents-reach-the-agenda */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/** @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#requirement-req-aidl-001-incoming-documents-reach-the-agenda */
		async refresh() {
			if (!this.resolvedObjectId) return
			this.loading = true
			this.error = ''
			try {
				const [items, types] = await Promise.all([
					ensureRelationType('agenda-item').fetchCollection(
						'agenda-item',
						{
							meeting: this.resolvedObjectId,
							_limit: 200,
						},
					),
					ensureRelationType('agenda-item-type').fetchCollection(
						'agenda-item-type',
						{ _limit: 200 },
					),
				])
				const incoming = incomingTypes(types)
				this.rows = incomingRows(incomingItems(items, incoming), incoming)
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'Failed to load routed documents.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open the agenda item a row stands for.
		 *
		 * @param {object} row The row (carries `id`).
		 * @spec openspec/changes/agenda-incoming-documents-list/specs/agenda-management/spec.md#requirement-req-aidl-001-incoming-documents-reach-the-agenda
		 */
		openDetail(row) {
			if (!row?.id) return
			this.$router.push({ name: 'AgendaItemDetail', params: { id: row.id } })
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
