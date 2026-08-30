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
	<div
		class="decidiq-tab decidiq-tab--action-items"
		data-testid="decision-action-items-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Action items') }}
				<span v-if="!loading" class="decidiq-tab__count"
					>({{ rows.length }})</span
				>
			</h3>
			<NcButton
				variant="primary"
				data-testid="decision-action-items-add"
				:aria-label="t('decidiq', 'Add action item')"
				@click="openCreate">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidiq', 'Add action item') }}
			</NcButton>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load action items')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			rowKey="id"
			:emptyText="
				t('decidiq', 'No action items spawned by this decision yet.')
			"
			:loadingText="t('decidiq', 'Loading action items…')"
			@rowClick="openEdit">
			<template #column-taskStatus="{ value }">
				<CnStatusBadge
					v-if="value"
					:label="value"
					:colorMap="statusColors" />
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
			:dialogTitle="
				editTarget
					? t('decidiq', 'Edit action item')
					: t('decidiq', 'Add action item')
			"
			:excludeFields="excludedFields"
			@confirm="onConfirm"
			@close="formOpen = false" />

		<CnDeleteDialog
			v-if="deleteTarget"
			ref="deleteDialog"
			:item="deleteTarget"
			nameField="title"
			:dialogTitle="t('decidiq', 'Delete action item')"
			@confirm="confirmDelete"
			@close="deleteTarget = null" />
	</div>
</template>

<script>
import {
	CnDataTable,
	CnDeleteDialog,
	CnFormDialog,
	CnNoteCard,
	CnRowActions,
	CnStatusBadge,
} from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import {
	createActionItem,
	deleteActionItem,
	updateActionItem,
} from '../../services/actionItemApi.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'DecisionActionItemsTab',
	components: {
		CnDataTable,
		CnDeleteDialog,
		CnFormDialog,
		CnNoteCard,
		CnRowActions,
		CnStatusBadge,
		NcButton,
		Plus,
	},

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
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		columns() {
			return [
				{ key: 'title', label: this.t('decidiq', 'Title') },
				{ key: 'assignee', label: this.t('decidiq', 'Assignee') },
				{ key: 'dueDate', label: this.t('decidiq', 'Due') },
				{ key: 'taskStatus', label: this.t('decidiq', 'Status') },
			]
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		statusColors() {
			return {
				open: 'primary',
				'in-progress': 'warning',
				completed: 'success',
				overdue: 'error',
			}
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		rowActions() {
			return [
				{
					label: this.t('decidiq', 'Edit'),
					icon: Pencil,
					handler: (row) => this.openEdit(row),
				},
				{
					label: this.t('decidiq', 'Delete'),
					icon: TrashCanOutline,
					destructive: true,
					handler: (row) => {
						this.deleteTarget = { ...row }
					},
				},
			]
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		excludedFields() {
			return ['id', 'uuid', 'decision', 'created', 'updated']
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
	},

	methods: {
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const store = ensureRelationType('action-item')
				if (!this.actionItemSchema)
					this.actionItemSchema = await store.fetchSchema('action-item')
				const items = await store.fetchCollection('action-item', {
					decision: this.objectId,
					_limit: 100,
				})
				this.rows = items || []
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'Failed to load action items.')
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async openCreate() {
			const store = ensureRelationType('action-item')
			if (!this.actionItemSchema)
				this.actionItemSchema = await store.fetchSchema('action-item')
			this.editTarget = null
			this.formOpen = true
		},

		/**
		 * @param row
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		async openEdit(row) {
			const store = ensureRelationType('action-item')
			if (!this.actionItemSchema)
				this.actionItemSchema = await store.fetchSchema('action-item')
			this.editTarget = { ...row }
			this.formOpen = true
		},

		/**
		 * @param formData
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		async onConfirm(formData) {
			// Action items are read-only VTODO projections — write via the VTODO
			// endpoints (action-items-vtodo-deck-reconcile), not the object API.
			try {
				const uid =
					this.editTarget
					&& (this.editTarget.uuid
						|| this.editTarget.id
						|| this.editTarget['@self']?.uuid)
				if (uid) {
					await updateActionItem(uid, {
						...formData,
						decision: this.objectId,
					})
				} else {
					await createActionItem({ ...formData, decision: this.objectId })
				}
				this.$refs.formDialog?.setResult({ success: true })
				this.refresh()
			} catch (e) {
				this.$refs.formDialog?.setResult({
					error: e?.message || this.t('decidiq', 'Save failed.'),
				})
			}
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async confirmDelete() {
			try {
				const uid =
					this.deleteTarget
					&& (this.deleteTarget.uuid
						|| this.deleteTarget.id
						|| this.deleteTarget['@self']?.uuid)
				await deleteActionItem(uid)
				this.$refs.deleteDialog?.setResult({ success: true })
				this.refresh()
			} catch (e) {
				this.$refs.deleteDialog?.setResult({
					error: e?.message || this.t('decidiq', 'Delete failed.'),
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
