<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: action items spawned by a Decision.

 Posture: full CRUD. Decisions own their action items via
 `action-item.decision`; this tab fetches them, renders them with
 status pills (matching the standalone /action-items index), and
 lets the user add / edit / delete inline.
-->
<template>
	<div class="decidesk-tab decidesk-tab--action-items" data-testid="decision-action-items-tab">
		<div class="decidesk-tab__header">
			<h3 class="decidesk-tab__title">
				{{ t('decidesk', 'Action items') }}
				<span v-if="!loading" class="decidesk-tab__count">({{ rows.length }})</span>
			</h3>
			<NcButton
				type="primary"
				data-testid="decision-action-items-add"
				:aria-label="t('decidesk', 'Add action item')"
				@click="openCreate">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidesk', 'Add action item') }}
			</NcButton>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Could not load action items')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			row-key="id"
			:empty-text="t('decidesk', 'No action items spawned by this decision yet.')"
			:loading-text="t('decidesk', 'Loading action items…')"
			@row-click="openEdit">
			<template #column-taskStatus="{ value }">
				<CnStatusBadge v-if="value" :label="value" :color-map="statusColors" />
			</template>
			<template #row-actions="{ row }">
				<CnRowActions :row="row" :actions="rowActions" />
			</template>
		</CnDataTable>

		<CnFormDialog
			v-if="formOpen"
			ref="formDialog"
			:schema="actionItemSchema"
			:item="editTarget"
			:dialog-title="editTarget ? t('decidesk', 'Edit action item') : t('decidesk', 'Add action item')"
			:exclude-fields="excludedFields"
			@confirm="onConfirm"
			@close="formOpen = false" />

		<CnDeleteDialog
			v-if="deleteTarget"
			ref="deleteDialog"
			:item="deleteTarget"
			name-field="title"
			:dialog-title="t('decidesk', 'Delete action item')"
			@confirm="confirmDelete"
			@close="deleteTarget = null" />
	</div>
</template>

<script>
import { CnDataTable, CnDeleteDialog, CnFormDialog, CnNoteCard, CnRowActions, CnStatusBadge } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { ensureRelationType } from './useRelationStore.js'
import { createActionItem, updateActionItem, deleteActionItem } from '../../services/actionItemApi.js'

export default {
	name: 'DecisionActionItemsTab',
	components: { CnDataTable, CnDeleteDialog, CnFormDialog, CnNoteCard, CnRowActions, CnStatusBadge, NcButton, Plus },
	props: {
		objectId: { type: [String, Number], default: '' },
	},
	data() {
		return {
			loading: false,
			error: '',
			rows: [],
			actionItemSchema: null,
			formOpen: false,
			editTarget: null,
			deleteTarget: null,
		}
	},
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-25-relation-tab-ui/tasks.md#task-1 */
		columns() {
			return [
				{ key: 'title', label: this.t('decidesk', 'Title') },
				{ key: 'assignee', label: this.t('decidesk', 'Assignee') },
				{ key: 'dueDate', label: this.t('decidesk', 'Due') },
				{ key: 'taskStatus', label: this.t('decidesk', 'Status') },
			]
		},
		/** @spec openspec/changes/retrofit-2026-05-25-relation-tab-ui/tasks.md#task-2 */
		statusColors() {
			return {
				open: 'primary',
				'in-progress': 'warning',
				completed: 'success',
				overdue: 'error',
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-25-relation-tab-ui/tasks.md#task-2 */
		rowActions() {
			return [
				{ label: this.t('decidesk', 'Edit'), icon: Pencil, handler: (row) => this.openEdit(row) },
				{ label: this.t('decidesk', 'Delete'), icon: TrashCanOutline, destructive: true, handler: (row) => { this.deleteTarget = { ...row } } },
			]
		},
		/** @spec openspec/changes/retrofit-2026-05-25-relation-tab-ui/tasks.md#task-1 */
		excludedFields() {
			return ['id', 'uuid', 'decision', 'created', 'updated']
		},
	},
	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/retrofit-2026-05-25-relation-tab-ui/tasks.md#task-1 */
			handler() { this.refresh() },
		},
	},
	methods: {
		/** @spec openspec/changes/retrofit-2026-05-25-relation-tab-ui/tasks.md#task-1 */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const store = ensureRelationType('action-item')
				if (!this.actionItemSchema) this.actionItemSchema = await store.fetchSchema('action-item')
				const items = await store.fetchCollection('action-item', {
					decision: this.objectId,
					_limit: 100,
				})
				this.rows = items || []
			} catch (e) {
				this.error = e?.message || this.t('decidesk', 'Failed to load action items.')
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-25-relation-tab-ui/tasks.md#task-1 */
		async openCreate() {
			const store = ensureRelationType('action-item')
			if (!this.actionItemSchema) this.actionItemSchema = await store.fetchSchema('action-item')
			this.editTarget = null
			this.formOpen = true
		},
		/** @spec openspec/changes/retrofit-2026-05-25-relation-tab-ui/tasks.md#task-1 */
		async openEdit(row) {
			const store = ensureRelationType('action-item')
			if (!this.actionItemSchema) this.actionItemSchema = await store.fetchSchema('action-item')
			this.editTarget = { ...row }
			this.formOpen = true
		},
		/** @spec openspec/changes/retrofit-2026-05-25-relation-tab-ui/tasks.md#task-1 */
		async onConfirm(formData) {
			// Action items are read-only VTODO projections — write via the VTODO
			// endpoints (action-items-vtodo-deck-reconcile), not the object API.
			try {
				const uid = this.editTarget && (this.editTarget.uuid || this.editTarget.id || this.editTarget['@self']?.uuid)
				if (uid) {
					await updateActionItem(uid, { ...formData, decision: this.objectId })
				} else {
					await createActionItem({ ...formData, decision: this.objectId })
				}
				this.$refs.formDialog?.setResult({ success: true })
				this.refresh()
			} catch (e) {
				this.$refs.formDialog?.setResult({ error: e?.message || this.t('decidesk', 'Save failed.') })
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-25-relation-tab-ui/tasks.md#task-1 */
		async confirmDelete() {
			try {
				const uid = this.deleteTarget && (this.deleteTarget.uuid || this.deleteTarget.id || this.deleteTarget['@self']?.uuid)
				await deleteActionItem(uid)
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
