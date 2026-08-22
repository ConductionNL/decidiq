<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Agenda builder: drag-and-drop ordering, proposals, spokespersons,
 recurring items, statutory ALV enforcement, and hierarchical sub-items.

 Sub-items (additive `parentItem` on AgendaItem) render nested under
 their parent; reordering keeps children grouped under their parent and
 persists the flattened parent→children order through the existing
 reorder endpoint. For `general_assembly` meetings a warning lists the
 missing statutory ALV items (BW 2:38).

 Dialogs live in src/dialogs/ per the modal-isolation rule (ADR-004).

 @spec openspec/specs/agenda-management/spec.md
-->
<template>
	<div
		class="agenda-builder"
		role="region"
		:aria-label="t('decidiq', 'Agenda builder')">
		<!-- Header with total duration -->
		<div class="agenda-builder__header">
			<h3 class="agenda-builder__title">
				{{ t('decidiq', 'Agenda builder') }}
			</h3>
			<span class="agenda-builder__duration" aria-live="polite">
				{{
					t('decidiq', 'Total duration: {min} min', {
						min: totalDuration,
					})
				}}
			</span>
			<div class="agenda-builder__actions">
				<NcButton
					v-if="canEdit && canShowRecurring"
					@click="showRecurringDialog = true">
					{{ t('decidiq', 'Add recurring items') }}
				</NcButton>
				<NcButton v-if="canEdit" @click="showProposeDialog = true">
					{{ t('decidiq', 'Propose agenda item') }}
				</NcButton>
			</div>
		</div>

		<!-- Statutory ALV items warning (BW 2:38) -->
		<NcNoteCard
			v-if="missingStatutory.length > 0"
			type="warning"
			data-testid="statutory-items-warning">
			<p>
				{{
					t(
						'decidiq',
						'This general assembly agenda is missing legally required items:',
					)
				}}
			</p>
			<ul class="agenda-builder__statutory-list">
				<li v-for="required in missingStatutory" :key="required.id">
					{{ t('decidiq', required.label) }}
				</li>
			</ul>
		</NcNoteCard>

		<!-- Proposal inbox (chair only) -->
		<div
			v-if="isChair && proposalItems.length > 0"
			class="agenda-builder__proposals">
			<h4>{{ t('decidiq', 'Proposed items') }}</h4>
			<ul class="agenda-builder__proposal-list" role="list">
				<li
					v-for="proposal in proposalItems"
					:key="proposal.id"
					class="agenda-builder__proposal-item"
					role="listitem">
					<span class="agenda-builder__proposal-title">{{
						proposal.title
					}}</span>
					<div class="agenda-builder__proposal-actions">
						<NcButton
							:aria-label="
								t('decidiq', 'Approve proposal {title}', {
									title: proposal.title,
								})
							"
							variant="success"
							@click="approveProposal(proposal)">
							{{ t('decidiq', 'Approve') }}
						</NcButton>
						<NcButton
							:aria-label="
								t('decidiq', 'Reject proposal {title}', {
									title: proposal.title,
								})
							"
							variant="error"
							@click="rejectProposal(proposal)">
							{{ t('decidiq', 'Reject') }}
						</NcButton>
					</div>
				</li>
			</ul>
		</div>

		<!-- Drag-and-drop tree list: top-level items with nested sub-items -->
		<ol
			ref="itemList"
			class="agenda-builder__list"
			role="list"
			:aria-label="t('decidiq', 'Agenda items, drag to reorder')">
			<li
				v-for="(node, index) in agendaTree"
				:key="node.item.id"
				class="agenda-builder__item"
				:draggable="isChair"
				role="listitem"
				:aria-label="
					t('decidiq', 'Agenda item {n}: {title}', {
						n: node.item.orderNumber,
						title: node.item.title,
					})
				"
				@dragstart="isChair ? onDragStart($event, index) : null"
				@dragover.prevent="isChair ? onDragOver($event, index) : null"
				@drop="isChair ? onDrop($event, index) : null"
				@dragend="isChair ? onDragEnd() : null"
				@keydown.up.prevent="moveTopLevel(index, -1)"
				@keydown.down.prevent="moveTopLevel(index, 1)">
				<div class="agenda-builder__item-row">
					<span class="agenda-builder__item-order" aria-hidden="true">
						{{ node.item.orderNumber }}
					</span>

					<CnStatusBadge
						:status="node.item.itemType"
						:aria-label="
							t('decidiq', 'Type: {type}', {
								type: node.item.itemType,
							})
						" />

					<span class="agenda-builder__item-title">{{
						node.item.title
					}}</span>

					<span
						v-if="node.item.estimatedDuration"
						class="agenda-builder__item-duration">
						{{ node.item.estimatedDuration }} {{ t('decidiq', 'min') }}
					</span>

					<!-- Spokesperson -->
					<span
						v-if="getSpokesperson(node.item)"
						class="agenda-builder__item-spokesperson">
						<NcUserBubble
							:user="getSpokesperson(node.item)"
							:showUserStatus="false" />
					</span>

					<!-- Attachment count -->
					<span
						v-if="(node.item.files || []).length > 0"
						class="agenda-builder__item-attachments"
						:aria-label="
							t('decidiq', '{n} attachment(s)', {
								n: (node.item.files || []).length,
							})
						">
						📎 {{ (node.item.files || []).length }}
					</span>

					<!-- COI badge -->
					<span
						v-if="coiCount(node.item) > 0"
						class="agenda-builder__item-coi"
						:aria-label="
							t(
								'decidiq',
								'{n} conflict of interest declaration(s)',
								{ n: coiCount(node.item) },
							)
						">
						{{ t('decidiq', 'COI ({n})', { n: coiCount(node.item) }) }}
					</span>

					<!-- Spokesperson assignment (chair/secretary only) -->
					<NcButton
						v-if="isChair"
						size="small"
						:aria-label="
							t('decidiq', 'Assign spokesperson for {title}', {
								title: node.item.title,
							})
						"
						@click="openSpokespersonDialog(node.item)">
						{{
							getSpokesperson(node.item)
								? t('decidiq', 'Change spokesperson')
								: t('decidiq', 'Assign spokesperson')
						}}
					</NcButton>

					<!-- Add sub-item (chair/secretary only) -->
					<NcButton
						v-if="isChair && !node.item.parentItem"
						size="small"
						data-testid="agenda-add-sub-item"
						:aria-label="
							t('decidiq', 'Add sub-item under {title}', {
								title: node.item.title,
							})
						"
						@click="openAddSubItemDialog(node.item)">
						{{ t('decidiq', 'Add sub-item') }}
					</NcButton>

					<!-- Move buttons (keyboard accessible) -->
					<NcButton
						v-if="isChair"
						size="small"
						:disabled="index === 0"
						:aria-label="
							t('decidiq', 'Move {title} up', {
								title: node.item.title,
							})
						"
						@click="moveTopLevel(index, -1)">
						↑
					</NcButton>
					<NcButton
						v-if="isChair"
						size="small"
						:disabled="index === agendaTree.length - 1"
						:aria-label="
							t('decidiq', 'Move {title} down', {
								title: node.item.title,
							})
						"
						@click="moveTopLevel(index, 1)">
						↓
					</NcButton>
				</div>

				<!-- Nested sub-items -->
				<ol
					v-if="node.children.length > 0"
					class="agenda-builder__sublist"
					role="list"
					:aria-label="
						t('decidiq', 'Sub-items of {title}', {
							title: node.item.title,
						})
					">
					<li
						v-for="(child, childIndex) in node.children"
						:key="child.id"
						class="agenda-builder__subitem"
						data-testid="agenda-sub-item"
						role="listitem"
						:aria-label="
							t('decidiq', 'Sub-item: {title}', {
								title: child.title,
							})
						"
						@keydown.up.prevent="moveChild(index, childIndex, -1)"
						@keydown.down.prevent="moveChild(index, childIndex, 1)">
						<span
							class="agenda-builder__subitem-marker"
							aria-hidden="true"
							>↳</span
						>

						<CnStatusBadge
							:status="child.itemType"
							:aria-label="
								t('decidiq', 'Type: {type}', {
									type: child.itemType,
								})
							" />

						<span class="agenda-builder__item-title">{{
							child.title
						}}</span>

						<span
							v-if="child.estimatedDuration"
							class="agenda-builder__item-duration">
							{{ child.estimatedDuration }} {{ t('decidiq', 'min') }}
						</span>

						<span
							v-if="getSpokesperson(child)"
							class="agenda-builder__item-spokesperson">
							<NcUserBubble
								:user="getSpokesperson(child)"
								:showUserStatus="false" />
						</span>

						<NcButton
							v-if="isChair"
							size="small"
							:aria-label="
								t('decidiq', 'Assign spokesperson for {title}', {
									title: child.title,
								})
							"
							@click="openSpokespersonDialog(child)">
							{{
								getSpokesperson(child)
									? t('decidiq', 'Change spokesperson')
									: t('decidiq', 'Assign spokesperson')
							}}
						</NcButton>

						<NcButton
							v-if="isChair"
							size="small"
							:disabled="childIndex === 0"
							:aria-label="
								t('decidiq', 'Move {title} up', {
									title: child.title,
								})
							"
							@click="moveChild(index, childIndex, -1)">
							↑
						</NcButton>
						<NcButton
							v-if="isChair"
							size="small"
							:disabled="childIndex === node.children.length - 1"
							:aria-label="
								t('decidiq', 'Move {title} down', {
									title: child.title,
								})
							"
							@click="moveChild(index, childIndex, 1)">
							↓
						</NcButton>
					</li>
				</ol>
			</li>
		</ol>

		<!-- Recurring items dialog -->
		<RecurringItemsDialog
			v-if="showRecurringDialog"
			:recurringItems="recurringItems"
			@add="addSelectedRecurring"
			@close="showRecurringDialog = false" />

		<!-- Propose item dialog -->
		<ProposeAgendaItemDialog
			v-if="showProposeDialog"
			@submit="submitProposal"
			@close="showProposeDialog = false" />

		<!-- Spokesperson selector dialog -->
		<SpokespersonDialog
			v-if="spokespersonDialog.open"
			:participants="participants"
			:hasSpokesperson="!!getSpokesperson(spokespersonDialog.item)"
			@assign="assignSpokesperson(spokespersonDialog.item, $event)"
			@remove="removeSpokesperson(spokespersonDialog.item)"
			@close="spokespersonDialog.open = false" />

		<!-- Add sub-item dialog -->
		<AddSubItemDialog
			v-if="addSubItemDialog.open"
			:parentTitle="
				addSubItemDialog.parent ? addSubItemDialog.parent.title : ''
			"
			@submit="createSubItem"
			@close="addSubItemDialog.open = false" />
	</div>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import { NcButton, NcNoteCard, NcUserBubble } from '@nextcloud/vue'
import AddSubItemDialog from '../dialogs/AddSubItemDialog.vue'
import ProposeAgendaItemDialog from '../dialogs/ProposeAgendaItemDialog.vue'
import RecurringItemsDialog from '../dialogs/RecurringItemsDialog.vue'
import SpokespersonDialog from '../dialogs/SpokespersonDialog.vue'
import {
	buildAgendaTree,
	flattenTree,
	missingStatutoryItems,
} from '../services/agendaRules.js'
import { fetchRecurringAgendaItems } from '../services/recurringAgendaItems.js'
import { useObjectStore } from '../store/store.js'

/**
 * @spec openspec/specs/agenda-management/spec.md
 */
export default {
	name: 'AgendaBuilder',

	components: {
		AddSubItemDialog,
		CnStatusBadge,
		NcButton,
		NcNoteCard,
		NcUserBubble,
		ProposeAgendaItemDialog,
		RecurringItemsDialog,
		SpokespersonDialog,
	},

	props: {
		/** UUID of the meeting whose agenda is being built */
		meetingId: { type: String, required: true },
		/** Whether to show edit controls (chair/secretary) */
		isChair: { type: Boolean, default: false },
		/** Meeting lifecycle (scheduled/opened/paused/etc.) */
		lifecycle: { type: String, default: 'scheduled' },
		/** Meeting type — `general_assembly` activates statutory ALV enforcement */
		meetingType: { type: String, default: '' },
		/** Agenda items for this meeting */
		items: { type: Array, default: () => [] },
		/** Participants for spokesperson assignment */
		participants: { type: Array, default: () => [] },
	},

	emits: ['reordered', 'item-updated'],

	/** @spec exclude setup() only wires the shared object store ref; no domain logic */
	setup() {
		const objectStore = useObjectStore()
		return { objectStore }
	},

	data() {
		return {
			/** Items in current sort order (mutable copy) */
			localItems: [],
			/** Index of the top-level node being dragged */
			dragIndex: null,
			showRecurringDialog: false,
			showProposeDialog: false,
			recurringItems: [],
			spokespersonDialog: { open: false, item: null },
			addSubItemDialog: { open: false, parent: null },
		}
	},

	computed: {
		/** @spec openspec/specs/agenda-management/spec.md */
		agendaTree() {
			return buildAgendaTree(
				this.localItems.filter((i) => i.status !== 'voorstel'),
			)
		},

		/** @spec openspec/specs/agenda-management/spec.md */
		missingStatutory() {
			return missingStatutoryItems(this.meetingType, this.localItems)
		},

		/** @spec openspec/specs/agenda-management/spec.md */
		totalDuration() {
			return this.localItems.reduce((sum, item) => {
				const d = item.estimatedDuration
				return sum + (d != null ? d : 0)
			}, 0)
		},

		/** @spec openspec/specs/agenda-management/spec.md */
		proposalItems() {
			return this.localItems.filter((i) => i.status === 'voorstel')
		},

		/** @spec openspec/specs/agenda-management/spec.md */
		canEdit() {
			return ['scheduled', 'opened'].includes(this.lifecycle)
		},

		/** @spec openspec/specs/agenda-management/spec.md */
		canShowRecurring() {
			return true
		},
	},

	watch: {
		items: {
			immediate: true,
			deep: true,
			/**
			 * @param val
			 * @spec openspec/specs/agenda-management/spec.md
			 */
			handler(val) {
				this.localItems = val ? val.slice() : []
			},
		},
	},

	/** @spec exclude lifecycle hook; body only calls the already-spec'd loadRecurringItems() */
	created() {
		this.loadRecurringItems()
	},

	methods: {
		// -----------------------------------------------------------------------
		// Drag-and-drop helpers (top-level nodes; children follow their parent)
		// -----------------------------------------------------------------------

		/**
		 * @param event
		 * @param index
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		onDragStart(event, index) {
			this.dragIndex = index
			if (event.dataTransfer) {
				event.dataTransfer.effectAllowed = 'move'
			}
		},

		/**
		 * @param event
		 * @param index
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		onDragOver(event, index) {
			if (this.dragIndex === null || this.dragIndex === index) return
			event.preventDefault()
		},

		/**
		 * @param event
		 * @param targetIndex
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		onDrop(event, targetIndex) {
			if (this.dragIndex === null || this.dragIndex === targetIndex) return
			const tree = this.agendaTree.slice()
			const [moved] = tree.splice(this.dragIndex, 1)
			tree.splice(targetIndex, 0, moved)
			this.dragIndex = null
			this.applyTreeOrder(tree)
		},

		/** @spec openspec/specs/agenda-management/spec.md */
		onDragEnd() {
			this.dragIndex = null
		},

		/**
		 * @param index
		 * @param delta
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		moveTopLevel(index, delta) {
			const target = index + delta
			if (target < 0 || target >= this.agendaTree.length) return
			const tree = this.agendaTree.slice()
			;[tree[index], tree[target]] = [tree[target], tree[index]]
			this.applyTreeOrder(tree)
		},

		/**
		 * @param parentIndex
		 * @param childIndex
		 * @param delta
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		moveChild(parentIndex, childIndex, delta) {
			const target = childIndex + delta
			const tree = this.agendaTree.map((node) => ({
				item: node.item,
				children: node.children.slice(),
			}))
			const siblings = tree[parentIndex]?.children
			if (!siblings || target < 0 || target >= siblings.length) return
			;[siblings[childIndex], siblings[target]] = [
				siblings[target],
				siblings[childIndex],
			]
			this.applyTreeOrder(tree)
		},

		/**
		 * Flatten the tree parent→children, renumber globally, and persist.
		 *
		 * @param {Array<object>} tree Reordered agenda tree.
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		applyTreeOrder(tree) {
			const flat = flattenTree(tree)
			flat.forEach((item, i) => {
				item.orderNumber = i + 1
			})
			this.localItems = flat.concat(this.proposalItems)
			this.persistReorder(flat.map((i) => i.id))
		},

		/**
		 * @param ids
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		async persistReorder(ids) {
			try {
				const response = await fetch(
					OC.generateUrl(
						`/apps/decidiq/api/agendas/${this.meetingId}/reorder`,
					),
					{
						method: 'PUT',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({ ids }),
					},
				)
				if (!response.ok) {
					console.error(
						'Failed to persist agenda reorder:',
						response.status,
					)
					return
				}
				this.$emit('reordered', ids)
			} catch (e) {
				console.error('Failed to persist agenda reorder:', e)
			}
		},

		// -----------------------------------------------------------------------
		// Sub-items
		// -----------------------------------------------------------------------

		/**
		 * @param parent
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		openAddSubItemDialog(parent) {
			this.addSubItemDialog = { open: true, parent }
		},

		/**
		 * @param payload
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		async createSubItem(payload) {
			const parent = this.addSubItemDialog.parent
			if (!parent) return
			const maxOrder = this.localItems.reduce(
				(max, i) => Math.max(max, i.orderNumber ?? 0),
				0,
			)
			try {
				await this.objectStore.saveObject('agenda-item', {
					title: payload.title,
					itemType: payload.itemType,
					estimatedDuration: payload.estimatedDuration,
					parentItem: parent.id,
					orderNumber: maxOrder + 1,
					relations: { meeting: [{ id: this.meetingId }] },
				})
				this.$emit('item-updated', null)
			} catch (e) {
				console.error('Failed to create sub-item:', e)
			} finally {
				this.addSubItemDialog = { open: false, parent: null }
			}
		},

		// -----------------------------------------------------------------------
		// Spokesperson
		// -----------------------------------------------------------------------

		/**
		 * @param item
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		getSpokesperson(item) {
			return item?.relations?.spokesperson?.[0]?.owner ?? null
		},

		/**
		 * @param item
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		openSpokespersonDialog(item) {
			this.spokespersonDialog = { open: true, item }
		},

		/**
		 * @param item
		 * @param participant
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		async assignSpokesperson(item, participant) {
			try {
				await this.objectStore.saveObject('agenda-item', {
					...item,
					relations: {
						...(item.relations ?? {}),
						spokesperson: [{ id: participant.id }],
					},
				})
				this.$emit('item-updated', item.id)
			} catch (e) {
				console.error('Failed to assign spokesperson:', e)
			} finally {
				this.spokespersonDialog.open = false
			}
		},

		/**
		 * @param item
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		async removeSpokesperson(item) {
			try {
				const relations = { ...(item.relations ?? {}) }
				delete relations.spokesperson
				await this.objectStore.saveObject('agenda-item', {
					...item,
					relations,
				})
				this.$emit('item-updated', item.id)
			} catch (e) {
				console.error('Failed to remove spokesperson:', e)
			} finally {
				this.spokespersonDialog.open = false
			}
		},

		// -----------------------------------------------------------------------
		// COI badge
		// -----------------------------------------------------------------------

		/**
		 * @param item
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		coiCount(item) {
			const notes = item?.notes ?? []
			return notes.filter((n) => (n.title ?? '').startsWith('COI:')).length
		},

		// -----------------------------------------------------------------------
		// Recurring items
		// -----------------------------------------------------------------------

		/**
		 * Load the reusable "recurring" agenda-item templates for the
		 * Add-recurring-items dialog.
		 *
		 * ⚠️ DO NOT PUT THIS BACK ON `objectStore.fetchCollection()`.
		 * The shared object store keeps exactly ONE collection slot per type
		 * (`this.collections = { ...this.collections, [type]: results }` in
		 * `useObjectStore.fetchCollection`), so any differently-filtered fetch of
		 * the same type OVERWRITES the previous one. This component is mounted by
		 * LiveMeeting only for the chair, and its `created()` hook fired
		 * `agenda-item?isRecurring=true` right after LiveMeeting's own
		 * `agenda-item?meeting=<id>` — the recurring response landed last and
		 * replaced the meeting's agenda in the cache with templates belonging to
		 * other meetings. `LiveMeeting.allItems` then filtered them all away, so
		 * the CHAIR saw an empty agenda and an empty "Activate item" list while a
		 * non-chair (who never mounts this component) saw the agenda correctly.
		 *
		 * Measured on the dev instance, 2026-08-16: `agenda-item?meeting=<id>`
		 * returned `total: 1`, `agenda-item?isRecurring=true` returned `total: 2`,
		 * and `.live-meeting__activate-list` rendered as an empty `<ul>`.
		 *
		 * This is a read whose result is component-local (`recurringItems` feeds
		 * one dialog), so it must not touch the shared cache at all.
		 *
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		async loadRecurringItems() {
			try {
				this.recurringItems = await fetchRecurringAgendaItems()
			} catch (e) {
				console.error('Failed to load recurring items:', e)
			}
		},

		/**
		 * @param selectedIds
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		async addSelectedRecurring(selectedIds) {
			let order = this.localItems.length + 1

			for (const srcId of selectedIds) {
				const src = this.recurringItems.find((r) => r.id === srcId)
				if (!src) continue

				try {
					await this.objectStore.saveObject('agenda-item', {
						title: src.title,
						itemType: src.itemType,
						estimatedDuration: src.estimatedDuration,
						isRecurring: true,
						orderNumber: order,
						relations: { meeting: [{ id: this.meetingId }] },
					})
					order++
				} catch (e) {
					console.error('Failed to add recurring item:', e)
				}
			}

			this.showRecurringDialog = false
			this.$emit('item-updated', null)
		},

		// -----------------------------------------------------------------------
		// Proposal inbox (chair)
		// -----------------------------------------------------------------------

		/**
		 * @param item
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		async approveProposal(item) {
			const nextOrder =
				this.localItems.filter((i) => i.status !== 'voorstel').length + 1
			try {
				await this.objectStore.saveObject('agenda-item', {
					...item,
					status: null,
					orderNumber: nextOrder,
				})
				this.$emit('item-updated', item.id)
			} catch (e) {
				console.error('Failed to approve proposal:', e)
			}
		},

		/**
		 * @param item
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		async rejectProposal(item) {
			try {
				await this.objectStore.saveObject('agenda-item', {
					...item,
					status: 'rejected',
				})
				this.$emit('item-updated', item.id)
			} catch (e) {
				console.error('Failed to reject proposal:', e)
			}
		},

		// -----------------------------------------------------------------------
		// Submit proposal (all participants)
		// -----------------------------------------------------------------------

		/**
		 * @param payload
		 * @spec openspec/specs/agenda-management/spec.md
		 */
		async submitProposal(payload) {
			try {
				await this.objectStore.saveObject('agenda-item', {
					title: payload.title,
					description: payload.description,
					status: 'voorstel',
					itemType: 'discussion',
					orderNumber: 999,
					relations: { meeting: [{ id: this.meetingId }] },
				})
				this.showProposeDialog = false
				this.$emit('item-updated', null)
			} catch (e) {
				console.error('Failed to submit proposal:', e)
			}
		},
	},
}
</script>

<style scoped>
.agenda-builder {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.agenda-builder__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
	flex-wrap: wrap;
}

.agenda-builder__title {
	margin: 0;
	font-size: var(--default-font-size);
	font-weight: 700;
}

.agenda-builder__duration {
	color: var(--color-text-maxcontrast);
	font-size: var(--default-font-size);
}

.agenda-builder__actions {
	display: flex;
	gap: var(--default-grid-baseline);
}

.agenda-builder__statutory-list {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 4);
	list-style: disc;
}

.agenda-builder__proposals {
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	padding: var(--default-grid-baseline);
	background: var(--color-background-hover);
}

.agenda-builder__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.agenda-builder__item {
	display: flex;
	flex-direction: column;
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
	cursor: grab;
}

.agenda-builder__item:last-child {
	border-bottom: none;
}

.agenda-builder__item:focus-within {
	outline: 2px solid var(--color-primary-element);
	border-radius: var(--border-radius);
}

.agenda-builder__item-row {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.agenda-builder__sublist {
	list-style: none;
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 8);
}

.agenda-builder__subitem {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline) 0;
	border-top: 1px dashed var(--color-border);
}

.agenda-builder__subitem-marker {
	color: var(--color-text-maxcontrast);
	font-weight: 700;
}

.agenda-builder__item-order {
	min-width: 2rem;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	text-align: right;
}

.agenda-builder__item-title {
	flex: 1;
}

.agenda-builder__item-duration {
	color: var(--color-text-maxcontrast);
	font-size: calc(var(--default-font-size) * 0.875);
}

.agenda-builder__item-coi {
	background: var(--color-error);
	color: var(--color-primary-element-text);
	border-radius: var(--border-radius-pill);
	padding: 0 var(--default-grid-baseline);
	font-size: calc(var(--default-font-size) * 0.75);
}

.agenda-builder__item-attachments {
	color: var(--color-text-maxcontrast);
	font-size: calc(var(--default-font-size) * 0.875);
}

.agenda-builder__proposal-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.agenda-builder__proposal-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.agenda-builder__proposal-item:last-child {
	border-bottom: none;
}

.agenda-builder__proposal-actions {
	display: flex;
	gap: var(--default-grid-baseline);
}
</style>
