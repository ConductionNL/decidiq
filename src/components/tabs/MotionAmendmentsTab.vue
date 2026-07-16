<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: amendments proposed against a Motion.

 Posture: full CRUD. Motions own their amendments via `parentMotion`;
 this tab fetches amendments where `parentMotion === parent.id`,
 lists them with lifecycle badges, and lets users add / edit / delete.
 Lifecycle transitions, voting, and conflict warnings remain on the
 standalone /amendments/:id detail page.
-->
<template>
	<div class="decidesk-tab decidesk-tab--amendments" data-testid="motion-amendments-tab">
		<div class="decidesk-tab__header">
			<h3 class="decidesk-tab__title">
				{{ t('decidesk', 'Amendments') }}
				<span v-if="!loading" class="decidesk-tab__count">({{ rows.length }})</span>
			</h3>
			<NcButton
				type="primary"
				data-testid="motion-amendments-add"
				:aria-label="t('decidesk', 'Submit amendment')"
				@click="openCreate">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidesk', 'Submit amendment') }}
			</NcButton>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Could not load amendments')">
			{{ error }}
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			row-key="id"
			:empty-text="t('decidesk', 'No amendments for this motion yet.')"
			:loading-text="t('decidesk', 'Loading amendments…')"
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
			:schema="amendmentSchema"
			:item="editTarget"
			:dialog-title="editTarget ? t('decidesk', 'Edit amendment') : t('decidesk', 'Submit amendment')"
			:exclude-fields="excludedFields"
			@confirm="onConfirm"
			@close="formOpen = false" />

		<CnDeleteDialog
			v-if="deleteTarget"
			ref="deleteDialog"
			:item="deleteTarget"
			name-field="title"
			:dialog-title="t('decidesk', 'Delete amendment')"
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
	name: 'MotionAmendmentsTab',
	components: { CnDataTable, CnDeleteDialog, CnFormDialog, CnNoteCard, CnRowActions, CnStatusBadge, NcButton, Plus },
	props: {
		objectId: { type: [String, Number], default: '' },
	},
	data() {
		return {
			loading: false,
			error: '',
			rows: [],
			amendmentSchema: null,
			formOpen: false,
			editTarget: null,
			deleteTarget: null,
		}
	},
	computed: {
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		columns() {
			return [
				{ key: 'title', label: this.t('decidesk', 'Title') },
				{ key: 'proposer', label: this.t('decidesk', 'Proposer') },
				{ key: 'lifecycle', label: this.t('decidesk', 'Status') },
			]
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
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
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		rowActions() {
			return [
				{ label: this.t('decidesk', 'Edit'), icon: Pencil, handler: (row) => this.openEdit(row) },
				{ label: this.t('decidesk', 'Delete'), icon: TrashCanOutline, destructive: true, handler: (row) => { this.deleteTarget = { ...row } } },
			]
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		excludedFields() {
			return ['id', 'uuid', 'parentMotion', 'created', 'updated']
		},
	},
	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/relation-tab-ui/spec.md */
			handler() { this.refresh() },
		},
	},
	methods: {
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				const store = ensureRelationType('amendment')
				if (!this.amendmentSchema) this.amendmentSchema = await store.fetchSchema('amendment')
				const items = await store.fetchCollection('amendment', {
					decisionType: 'amendment',
					amends: this.objectId,
					_limit: 100,
				})
				this.rows = items || []
			} catch (e) {
				this.error = e?.message || this.t('decidesk', 'Failed to load amendments.')
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async openCreate() {
			const store = ensureRelationType('amendment')
			if (!this.amendmentSchema) this.amendmentSchema = await store.fetchSchema('amendment')
			this.editTarget = null
			this.formOpen = true
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async openEdit(row) {
			const store = ensureRelationType('amendment')
			if (!this.amendmentSchema) this.amendmentSchema = await store.fetchSchema('amendment')
			this.editTarget = { ...row }
			this.formOpen = true
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async onConfirm(formData) {
			const store = ensureRelationType('amendment')
			try {
				await store.saveObject('amendment', { ...formData, decisionType: 'amendment', amends: this.objectId })
				this.$refs.formDialog?.setResult({ success: true })
				this.refresh()
			} catch (e) {
				this.$refs.formDialog?.setResult({ error: e?.message || this.t('decidesk', 'Save failed.') })
			}
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async confirmDelete() {
			const store = ensureRelationType('amendment')
			try {
				await store.deleteObject('amendment', this.deleteTarget.id)
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
