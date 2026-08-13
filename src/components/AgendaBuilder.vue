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
		:aria-label="t('decidesk', 'Agenda builder')">
		<!-- Header with total duration -->
		<div class="agenda-builder__header">
			<h3 class="agenda-builder__title">
				{{ t('decidesk', 'Agenda builder') }}
			</h3>
			<span class="agenda-builder__duration" aria-live="polite">
				{{
					t('decidesk', 'Total duration: {min} min', {
						min: totalDuration,
					})
				}}
			</span>
			<div class="agenda-builder__actions">
				<NcButton
					v-if="canEdit && canShowRecurring"
					@click="showRecurringDialog = true">
					{{ t('decidesk', 'Add recurring items') }}
				</NcButton>
				<NcButton v-if="canEdit" @click="showProposeDialog = true">
					{{ t('decidesk', 'Propose agenda item') }}
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
						'decidesk',
						'This general assembly agenda is missing legally required items:',
					)
				}}
			</p>
			<ul class="agenda-builder__statutory-list">
				<li v-for="required in missingStatutory" :key="required.id">
					{{ t('decidesk', required.label) }}
				</li>
			</ul>
		</NcNoteCard>

		<!-- Proposal inbox (chair only) -->
		<div
			v-if="isChair && proposalItems.length > 0"
			class="agenda-builder__proposals">
			<h4>{{ t('decidesk', 'Proposed items') }}</h4>
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
								t('decidesk', 'Approve proposal {title}', {
									title: proposal.title,
								})
							"
							variant="success"
							@click="approveProposal(proposal)">
							{{ t('decidesk', 'Approve') }}
						</NcButton>
						<NcButton
							:aria-label="
								t('decidesk', 'Reject proposal {title}', {
									title: proposal.title,
								})
							"
							variant="error"
							@click="rejectProposal(proposal)">
							{{ t('decidesk', 'Reject') }}
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
			:aria-label="t('decidesk', 'Agenda items, drag to reorder')">
			<li
				v-for="(node, index) in agendaTree"
				:key="node.item.id"
				class="agenda-builder__item"
				:draggable="isChair"
				role="listitem"
				:aria-label="
					t('decidesk', 'Agenda item {n}: {title}', {
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
							t('decidesk', 'Type: {type}', {
								type: node.item.itemType,
							})
						" />

					<span class="agenda-builder__item-title">{{
						node.item.title
					}}</span>

					<span
						v-if="node.item.estimatedDuration"
						class="agenda-builder__item-duration">
						{{ node.item.estimatedDuration }} {{ t('decidesk', 'min') }}
					</span>

					<!-- Spokesperson -->
					<span
						v-if="getSpokesperson(node.item)"
						class="agenda-builder__item-spokesperson">
						<NcUserBubble
							:user="getSpokesperson(node.item)"
							:show-user-status="false" />
					</span>

					<!-- Attachment count -->
					<span
						v-if="(node.item.files || []).length > 0"
						class="agenda-builder__item-attachments"
						:aria-label="
							t('decidesk', '{n} attachment(s)', {
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
								'decidesk',
								'{n} conflict of interest declaration(s)',
								{ n: coiCount(node.item) },
							)
						">
						{{ t('decidesk', 'COI ({n})', { n: coiCount(node.item) }) }}
					</span>

					<!-- Spokesperson assignment (chair/secretary only) -->
					<NcButton
						v-if="isChair"
						size="small"
						:aria-label="
							t('decidesk', 'Assign spokesperson for {title}', {
								title: node.item.title,
							})
						"
						@click="openSpokespersonDialog(node.item)">
						{{
							getSpokesperson(node.item)
								? t('decidesk', 'Change spokesperson')
								: t('decidesk', 'Assign spokesperson')
						}}
					</NcButton>

					<!-- Add sub-item (chair/secretary only) -->
					<NcButton
						v-if="isChair && !node.item.parentItem"
						size="small"
						data-testid="agenda-add-sub-item"
						:aria-label="
							t('decidesk', 'Add sub-item under {title}', {
								title: node.item.title,
							})
						"
						@click="openAddSubItemDialog(node.item)">
						{{ t('decidesk', 'Add sub-item') }}
					</NcButton>

					<!-- Move buttons (keyboard accessible) -->
					<NcButton
						v-if="isChair"
						size="small"
						:disabled="index === 0"
						:aria-label="
							t('decidesk', 'Move {title} up', {
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
							t('decidesk', 'Move {title} down', {
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
						t('decidesk', 'Sub-items of {title}', {
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
							t('decidesk', 'Sub-item: {title}', {
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
								t('decidesk', 'Type: {type}', {
									type: child.itemType,
								})
							" />

						<span class="agenda-builder__item-title">{{
							child.title
						}}</span>

						<span
							v-if="child.estimatedDuration"
							class="agenda-builder__item-duration">
							{{ child.estimatedDuration }} {{ t('decidesk', 'min') }}
						</span>

						<span
							v-if="getSpokesperson(child)"
							class="agenda-builder__item-spokesperson">
							<NcUserBubble
								:user="getSpokesperson(child)"
								:show-user-status="false" />
						</span>

						<NcButton
							v-if="isChair"
							size="small"
							:aria-label="
								t('decidesk', 'Assign spokesperson for {title}', {
									title: child.title,
								})
							"
							@click="openSpokespersonDialog(child)">
							{{
								getSpokesperson(child)
									? t('decidesk', 'Change spokesperson')
									: t('decidesk', 'Assign spokesperson')
							}}
						</NcButton>

						<NcButton
							v-if="isChair"
							size="small"
							:disabled="childIndex === 0"
							:aria-label="
								t('decidesk', 'Move {title} up', {
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
								t('decidesk', 'Move {title} down', {
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
			:recurring-items="recurringItems"
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
			:has-spokesperson="!!getSpokesperson(spokespersonDialog.item)"
			@assign="assignSpokesperson(spokespersonDialog.item, $event)"
			@remove="removeSpokesperson(spokespersonDialog.item)"
			@close="spokespersonDialog.open = false" />

		<!-- Add sub-item dialog -->
		<AddSubItemDialog
			v-if="addSubItemDialog.open"
			:parent-title="
				addSubItemDialog.parent ? addSubItemDialog.parent.title : ''
			"
			@submit="createSubItem"
			@close="addSubItemDialog.open = false" />
	</div>
</template>

<script>
import { NcButton, NcNoteCard, NcUserBubble } from '@nextcloud/vue'
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/store.js'
import AddSubItemDialog from '../dialogs/AddSubItemDialog.vue'
import ProposeAgendaItemDialog from '../dialogs/ProposeAgendaItemDialog.vue'
import RecurringItemsDialog from '../dialogs/RecurringItemsDialog.vue'
import SpokespersonDialog from '../dialogs/SpokespersonDialog.vue'
import {
	buildAgendaTree,
	flattenTree,
	missingStatutoryItems,
} from '../services/agendaRules.js'

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
			/** @spec openspec/specs/agenda-management/spec.md */
			handler(val) {
				this.localItems = val ? val.slice() : []
			},
		},
	},

	methods: {
		// -----------------------------------------------------------------------
		// Drag-and-drop helpers (top-level nodes; children follow their parent)
		// -----------------------------------------------------------------------

		/** @spec openspec/specs/agenda-management/spec.md */
		onDragStart(event, index) {
			this.dragIndex = index
			if (event.dataTransfer) {
				event.dataTransfer.effectAllowed = 'move'
			}
		},

		/** @spec openspec/specs/agenda-management/spec.md */
		onDragOver(event, index) {
			if (this.dragIndex === null || this.dragIndex === index) return
			event.preventDefault()
		},

		/** @spec openspec/specs/agenda-management/spec.md */
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

		/** @spec openspec/specs/agenda-management/spec.md */
		moveTopLevel(index, delta) {
			const target = index + delta
			if (target < 0 || target >= this.agendaTree.length) return
			const tree = this.agendaTree.slice()
			;[tree[index], tree[target]] = [tree[target], tree[index]]
			this.applyTreeOrder(tree)
		},

		/** @spec openspec/specs/agenda-management/spec.md */
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

		/** @spec openspec/specs/agenda-management/spec.md */
		async persistReorder(ids) {
			try {
				const response = await fetch(
					OC.generateUrl(
						`/apps/decidesk/api/agendas/${this.meetingId}/reorder`,
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

		/** @spec openspec/specs/agenda-management/spec.md */
		openAddSubItemDialog(parent) {
			this.addSubItemDialog = { open: true, parent }
		},

		/** @spec openspec/specs/agenda-management/spec.md */
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

		/** @spec openspec/specs/agenda-management/spec.md */
		getSpokesperson(item) {
			return item?.relations?.spokesperson?.[0]?.owner ?? null
		},

		/** @spec openspec/specs/agenda-management/spec.md */
		openSpokespersonDialog(item) {
			this.spokespersonDialog = { open: true, item }
		},

		/** @spec openspec/specs/agenda-management/spec.md */
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

		/** @spec openspec/specs/agenda-management/spec.md */
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

		/** @spec openspec/specs/agenda-management/spec.md */
		coiCount(item) {
			const notes = item?.notes ?? []
			return notes.filter((n) => (n.title ?? '').startsWith('COI:')).length
		},

		// -----------------------------------------------------------------------
		// Recurring items
		// -----------------------------------------------------------------------

		/** @spec openspec/specs/agenda-management/spec.md */
		async loadRecurringItems() {
			try {
				const all = await this.objectStore.fetchCollection('agenda-item', {
					isRecurring: true,
				})
				this.recurringItems = all ?? []
			} catch (e) {
				console.error('Failed to load recurring items:', e)
			}
		},

		/** @spec openspec/specs/agenda-management/spec.md */
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

		/** @spec openspec/specs/agenda-management/spec.md */
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

		/** @spec openspec/specs/agenda-management/spec.md */
		async rejectProposal(item) {
			try {
				await this.objectStore.saveObject('agenda-item', {
					...item,
					status: 'afgewezen',
				})
				this.$emit('item-updated', item.id)
			} catch (e) {
				console.error('Failed to reject proposal:', e)
			}
		},

		// -----------------------------------------------------------------------
		// Submit proposal (all participants)
		// -----------------------------------------------------------------------

		/** @spec openspec/specs/agenda-management/spec.md */
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

	/** @spec exclude lifecycle hook; body only calls the already-spec'd loadRecurringItems() */
	created() {
		this.loadRecurringItems()
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
