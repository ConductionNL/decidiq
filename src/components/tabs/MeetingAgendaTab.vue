<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: agenda items for a Meeting.

 Posture: full CRUD. Meetings own their agenda items; this tab lists
 them by `meeting === parent.id` in tree order (sub-items nested under
 their parent via the additive `parentItem` field), and lets
 chair/secretary add, edit, and delete items inline. It also warns
 about missing statutory ALV items for `general_assembly` meetings and
 assembles the meeting document package (vergaderstukken). Drag-reorder
 from the deleted MeetingDetail/AgendaBuilder is left to the standalone
 /agenda-items index — out of scope for the sidebar tab.
-->
<template>
	<div class="decidesk-tab decidesk-tab--agenda" data-testid="agenda-tab">
		<div class="decidesk-tab__header">
			<h3 class="decidesk-tab__title">
				{{ t('decidesk', 'Agenda') }}
				<span v-if="!loading" class="decidesk-tab__count">({{ rows.length }})</span>
			</h3>
			<div class="decidesk-tab__header-actions">
				<NcButton
					data-testid="agenda-assemble-package"
					:disabled="assembling"
					:aria-label="t('decidesk', 'Assemble meeting package')"
					@click="assemblePackage">
					{{ assembling ? t('decidesk', 'Assembling…') : t('decidesk', 'Assemble meeting package') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="agenda-add-item"
					:aria-label="t('decidesk', 'Add agenda item')"
					@click="openCreate">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('decidesk', 'Add agenda item') }}
				</NcButton>
			</div>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Could not load agenda items')">
			{{ error }}
		</CnNoteCard>

		<CnNoteCard
			v-if="missingStatutory.length > 0"
			type="warning"
			data-testid="statutory-items-warning"
			:title="t('decidesk', 'Missing statutory ALV agenda items')">
			<p>{{ t('decidesk', 'This general assembly agenda is missing legally required items:') }}</p>
			<ul class="decidesk-tab__statutory-list">
				<li v-for="required in missingStatutory" :key="required.id">
					{{ t('decidesk', required.label) }}
				</li>
			</ul>
		</CnNoteCard>

		<CnNoteCard
			v-if="packageError"
			type="error"
			:title="t('decidesk', 'Package assembly failed')">
			{{ packageError }}
		</CnNoteCard>

		<CnNoteCard
			v-if="packageResult"
			type="success"
			data-testid="agenda-package-result"
			:title="t('decidesk', 'Meeting package assembled')">
			<p>{{ packageResult.message }}</p>
			<a
				v-if="packageResult.path"
				:href="packageFolderUrl"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('decidesk', 'Open package folder') }}
			</a>
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
import { generateUrl } from '@nextcloud/router'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { ensureRelationType } from './useRelationStore.js'
import { buildAgendaTree, flattenTree, missingStatutoryItems } from '../../services/agendaRules.js'

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
			meeting: null,
			agendaSchema: null,
			formOpen: false,
			editTarget: null,
			deleteTarget: null,
			assembling: false,
			packageResult: null,
			packageError: '',
		}
	},
	computed: {
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		columns() {
			return [
				{ key: 'orderNumber', label: this.t('decidesk', '#'), width: '60px' },
				{ key: 'titleDisplay', label: this.t('decidesk', 'Title') },
				{ key: 'itemType', label: this.t('decidesk', 'Type') },
				{ key: 'estimatedDuration', label: this.t('decidesk', 'Duration (min)') },
			]
		},

		/** @spec openspec/specs/agenda-management/spec.md */
		missingStatutory() {
			return missingStatutoryItems(this.meeting?.meetingType || '', this.rows)
		},

		/** @spec openspec/specs/agenda-management/spec.md */
		packageFolderUrl() {
			if (!this.packageResult?.path) return ''
			return generateUrl('/apps/files') + '?dir=' + encodeURIComponent(this.packageResult.path)
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
			// Hide system / parent-link fields — we set `meeting` ourselves.
			return ['id', 'uuid', 'meeting', 'created', 'updated']
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
		/** @spec openspec/specs/agenda-management/spec.md */
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
				// Tree order: sub-items (`parentItem`) nest under their parent;
				// flattened parent→children order with a nesting indicator.
				const flat = flattenTree(buildAgendaTree(items || []))
				this.rows = flat.map(item => ({
					...item,
					titleDisplay: item.parentItem ? `↳ ${item.title}` : item.title,
				}))
				await this.loadMeeting()
			} catch (e) {
				this.error = e?.message || this.t('decidesk', 'Failed to load agenda.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the parent meeting (for `meetingType` — statutory ALV check).
		 * Fail-soft: the agenda list renders even when the meeting cannot load.
		 *
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		async loadMeeting() {
			try {
				const meetingStore = ensureRelationType('meeting')
				this.meeting = await meetingStore.fetchObject('meeting', this.objectId)
			} catch (e) {
				console.error('[decidesk] MeetingAgendaTab meeting fetch failed', e)
			}
		},

		/**
		 * Assemble the meeting document package (vergaderstukken) via
		 * POST /api/meetings/{id}/package and surface the folder link.
		 *
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		async assemblePackage() {
			this.assembling = true
			this.packageError = ''
			this.packageResult = null
			try {
				const response = await fetch(
					generateUrl(`/apps/decidesk/api/meetings/${this.objectId}/package`),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							Accept: 'application/json',
							requesttoken: OC.requestToken,
						},
					},
				)
				const payload = await response.json()
				if (!response.ok || payload?.success === false) {
					this.packageError = payload?.message || this.t('decidesk', 'Package assembly failed.')
					return
				}
				this.packageResult = payload
			} catch (e) {
				this.packageError = e?.message || this.t('decidesk', 'Package assembly failed.')
			} finally {
				this.assembling = false
			}
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async openCreate() {
			const store = ensureRelationType('agenda-item')
			if (!this.agendaSchema) this.agendaSchema = await store.fetchSchema('agenda-item')
			this.editTarget = null
			this.formOpen = true
		},
		/** @spec openspec/specs/agenda-management/spec.md */
		async openEdit(row) {
			const store = ensureRelationType('agenda-item')
			if (!this.agendaSchema) this.agendaSchema = await store.fetchSchema('agenda-item')
			// Strip the presentation-only nesting indicator before editing.
			// eslint-disable-next-line no-unused-vars
			const { titleDisplay, ...item } = row
			this.editTarget = item
			this.formOpen = true
		},
		/** @spec openspec/specs/relation-tab-ui/spec.md */
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
		/** @spec openspec/specs/relation-tab-ui/spec.md */
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
.decidesk-tab__header-actions {
	display: flex;
	gap: var(--default-grid-baseline);
	flex-wrap: wrap;
}
.decidesk-tab__statutory-list {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 4);
	list-style: disc;
}
</style>
