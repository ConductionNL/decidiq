<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: motions tied to an Agenda Item.

 Posture: full CRUD. Agenda items own their motions; this tab fetches
 motions whose `agendaItem === parent.id`, lists them with lifecycle
 badges, and lets users add / edit / delete inline. Lifecycle
 transitions and voting still live on /motions/:id (see MotionDetail
 page) — this tab is the structural CRUD surface only.
-->
<template>
	<div class="decidesk-tab decidesk-tab--motions" data-testid="agenda-motions-tab">
		<div class="decidesk-tab__header">
			<h3 class="decidesk-tab__title">
				{{ t('decidesk', 'Motions') }}
				<span v-if="!loading" class="decidesk-tab__count">({{ rows.length }})</span>
			</h3>
			<NcButton
				type="primary"
				data-testid="agenda-motions-add"
				:aria-label="t('decidesk', 'Add motion')"
				@click="openCreate">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidesk', 'Add motion') }}
			</NcButton>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Could not load motions')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			row-key="id"
			:empty-text="t('decidesk', 'No motions for this agenda item yet.')"
			:loading-text="t('decidesk', 'Loading motions…')"
			@row-click="openEdit">
			<template #column-lifecycle="{ value }">
				<CnStatusBadge v-if="value" :label="value" :color-map="lifecycleColors" />
			</template>
			<template #row-actions="{ row }">
				<CnRowActions :row="row" :actions="rowActions" />
			</template>
		</CnDataTable>

		<CnFormDialog
			v-if="formOpen"
			ref="formDialog"
			:schema="motionSchema"
			:item="editTarget"
			:dialog-title="editTarget ? t('decidesk', 'Edit motion') : t('decidesk', 'Add motion')"
			:exclude-fields="excludedFields"
			@confirm="onConfirm"
			@close="formOpen = false" />

		<CnDeleteDialog
			v-if="deleteTarget"
			ref="deleteDialog"
			:item="deleteTarget"
			name-field="title"
			:dialog-title="t('decidesk', 'Delete motion')"
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

export default {
	name: 'AgendaMotionsTab',
	components: { CnDataTable, CnDeleteDialog, CnFormDialog, CnNoteCard, CnRowActions, CnStatusBadge, NcButton, Plus },
	props: {
		objectId: { type: [String, Number], default: '' },
	},
	data() {
		return {
			loading: false,
			error: '',
			rows: [],
			motionSchema: null,
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
				{ key: 'proposer', label: this.t('decidesk', 'Proposer') },
				{ key: 'lifecycle', label: this.t('decidesk', 'Status') },
			]
		},
		/** @spec openspec/changes/retrofit-2026-05-25-relation-tab-ui/tasks.md#task-2 */
		lifecycleColors() {
			return {
				submitted: 'primary',
				debating: 'warning',
				voting: 'warning',
				adopted: 'success',
				rejected: 'error',
				withdrawn: 'default',
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
			return ['id', 'uuid', 'agendaItem', 'created', 'updated']
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
				const store = ensureRelationType('motion')
				if (!this.motionSchema) this.motionSchema = await store.fetchSchema('motion')
				const items = await store.fetchCollection('motion', {
					agendaItem: this.objectId,
					_limit: 100,
				})
				this.rows = items || []
			} catch (e) {
				this.error = e?.message || this.t('decidesk', 'Failed to load motions.')
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-25-relation-tab-ui/tasks.md#task-1 */
		async openCreate() {
			const store = ensureRelationType('motion')
			if (!this.motionSchema) this.motionSchema = await store.fetchSchema('motion')
			this.editTarget = null
			this.formOpen = true
		},
		/** @spec openspec/changes/retrofit-2026-05-25-relation-tab-ui/tasks.md#task-1 */
		async openEdit(row) {
			const store = ensureRelationType('motion')
			if (!this.motionSchema) this.motionSchema = await store.fetchSchema('motion')
			this.editTarget = { ...row }
			this.formOpen = true
		},
		/** @spec openspec/changes/retrofit-2026-05-25-relation-tab-ui/tasks.md#task-1 */
		async onConfirm(formData) {
			const store = ensureRelationType('motion')
			try {
				await store.saveObject('motion', { ...formData, agendaItem: this.objectId })
				this.$refs.formDialog?.setResult({ success: true })
				this.refresh()
			} catch (e) {
				this.$refs.formDialog?.setResult({ error: e?.message || this.t('decidesk', 'Save failed.') })
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-25-relation-tab-ui/tasks.md#task-1 */
		async confirmDelete() {
			const store = ensureRelationType('motion')
			try {
				await store.deleteObject('motion', this.deleteTarget.id)
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
