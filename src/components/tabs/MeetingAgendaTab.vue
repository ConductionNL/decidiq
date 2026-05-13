<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: agenda items for a Meeting.

 Posture: full CRUD. Meetings own their agenda items; this tab lists
 them by `meeting === parent.id`, sorted by `orderNumber`, and lets
 chair/secretary add, edit, and delete items inline. Drag-reorder from
 the deleted MeetingDetail/AgendaBuilder is left to the standalone
 /agenda-items index — out of scope for the sidebar tab.
-->
<template>
	<div class="decidesk-tab decidesk-tab--agenda" data-testid="agenda-tab">
		<div class="decidesk-tab__header">
			<h3 class="decidesk-tab__title">
				{{ t('decidesk', 'Agenda') }}
				<span v-if="!loading" class="decidesk-tab__count">({{ rows.length }})</span>
			</h3>
			<NcButton
				type="primary"
				data-testid="agenda-add-item"
				:aria-label="t('decidesk', 'Add agenda item')"
				@click="openCreate">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidesk', 'Add agenda item') }}
			</NcButton>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Could not load agenda items')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			row-key="id"
			:empty-text="t('decidesk', 'No agenda items yet for this meeting.')"
			:loading-text="t('decidesk', 'Loading agenda…')"
			@row-click="openEdit">
			<template #row-actions="{ row }">
				<CnRowActions :row="row" :actions="rowActions" />
			</template>
		</CnDataTable>

		<CnFormDialog
			v-if="formOpen"
			ref="formDialog"
			:schema="agendaSchema"
			:item="editTarget"
			:dialog-title="editTarget ? t('decidesk', 'Edit agenda item') : t('decidesk', 'Add agenda item')"
			:exclude-fields="excludedFields"
			@confirm="onConfirm"
			@close="formOpen = false" />

		<CnDeleteDialog
			v-if="deleteTarget"
			ref="deleteDialog"
			:item="deleteTarget"
			name-field="title"
			:dialog-title="t('decidesk', 'Delete agenda item')"
			@confirm="confirmDelete"
			@close="deleteTarget = null" />
	</div>
</template>

<script>
import { CnDataTable, CnDeleteDialog, CnFormDialog, CnNoteCard, CnRowActions } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MeetingAgendaTab',
	components: { CnDataTable, CnDeleteDialog, CnFormDialog, CnNoteCard, CnRowActions, NcButton, Plus },
	props: {
		objectId: { type: [String, Number], default: '' },
	},
	data() {
		return {
			loading: false,
			error: '',
			rows: [],
			agendaSchema: null,
			formOpen: false,
			editTarget: null,
			deleteTarget: null,
		}
	},
	computed: {
		columns() {
			return [
				{ key: 'orderNumber', label: this.t('decidesk', '#'), width: '60px' },
				{ key: 'title', label: this.t('decidesk', 'Title') },
				{ key: 'itemType', label: this.t('decidesk', 'Type') },
				{ key: 'estimatedDuration', label: this.t('decidesk', 'Duration (min)') },
			]
		},
		rowActions() {
			return [
				{ label: this.t('decidesk', 'Edit'), icon: Pencil, handler: (row) => this.openEdit(row) },
				{ label: this.t('decidesk', 'Delete'), icon: TrashCanOutline, destructive: true, handler: (row) => { this.deleteTarget = { ...row } } },
			]
		},
		excludedFields() {
			// Hide system / parent-link fields — we set `meeting` ourselves.
			return ['id', 'uuid', 'meeting', 'created', 'updated']
		},
	},
	watch: {
		objectId: {
			immediate: true,
			handler() { this.refresh() },
		},
	},
	methods: {
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const store = ensureRelationType('agenda-item')
				if (!this.agendaSchema) this.agendaSchema = await store.fetchSchema('agenda-item')
				const items = await store.fetchCollection('agenda-item', {
					meeting: this.objectId,
					_order: JSON.stringify({ orderNumber: 'asc' }),
					_limit: 100,
				})
				this.rows = (items || []).slice().sort((a, b) => (a.orderNumber ?? 0) - (b.orderNumber ?? 0))
			} catch (e) {
				this.error = e?.message || this.t('decidesk', 'Failed to load agenda.')
			} finally {
				this.loading = false
			}
		},
		async openCreate() {
			const store = ensureRelationType('agenda-item')
			if (!this.agendaSchema) this.agendaSchema = await store.fetchSchema('agenda-item')
			this.editTarget = null
			this.formOpen = true
		},
		async openEdit(row) {
			const store = ensureRelationType('agenda-item')
			if (!this.agendaSchema) this.agendaSchema = await store.fetchSchema('agenda-item')
			this.editTarget = { ...row }
			this.formOpen = true
		},
		async onConfirm(formData) {
			const store = ensureRelationType('agenda-item')
			try {
				await store.saveObject('agenda-item', { ...formData, meeting: this.objectId })
				this.$refs.formDialog?.setResult({ success: true })
				this.refresh()
			} catch (e) {
				this.$refs.formDialog?.setResult({ error: e?.message || this.t('decidesk', 'Save failed.') })
			}
		},
		async confirmDelete() {
			const store = ensureRelationType('agenda-item')
			try {
				await store.deleteObject('agenda-item', this.deleteTarget.id)
				this.$refs.deleteDialog?.setResult({ success: true })
				this.refresh()
			} catch (e) {
				this.$refs.deleteDialog?.setResult({ error: e?.message || this.t('decidesk', 'Delete failed.') })
			}
		},
	},
}
</script>

<style scoped>
.decidesk-tab {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
}
.decidesk-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}
.decidesk-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}
.decidesk-tab__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	margin-inline-start: 4px;
}
</style>
