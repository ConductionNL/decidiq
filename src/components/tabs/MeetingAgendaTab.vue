<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: agenda items for a Meeting.

 Posture: full CRUD. Meetings own their agenda items; this tab lists
 them by `meeting === parent.id` in tree order (sub-items nested under
 their parent via the additive `parentItem` field), and lets
 chair/secretary add, edit, and delete items inline. It also warns
 about missing statutory ALV items for `general_assembly` meetings and
 assembles the meeting document package (vergaderstukken). A chair,
 secretary or admin (asked of GET /api/meetings/{id}/my-roles, the same
 resolver the reorder endpoint's guard uses) can also drag rows or use
 Move up and Move down to reorder, and opens the live meeting screen from
 here. Every row opens its agenda item page, where its documents live
 (agenda-meeting-page-item-tools).
-->
<template>
	<div class="decidiq-tab decidiq-tab--agenda" data-testid="agenda-tab">
		<div class="decidiq-tab__header">
			<h3 class="decidiq-tab__title">
				{{ t('decidiq', 'Agenda') }}
				<span v-if="!loading" class="decidiq-tab__count"
					>({{ rows.length }})</span
				>
			</h3>
			<div class="decidiq-tab__header-actions">
				<NcButton
					v-if="canManage"
					data-testid="agenda-open-live"
					@click="openLive">
					<template #icon>
						<Presentation :size="20" />
					</template>
					{{ t('decidiq', 'Open live meeting') }}
				</NcButton>
				<NcButton
					data-testid="agenda-assemble-package"
					:disabled="assembling"
					:aria-label="t('decidiq', 'Assemble meeting package')"
					@click="assemblePackage">
					{{
						assembling
							? t('decidiq', 'Assembling…')
							: t('decidiq', 'Assemble meeting package')
					}}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="agenda-add-item"
					:aria-label="t('decidiq', 'Add agenda item')"
					@click="openCreate">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('decidiq', 'Add agenda item') }}
				</NcButton>
			</div>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'Could not load agenda items')">
			{{ error }}
		</CnNoteCard>

		<CnNoteCard
			v-if="missingStatutory.length > 0"
			type="warning"
			data-testid="statutory-items-warning"
			:title="t('decidiq', 'Missing statutory ALV agenda items')">
			<p>
				{{
					t(
						'decidiq',
						'This general assembly agenda is missing legally required items:',
					)
				}}
			</p>
			<ul class="decidiq-tab__statutory-list">
				<li v-for="required in missingStatutory" :key="required.id">
					{{ t('decidiq', required.label) }}
				</li>
			</ul>
		</CnNoteCard>

		<CnNoteCard
			v-if="reorderError"
			type="error"
			data-testid="agenda-reorder-error"
			:title="t('decidiq', 'Could not save the new order')">
			{{ reorderError }}
		</CnNoteCard>

		<CnNoteCard
			v-if="packageError"
			type="error"
			:title="t('decidiq', 'Package assembly failed')">
			{{ packageError }}
		</CnNoteCard>

		<CnNoteCard
			v-if="packageResult"
			type="success"
			data-testid="agenda-package-result"
			:title="t('decidiq', 'Meeting package assembled')">
			<p>{{ packageResult.message }}</p>
			<a
				v-if="packageResult.path"
				:href="packageFolderUrl"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('decidiq', 'Open package folder') }}
			</a>
		</CnNoteCard>

		<CnDataTable
			:columns="columns"
			:rows="rows"
			:loading="loading"
			rowKey="id"
			:emptyText="t('decidiq', 'No agenda items yet for this meeting.')"
			:loadingText="t('decidiq', 'Loading agenda…')"
			@rowClick="openEdit">
			<template #column-orderNumber="{ row, value }">
				<span
					class="decidiq-tab__order"
					:class="{ 'decidiq-tab__order--draggable': canManage }"
					:draggable="canManage"
					:data-testid="`agenda-drag-${row.id}`"
					@click.stop
					@dragstart="onDragStart($event, row)"
					@dragover.prevent
					@drop.prevent="onDrop(row)">
					<DragVertical
						v-if="canManage"
						:size="16"
						class="decidiq-tab__drag-handle" />
					{{ value }}
				</span>
			</template>
			<template #column-titleDisplay="{ row, value }">
				<span @dragover.prevent @drop.prevent="onDrop(row)">{{ value }}</span>
			</template>
			<template #row-actions="{ row }">
				<CnRowActions :row="row" :actions="rowActions" />
			</template>
		</CnDataTable>

		<CnFormDialog
			v-if="formOpen"
			ref="formDialog"
			:schema="agendaSchema"
			:item="editTarget"
			:dialogTitle="
				editTarget
					? t('decidiq', 'Edit agenda item')
					: t('decidiq', 'Add agenda item')
			"
			:excludeFields="excludedFields"
			@confirm="onConfirm"
			@close="closeForm">
			<!-- #1393: the fields the selected type declares, written into
			     typeFields. The schema form skips `typeFields` (a free object),
			     so the inputs come from the type, not the schema. -->
			<template #after-fields>
				<AgendaItemTypeFields
					v-model="formTypeFields"
					:type="formItemType" />
			</template>
		</CnFormDialog>

		<CnDeleteDialog
			v-if="deleteTarget"
			ref="deleteDialog"
			:item="deleteTarget"
			nameField="title"
			:dialogTitle="t('decidiq', 'Delete agenda item')"
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
} from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import DragVertical from 'vue-material-design-icons/DragVertical.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Presentation from 'vue-material-design-icons/Presentation.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import AgendaItemTypeFields from '../AgendaItemTypeFields.vue'
import {
	buildAgendaTree,
	canManageAgenda,
	dropAgendaItem,
	flattenTree,
	missingStatutoryItems,
	moveAgendaItem,
} from '../../services/agendaRules.js'
import {
	findItemType,
	missingRequiredTypeFields,
	typeFieldInputs,
} from '../../utils/agendaItemTypeFields.js'
import { ensureRelationType } from './useRelationStore.js'

export default {
	name: 'MeetingAgendaTab',
	components: {
		AgendaItemTypeFields,
		CnDataTable,
		CnDeleteDialog,
		CnFormDialog,
		CnNoteCard,
		CnRowActions,
		DragVertical,
		NcButton,
		Plus,
		Presentation,
	},

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			rawRows: [],
			meeting: null,
			agendaSchema: null,
			// uuid → name for the agenda-item types this instance has configured.
			// Empty is a valid state, not a failure: an instance that seeds no
			// types shows the coarse enum and nothing breaks.
			itemTypeNames: {},
			// The configured agenda-item types themselves, for their `fields`.
			itemTypes: [],
			// The type picked in the open form, and the answers to its fields.
			formType: null,
			formTypeFields: {},
			unwatchFormType: null,
			formOpen: false,
			editTarget: null,
			deleteTarget: null,
			assembling: false,
			packageResult: null,
			packageError: '',
			// The caller's presiding roles on this meeting, from the server.
			// Null until answered: the tools stay hidden rather than flash.
			myRoles: null,
			dragId: null,
			reorderError: '',
		}
	},

	computed: {
		/**
		 * Agenda rows, with the Type column resolved against the configured
		 * kinds.
		 *
		 * Computed rather than stored so the column fills in when the type names
		 * arrive, without the agenda blocking on that fetch.
		 *
		 * @return {Array<object>} The rows to render.
		 *
		 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md
		 */
		rows() {
			return this.rawRows.map((item) => ({
				...item,
				kindDisplay: this.itemTypeNames[item.type] || item.itemType,
			}))
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		columns() {
			return [
				{
					key: 'orderNumber',
					label: this.t('decidiq', '#'),
					width: '60px',
				},
				{ key: 'titleDisplay', label: this.t('decidiq', 'Title') },
				// The CONFIGURABLE kind, not the coarse enum. Since
				// questions-as-agenda-items collapsed oral questions and
				// interpellation requests into agenda items, `itemType`
				// (informational/discussion/decision) would render every one of
				// them as "discussion" and the column would stop telling a clerk
				// anything. `kindDisplay` falls back to `itemType` for an item
				// whose type is unset, so an instance that seeds no types reads
				// exactly as it did before.
				{ key: 'kindDisplay', label: this.t('decidiq', 'Type') },
				{
					key: 'estimatedDuration',
					label: this.t('decidiq', 'Duration (min)'),
				},
			]
		},

		/**
		 * Whether the caller may reorder and open the live screen.
		 *
		 * @return {boolean} True for chair, secretary or admin.
		 * @spec openspec/changes/agenda-meeting-page-item-tools/specs/agenda-management/spec.md#requirement-req-amp-002-a-chair-or-secretary-reorders-the-agenda-on-the-meeting-page
		 */
		canManage() {
			return canManageAgenda(this.myRoles)
		},

		/** @spec openspec/specs/agenda-management/spec.md */
		missingStatutory() {
			return missingStatutoryItems(this.meeting?.meetingType || '', this.rows)
		},

		/** @spec openspec/specs/agenda-management/spec.md */
		packageFolderUrl() {
			if (!this.packageResult?.path) return ''
			return (
				generateUrl('/apps/files')
				+ '?dir='
				+ encodeURIComponent(this.packageResult.path)
			)
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		rowActions() {
			return [
				{
					label: this.t('decidiq', 'Open'),
					icon: EyeOutline,
					handler: (row) => this.openItem(row),
				},
				{
					label: this.t('decidiq', 'Move up'),
					icon: ArrowUp,
					visible: () => this.canManage,
					handler: (row) => this.moveItem(row, -1),
				},
				{
					label: this.t('decidiq', 'Move down'),
					icon: ArrowDown,
					visible: () => this.canManage,
					handler: (row) => this.moveItem(row, 1),
				},
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

		/**
		 * The type picked in the open form, whose `fields` the form renders.
		 *
		 * @return {?object} The AgendaItemType, or null.
		 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md
		 */
		formItemType() {
			return findItemType(this.itemTypes, this.formType)
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
			handler() {
				this.refresh()
				this.loadMyRoles()
			},
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
				if (!this.agendaSchema)
					this.agendaSchema = await store.fetchSchema('agenda-item')
				const items = await store.fetchCollection('agenda-item', {
					meeting: this.objectId,
					_order: JSON.stringify({ orderNumber: 'asc' }),
					_limit: 100,
				})
				// 🔴 NOT AWAITED. The Type column is a label; the agenda is the
				// page. Awaiting this put one more object-list query in front of
				// every render, and on a loaded instance those cost about a
				// second each. `rows` is computed, so the names appear as soon as
				// they arrive and the agenda never waits for them.
				this.loadItemTypes()
				// Tree order: sub-items (`parentItem`) nest under their parent;
				// flattened parent→children order with a nesting indicator.
				const flat = flattenTree(buildAgendaTree(items || []))
				this.rawRows = flat.map((item) => ({
					...item,
					titleDisplay: item.parentItem ? `↳ ${item.title}` : item.title,
				}))
				await this.loadMeeting()
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'Failed to load agenda.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the configured agenda-item types, so the Type column can name
		 * the kind an item actually is.
		 *
		 * Fail-soft on purpose: a fetch that throws leaves the map empty, every
		 * row falls back to `itemType`, and the agenda still renders. An empty
		 * agenda-item-type collection is the normal state of an instance that
		 * has seeded no example set, so it must not read as an error.
		 *
		 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md
		 */
		async loadItemTypes() {
			try {
				const typeStore = ensureRelationType('agenda-item-type')
				const types = await typeStore.fetchCollection('agenda-item-type', {
					_limit: 200,
				})
				const names = {}
				for (const type of types || []) {
					if (!type?.name) continue
					// 🔴 INDEXED BY BOTH, BECAUSE A SEED WRITES THE SLUG.
					// A row created through the UI holds the type's uuid, but
					// seedData stores a reference exactly as the file wrote it:
					// measured on a live instance, seeded agenda items carry
					// `meeting: "raadsvergadering-2025-01-15"`, the slug, not a
					// uuid. Keying on `id` alone would leave every example-set
					// item falling back to the coarse enum, so the set that
					// exists to demonstrate configurable kinds would show none.
					if (type.id) names[type.id] = type.name
					const slug = type['@self']?.slug
					if (slug) names[slug] = type.name
				}
				this.itemTypeNames = names
				this.itemTypes = types || []
			} catch {
				this.itemTypeNames = {}
				this.itemTypes = []
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
				this.meeting = await meetingStore.fetchObject(
					'meeting',
					this.objectId,
				)
			} catch (e) {
				console.error('[decidiq] MeetingAgendaTab meeting fetch failed', e)
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
					generateUrl(
						`/apps/decidiq/api/meetings/${this.objectId}/package`,
					),
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
					this.packageError =
						payload?.message
						|| this.t('decidiq', 'Package assembly failed.')
					return
				}
				this.packageResult = payload
			} catch (e) {
				this.packageError =
					e?.message || this.t('decidiq', 'Package assembly failed.')
			} finally {
				this.assembling = false
			}
		},

		/**
		 * Ask the server which presiding roles the caller holds on this
		 * meeting. Fail-closed: any error leaves the tools hidden, and the
		 * reorder endpoint would refuse the call anyway.
		 *
		 * @spec openspec/changes/agenda-meeting-page-item-tools/specs/agenda-management/spec.md#requirement-req-amp-001-the-meeting-page-asks-the-server-for-the-callers-meeting-roles
		 */
		async loadMyRoles() {
			this.myRoles = null
			if (!this.objectId) return
			try {
				const response = await fetch(
					generateUrl(
						`/apps/decidiq/api/meetings/${this.objectId}/my-roles`,
					),
					{ headers: { Accept: 'application/json' } },
				)
				if (!response.ok) return
				this.myRoles = await response.json()
			} catch {
				// Fail closed: without an answer the tools stay hidden.
				this.myRoles = null
			}
		},

		/**
		 * @param {object} row Agenda row.
		 * @spec openspec/changes/agenda-meeting-page-item-tools/specs/agenda-management/spec.md#requirement-req-amp-003-every-agenda-row-opens-its-item-page
		 */
		openItem(row) {
			this.$router.push({ name: 'AgendaItemDetail', params: { id: row.id } })
		},

		/** @spec openspec/changes/agenda-meeting-page-item-tools/specs/agenda-management/spec.md#requirement-req-amp-004-the-meeting-page-links-the-live-meeting-screen */
		openLive() {
			this.$router.push({ name: 'LiveMeeting', params: { id: this.objectId } })
		},

		/**
		 * @param {object} row Agenda row.
		 * @param {number} delta -1 for up, 1 for down.
		 * @spec openspec/changes/agenda-meeting-page-item-tools/specs/agenda-management/spec.md#requirement-req-amp-002-a-chair-or-secretary-reorders-the-agenda-on-the-meeting-page
		 */
		moveItem(row, delta) {
			const ids = moveAgendaItem(buildAgendaTree(this.rawRows), row.id, delta)
			if (ids) this.persistOrder(ids)
		},

		/**
		 * @param {DragEvent} event The drag event.
		 * @param {object} row Agenda row being dragged.
		 * @spec openspec/changes/agenda-meeting-page-item-tools/specs/agenda-management/spec.md#requirement-req-amp-002-a-chair-or-secretary-reorders-the-agenda-on-the-meeting-page
		 */
		onDragStart(event, row) {
			if (!this.canManage) return
			this.dragId = row.id
			if (event?.dataTransfer) {
				event.dataTransfer.effectAllowed = 'move'
				event.dataTransfer.setData('text/plain', String(row.id))
			}
		},

		/**
		 * @param {object} row Agenda row the dragged item was dropped on.
		 * @spec openspec/changes/agenda-meeting-page-item-tools/specs/agenda-management/spec.md#requirement-req-amp-002-a-chair-or-secretary-reorders-the-agenda-on-the-meeting-page
		 */
		onDrop(row) {
			const dragId = this.dragId
			this.dragId = null
			if (!this.canManage || !dragId) return
			const ids = dropAgendaItem(buildAgendaTree(this.rawRows), dragId, row.id)
			if (ids) this.persistOrder(ids)
		},

		/**
		 * Save the new order in one call to the existing reorder endpoint,
		 * which renumbers every item, then reload the rows.
		 *
		 * @param {Array<string>} ids Agenda item ids in the new order.
		 * @spec openspec/changes/agenda-meeting-page-item-tools/specs/agenda-management/spec.md#requirement-req-amp-002-a-chair-or-secretary-reorders-the-agenda-on-the-meeting-page
		 */
		async persistOrder(ids) {
			this.reorderError = ''
			try {
				const response = await fetch(
					generateUrl(`/apps/decidiq/api/agendas/${this.objectId}/reorder`),
					{
						method: 'PUT',
						headers: {
							'Content-Type': 'application/json',
							Accept: 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({ ids }),
					},
				)
				if (!response.ok) {
					const payload = await response.json().catch(() => ({}))
					this.reorderError =
						payload?.message
						|| this.t('decidiq', 'The new order was not saved.')
					return
				}
				await this.refresh()
			} catch (e) {
				this.reorderError =
					e?.message || this.t('decidiq', 'The new order was not saved.')
			}
		},

		/** @spec openspec/specs/relation-tab-ui/spec.md */
		async openCreate() {
			const store = ensureRelationType('agenda-item')
			if (!this.agendaSchema)
				this.agendaSchema = await store.fetchSchema('agenda-item')
			this.editTarget = null
			this.openForm()
		},

		/**
		 * @param row
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		async openEdit(row) {
			const store = ensureRelationType('agenda-item')
			if (!this.agendaSchema)
				this.agendaSchema = await store.fetchSchema('agenda-item')
			// Strip the presentation-only nesting indicator before editing.

			const { titleDisplay, ...item } = row
			this.editTarget = item
			this.openForm()
		},

		/**
		 * Open the form and follow the type picked in it, so the fields that
		 * type declares appear as soon as it is chosen.
		 *
		 * The dialog keeps its values internally and emits no change event, so
		 * its reactive `formData.type` is watched once it has mounted.
		 *
		 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md
		 */
		openForm() {
			this.formType = this.editTarget?.type || null
			this.formTypeFields = { ...(this.editTarget?.typeFields || {}) }
			this.formOpen = true
			this.$nextTick(() => {
				this.unwatchFormType?.()
				this.unwatchFormType = this.$watch(
					() => this.$refs.formDialog?.formData?.type,
					(value) => {
						if (value !== undefined) this.formType = value || null
					},
				)
			})
		},

		/** @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md */
		closeForm() {
			this.unwatchFormType?.()
			this.unwatchFormType = null
			this.formOpen = false
		},

		/**
		 * @param formData
		 * @spec openspec/specs/relation-tab-ui/spec.md
		 */
		async onConfirm(formData) {
			const missing = missingRequiredTypeFields(
				typeFieldInputs(this.formItemType),
				this.formTypeFields,
			)
			if (missing.length > 0) {
				this.$refs.formDialog?.setResult({
					error: this.t('decidiq', 'Fill in: {fields}', {
						fields: missing.join(', '),
					}),
				})
				return
			}
			const store = ensureRelationType('agenda-item')
			try {
				await store.saveObject('agenda-item', {
					...formData,
					typeFields: this.formTypeFields,
					meeting: this.objectId,
				})
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
			const store = ensureRelationType('agenda-item')
			try {
				await store.deleteObject('agenda-item', this.deleteTarget.id)
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

.decidiq-tab__header-actions {
	display: flex;
	gap: var(--default-grid-baseline);
	flex-wrap: wrap;
}

.decidiq-tab__order {
	display: inline-flex;
	align-items: center;
	gap: 2px;
}

.decidiq-tab__order--draggable {
	cursor: grab;
}

.decidiq-tab__drag-handle {
	color: var(--color-text-maxcontrast);
}

.decidiq-tab__statutory-list {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 4);
	list-style: disc;
}
</style>
