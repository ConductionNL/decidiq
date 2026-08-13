<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Action-item Deck board.

 A status-laned (open / in-progress / done) view of a decision's
 action-item VTODOs, projected onto *real* Nextcloud Deck cards via the
 OpenRegister Deck leaf (ADR-019). The VTODO stays authoritative:
 - "Project to Deck" creates one real Deck card per action item (idempotent).
 - Moving a card between lanes writes the new status through the VTODO
   endpoint (`updateActionItem`), not a Deck-native write.
 - Each projected card links out to the real card in the Deck app.

 v1 is forward-only — editing the card in Deck does not sync back.

 @spec openspec/changes/action-item-deck-board/specs/action-item-board-via-deck-leaf/spec.md
-->
<template>
	<div class="ai-deck-board" data-testid="action-item-deck-board">
		<div class="ai-deck-board__header">
			<h3 class="ai-deck-board__title">
				{{ t('decidesk', 'Action items') }}
				<span v-if="!loading" class="ai-deck-board__count"
					>({{ rows.length }})</span
				>
			</h3>
			<div class="ai-deck-board__actions">
				<NcButton
					variant="secondary"
					data-testid="action-item-deck-sync"
					:disabled="syncing || loading || !rows.length"
					@click="sync">
					<template #icon>
						<ViewColumnOutline v-if="!syncing" :size="20" />
						<NcLoadingIcon v-else :size="20" />
					</template>
					{{ t('decidesk', 'Project to Deck') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="action-item-deck-add"
					:aria-label="t('decidesk', 'Add action item')"
					@click="openCreate">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('decidesk', 'Add action item') }}
				</NcButton>
			</div>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Deck board error')">
			{{ error }}
		</CnNoteCard>
		<CnNoteCard
			v-if="notice"
			type="success"
			:title="t('decidesk', 'Projected to Deck')">
			{{ notice }}
		</CnNoteCard>

		<div class="ai-deck-board__lanes">
			<section
				v-for="lane in lanes"
				:key="lane.key"
				class="ai-deck-lane"
				:data-testid="`action-item-lane-${lane.key}`">
				<header class="ai-deck-lane__header">
					<span class="ai-deck-lane__label">{{ lane.label }}</span>
					<span class="ai-deck-lane__count">{{ lane.items.length }}</span>
				</header>
				<ul class="ai-deck-lane__cards">
					<li
						v-for="item in lane.items"
						:key="uidOf(item)"
						class="ai-deck-card"
						data-testid="action-item-card">
						<div class="ai-deck-card__head">
							<button
								class="ai-deck-card__title"
								@click="openEdit(item)">
								{{ item.title || t('decidesk', 'Untitled') }}
							</button>
							<NcButton
								variant="tertiary-no-background"
								:aria-label="t('decidesk', 'Delete action item')"
								@click="deleteTarget = { ...item }">
								<template #icon>
									<TrashCanOutline :size="18" />
								</template>
							</NcButton>
						</div>
						<div v-if="item.assignee" class="ai-deck-card__meta">
							{{ item.assignee }}
						</div>
						<div v-if="item.dueDate" class="ai-deck-card__meta">
							{{ item.dueDate }}
						</div>
						<div class="ai-deck-card__footer">
							<!--
								v9 model pair (`modelValue` /
								`update:modelValue`). The v8 `:value` / `@input`
								form renders an identical, fully interactive
								combobox but emits nothing, so choosing a lane
								here silently moved no card at all.
							-->
							<NcSelect
								:model-value="laneOption(item)"
								:options="laneOptions"
								:clearable="false"
								:input-label="t('decidesk', 'Status')"
								:aria-label-combobox="
									t(
										'decidesk',
										'Move action item to another status',
									)
								"
								class="ai-deck-card__move"
								@update:model-value="(opt) => moveTo(item, opt)" />
							<a
								v-if="item.deckCardId"
								:href="deckCardUrl"
								target="_blank"
								rel="noopener noreferrer"
								class="ai-deck-card__link"
								:title="t('decidesk', 'Open in Deck')">
								{{ t('decidesk', 'In Deck') }}
							</a>
						</div>
					</li>
					<li v-if="!lane.items.length" class="ai-deck-lane__empty">
						{{ t('decidesk', 'None') }}
					</li>
				</ul>
			</section>
		</div>

		<CnFormDialog
			v-if="formOpen"
			ref="formDialog"
			:schema="actionItemSchema"
			:item="editTarget"
			:dialog-title="
				editTarget
					? t('decidesk', 'Edit action item')
					: t('decidesk', 'Add action item')
			"
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
import { CnDeleteDialog, CnFormDialog, CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import ViewColumnOutline from 'vue-material-design-icons/ViewColumnOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { ensureRelationType } from './useRelationStore.js'
import { useSettingsStore } from '../../store/store.js'
import {
	createActionItem,
	updateActionItem,
	deleteActionItem,
} from '../../services/actionItemApi.js'
import {
	LANES,
	statusToLane,
	projectActionItems,
	itemUid,
} from '../../services/deckProjection.js'

const LANE_LABELS = {
	open: 'Open',
	'in-progress': 'In progress',
	done: 'Done',
}

export default {
	name: 'ActionItemDeckBoard',
	components: {
		CnDeleteDialog,
		CnFormDialog,
		CnNoteCard,
		NcButton,
		NcLoadingIcon,
		NcSelect,
		Plus,
		TrashCanOutline,
		ViewColumnOutline,
	},
	props: {
		objectId: { type: [String, Number], default: '' },
	},
	data() {
		return {
			loading: false,
			syncing: false,
			error: '',
			notice: '',
			rows: [],
			actionItemSchema: null,
			formOpen: false,
			editTarget: null,
			deleteTarget: null,
		}
	},
	computed: {
		/** @spec openspec/changes/action-item-deck-board/tasks.md#task-2 */
		register() {
			const settings = useSettingsStore().getSettings || {}
			return settings.register || 'decidesk'
		},
		/** @spec openspec/changes/action-item-deck-board/tasks.md#task-2 */
		schema() {
			const settings = useSettingsStore().getSettings || {}
			return settings.decisionSchema || 'decision'
		},
		/** @spec openspec/changes/action-item-deck-board/tasks.md#task-2 */
		lanes() {
			return LANES.map((key) => ({
				key,
				label: this.t('decidesk', LANE_LABELS[key]),
				items: this.rows.filter((r) => statusToLane(r.taskStatus) === key),
			}))
		},
		/** @spec openspec/changes/action-item-deck-board/tasks.md#task-3 */
		laneOptions() {
			return LANES.map((key) => ({
				id: key,
				label: this.t('decidesk', LANE_LABELS[key]),
			}))
		},
		/** Deck app root — used for the per-card "In Deck" link. */
		deckCardUrl() {
			return generateUrl('/apps/deck/')
		},
		/** @spec openspec/changes/action-item-deck-board/tasks.md#task-1 */
		excludedFields() {
			return ['id', 'uuid', 'decision', 'deckCardId', 'created', 'updated']
		},
	},
	watch: {
		objectId: {
			immediate: true,
			handler() {
				this.refresh()
			},
		},
	},
	methods: {
		uidOf(item) {
			return itemUid(item)
		},
		/** @spec openspec/changes/action-item-deck-board/tasks.md#task-3 */
		laneOption(item) {
			const key = statusToLane(item.taskStatus)
			return { id: key, label: this.t('decidesk', LANE_LABELS[key]) }
		},
		/** @spec openspec/changes/action-item-deck-board/tasks.md#task-2 */
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
					e?.message || this.t('decidesk', 'Failed to load action items.')
			} finally {
				this.loading = false
			}
		},
		/**
		 * Open the create dialog.
		 *
		 * @spec openspec/changes/action-item-deck-board/tasks.md#task-1
		 */
		async openCreate() {
			const store = ensureRelationType('action-item')
			if (!this.actionItemSchema)
				this.actionItemSchema = await store.fetchSchema('action-item')
			this.editTarget = null
			this.formOpen = true
		},
		/**
		 * Open the edit dialog for a card.
		 *
		 * @param {object} item The action-item row.
		 * @spec openspec/changes/action-item-deck-board/tasks.md#task-1
		 */
		async openEdit(item) {
			const store = ensureRelationType('action-item')
			if (!this.actionItemSchema)
				this.actionItemSchema = await store.fetchSchema('action-item')
			this.editTarget = { ...item }
			this.formOpen = true
		},
		/**
		 * Persist a create/edit through the authoritative VTODO endpoints
		 * (action items are a read-only projection — never the object API).
		 *
		 * @param {object} formData The dialog form data.
		 * @spec openspec/changes/action-item-deck-board/tasks.md#task-1
		 */
		async onConfirm(formData) {
			try {
				const uid = this.editTarget && itemUid(this.editTarget)
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
					error: e?.message || this.t('decidesk', 'Save failed.'),
				})
			}
		},
		/**
		 * Delete an action item (VTODO).
		 *
		 * @spec openspec/changes/action-item-deck-board/tasks.md#task-1
		 */
		async confirmDelete() {
			try {
				await deleteActionItem(itemUid(this.deleteTarget))
				this.$refs.deleteDialog?.setResult({ success: true })
				this.refresh()
			} catch (e) {
				this.$refs.deleteDialog?.setResult({
					error: e?.message || this.t('decidesk', 'Delete failed.'),
				})
			}
		},
		/** @spec openspec/changes/action-item-deck-board/tasks.md#task-2 */
		async sync() {
			this.syncing = true
			this.error = ''
			this.notice = ''
			try {
				const result = await projectActionItems({
					register: this.register,
					schema: this.schema,
					objectId: this.objectId,
					items: this.rows,
				})
				const parts = []
				if (result.created)
					parts.push(
						this.t('decidesk', '{n} card(s) created', {
							n: result.created,
						}),
					)
				if (result.skipped)
					parts.push(
						this.t('decidesk', '{n} already on Deck', {
							n: result.skipped,
						}),
					)
				this.notice =
					parts.join(' · ') || this.t('decidesk', 'Nothing to project.')
				if (result.errors && result.errors.length) {
					this.error = this.t(
						'decidesk',
						'{n} item(s) could not be projected.',
						{ n: result.errors.length },
					)
				}
				await this.refresh()
			} catch (e) {
				this.error = e?.message || this.t('decidesk', 'Projection failed.')
			} finally {
				this.syncing = false
			}
		},
		/**
		 * Move an action item to another lane — writes the status through the
		 * authoritative VTODO endpoint (optimistic, rolls back on error). The
		 * real Deck card re-stacks on the next "Project to Deck" run.
		 *
		 * @param {object} item The action-item row.
		 * @param {object} opt  The chosen lane option ({id, label}).
		 * @spec openspec/changes/action-item-deck-board/tasks.md#task-3
		 */
		async moveTo(item, opt) {
			const newLane = opt && opt.id
			if (!newLane || statusToLane(item.taskStatus) === newLane) return

			const uid = itemUid(item)
			const previous = item.taskStatus
			// Optimistic update.
			item.taskStatus = newLane
			try {
				await updateActionItem(uid, { taskStatus: newLane })
			} catch (e) {
				item.taskStatus = previous
				this.error =
					e?.message || this.t('decidesk', 'Could not update status.')
			}
		},
	},
}
</script>

<style scoped>
.ai-deck-board {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
}

.ai-deck-board__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
}

.ai-deck-board__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}

.ai-deck-board__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	margin-inline-start: 4px;
}

.ai-deck-board__lanes {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: var(--default-grid-baseline);
}

.ai-deck-lane {
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	padding: var(--default-grid-baseline);
	min-height: 80px;
}

.ai-deck-lane__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	font-weight: bold;
	margin-bottom: var(--default-grid-baseline);
}

.ai-deck-lane__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.ai-deck-lane__cards {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	list-style: none;
	margin: 0;
	padding: 0;
}

.ai-deck-lane__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	padding: 4px;
}

.ai-deck-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
}

.ai-deck-card__head {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 4px;
}

.ai-deck-card__title {
	font-weight: 500;
	background: none;
	border: none;
	padding: 0;
	text-align: start;
	cursor: pointer;
	color: var(--color-main-text);
	font-size: inherit;
}

.ai-deck-card__title:hover {
	text-decoration: underline;
}

.ai-deck-card__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.ai-deck-card__footer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
	margin-top: 6px;
}

.ai-deck-card__move {
	min-width: 140px;
}

.ai-deck-card__link {
	color: var(--color-primary-element);
	white-space: nowrap;
}
</style>
