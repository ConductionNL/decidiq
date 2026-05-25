<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p2-agenda-management/tasks.md#task-2.1
 @spec openspec/changes/p2-agenda-management/tasks.md#task-2.2
 @spec openspec/changes/p2-agenda-management/tasks.md#task-2.3
 @spec openspec/changes/p2-agenda-management/tasks.md#task-2.4
 @spec openspec/changes/p2-agenda-management/tasks.md#task-2.5
 @spec openspec/changes/p2-agenda-management/tasks.md#task-2.6
 @spec openspec/changes/p2-agenda-management/tasks.md#task-2.7
 @spec openspec/changes/p2-agenda-management/tasks.md#task-5.2
-->
<template>
	<div class="agenda-builder" role="region" :aria-label="t('decidesk', 'Agenda builder')">
		<!-- Header with total duration -->
		<div class="agenda-builder__header">
			<h3 class="agenda-builder__title">
				{{ t('decidesk', 'Agenda builder') }}
			</h3>
			<span class="agenda-builder__duration" aria-live="polite">
				{{ t('decidesk', 'Total duration: {min} min', { min: totalDuration }) }}
			</span>
			<div class="agenda-builder__actions">
				<NcButton
					v-if="canEdit && canShowRecurring"
					@click="showRecurringDialog = true">
					{{ t('decidesk', 'Add recurring items') }}
				</NcButton>
				<NcButton
					v-if="canEdit"
					@click="showProposeDialog = true">
					{{ t('decidesk', 'Propose agenda item') }}
				</NcButton>
			</div>
		</div>

		<!-- Proposal inbox (chair only) -->
		<div v-if="isChair && proposalItems.length > 0" class="agenda-builder__proposals">
			<h4>{{ t('decidesk', 'Proposed items') }}</h4>
			<ul class="agenda-builder__proposal-list" role="list">
				<li v-for="proposal in proposalItems"
					:key="proposal.id"
					class="agenda-builder__proposal-item"
					role="listitem">
					<span class="agenda-builder__proposal-title">{{ proposal.title }}</span>
					<div class="agenda-builder__proposal-actions">
						<NcButton
							:aria-label="t('decidesk', 'Approve proposal {title}', { title: proposal.title })"
							type="success"
							@click="approveProposal(proposal)">
							{{ t('decidesk', 'Approve') }}
						</NcButton>
						<NcButton
							:aria-label="t('decidesk', 'Reject proposal {title}', { title: proposal.title })"
							type="error"
							@click="rejectProposal(proposal)">
							{{ t('decidesk', 'Reject') }}
						</NcButton>
					</div>
				</li>
			</ul>
		</div>

		<!-- Drag-and-drop item list -->
		<ol
			ref="itemList"
			class="agenda-builder__list"
			role="list"
			:aria-label="t('decidesk', 'Agenda items, drag to reorder')">
			<li
				v-for="(item, index) in sortedItems"
				:key="item.id"
				class="agenda-builder__item"
				:draggable="isChair"
				role="listitem"
				:aria-label="t('decidesk', 'Agenda item {n}: {title}', { n: item.orderNumber, title: item.title })"
				@dragstart="isChair ? onDragStart($event, index) : null"
				@dragover.prevent="isChair ? onDragOver($event, index) : null"
				@drop="isChair ? onDrop($event, index) : null"
				@dragend="isChair ? onDragEnd() : null"
				@keydown.up.prevent="moveUp(index)"
				@keydown.down.prevent="moveDown(index)">
				<span class="agenda-builder__item-order" aria-hidden="true">
					{{ item.orderNumber }}
				</span>

				<CnStatusBadge
					:status="item.itemType"
					:aria-label="t('decidesk', 'Type: {type}', { type: item.itemType })" />

				<span class="agenda-builder__item-title">{{ item.title }}</span>

				<span v-if="item.estimatedDuration" class="agenda-builder__item-duration">
					{{ item.estimatedDuration }} {{ t('decidesk', 'min') }}
				</span>

				<!-- Spokesperson -->
				<span v-if="getSpokesperson(item)" class="agenda-builder__item-spokesperson">
					<NcUserBubble :user="getSpokesperson(item)" :show-user-status="false" />
				</span>

				<!-- Attachment count -->
				<span
					v-if="(item.files || []).length > 0"
					class="agenda-builder__item-attachments"
					:aria-label="t('decidesk', '{n} attachment(s)', { n: (item.files || []).length })">
					📎 {{ (item.files || []).length }}
				</span>

				<!-- COI badge -->
				<span
					v-if="coiCount(item) > 0"
					class="agenda-builder__item-coi"
					:aria-label="t('decidesk', '{n} conflict of interest declaration(s)', { n: coiCount(item) })">
					{{ t('decidesk', 'COI ({n})', { n: coiCount(item) }) }}
				</span>

				<!-- Spokesperson assignment (chair/secretary only) -->
				<NcButton
					v-if="isChair"
					size="small"
					:aria-label="t('decidesk', 'Assign spokesperson for {title}', { title: item.title })"
					@click="openSpokespersonDialog(item)">
					{{ getSpokesperson(item) ? t('decidesk', 'Change spokesperson') : t('decidesk', 'Assign spokesperson') }}
				</NcButton>

				<!-- Move buttons (keyboard accessible) -->
				<NcButton
					v-if="isChair"
					size="small"
					:disabled="index === 0"
					:aria-label="t('decidesk', 'Move {title} up', { title: item.title })"
					@click="moveUp(index)">
					↑
				</NcButton>
				<NcButton
					v-if="isChair"
					size="small"
					:disabled="index === sortedItems.length - 1"
					:aria-label="t('decidesk', 'Move {title} down', { title: item.title })"
					@click="moveDown(index)">
					↓
				</NcButton>
			</li>
		</ol>

		<!-- Recurring items dialog -->
		<NcDialog
			v-if="showRecurringDialog"
			:name="t('decidesk', 'Add recurring items')"
			@closing="showRecurringDialog = false">
			<template #default>
				<ul v-if="recurringItems.length > 0" class="agenda-builder__recurring-list" role="list">
					<li v-for="rItem in recurringItems"
						:key="rItem.id"
						class="agenda-builder__recurring-item"
						role="listitem">
						<NcCheckboxRadioSwitch
							:checked="selectedRecurring.includes(rItem.id)"
							@update:checked="toggleRecurring(rItem.id)">
							{{ rItem.title }}
						</NcCheckboxRadioSwitch>
					</li>
				</ul>
				<p v-else>
					{{ t('decidesk', 'No recurring agenda items found.') }}
				</p>
			</template>
			<template #actions>
				<NcButton :disabled="selectedRecurring.length === 0" @click="addSelectedRecurring">
					{{ t('decidesk', 'Add selected') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Propose item dialog -->
		<NcDialog
			v-if="showProposeDialog"
			:name="t('decidesk', 'Propose agenda item')"
			@closing="showProposeDialog = false">
			<template #default>
				<p>{{ t('decidesk', 'Fill in the agenda item details. The chair will approve or reject your proposal.') }}</p>
				<NcTextField
					v-model="proposalTitle"
					:label="t('decidesk', 'Title')"
					:placeholder="t('decidesk', 'Agenda item title')"
					required />
				<NcTextArea
					v-model="proposalDescription"
					:label="t('decidesk', 'Description')"
					:placeholder="t('decidesk', 'Describe the agenda item')" />
			</template>
			<template #actions>
				<NcButton :disabled="!proposalTitle" @click="submitProposal">
					{{ t('decidesk', 'Submit proposal') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Spokesperson selector dialog -->
		<NcDialog
			v-if="spokespersonDialog.open"
			:name="t('decidesk', 'Assign spokesperson')"
			@closing="spokespersonDialog.open = false">
			<template #default>
				<ul v-if="participants.length > 0" class="agenda-builder__participant-list" role="list">
					<li v-for="p in participants"
						:key="p.id"
						class="agenda-builder__participant-item"
						role="listitem">
						<NcButton @click="assignSpokesperson(spokespersonDialog.item, p)">
							{{ p.displayName }}
						</NcButton>
					</li>
				</ul>
				<p v-else>
					{{ t('decidesk', 'No participants found.') }}
				</p>
				<NcButton
					v-if="getSpokesperson(spokespersonDialog.item)"
					type="error"
					@click="removeSpokesperson(spokespersonDialog.item)">
					{{ t('decidesk', 'Remove spokesperson') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcTextField, NcTextArea, NcCheckboxRadioSwitch, NcUserBubble } from '@nextcloud/vue'
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/store.js'

/**
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-2.1
 */
export default {
	name: 'AgendaBuilder',

	components: {
		NcButton,
		NcDialog,
		NcTextField,
		NcTextArea,
		NcCheckboxRadioSwitch,
		NcUserBubble,
		CnStatusBadge,
	},

	props: {
		/** UUID of the meeting whose agenda is being built */
		meetingId: { type: String, required: true },
		/** Whether to show edit controls (chair/secretary) */
		isChair: { type: Boolean, default: false },
		/** Meeting lifecycle (scheduled/opened/paused/etc.) */
		lifecycle: { type: String, default: 'scheduled' },
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
			/** Index of item being dragged */
			dragIndex: null,
			showRecurringDialog: false,
			showProposeDialog: false,
			proposalTitle: '',
			proposalDescription: '',
			selectedRecurring: [],
			recurringItems: [],
			spokespersonDialog: { open: false, item: null },
		}
	},

	computed: {
		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.1 */
		sortedItems() {
			return this.localItems.slice().sort((a, b) => (a.orderNumber ?? 0) - (b.orderNumber ?? 0))
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.2 */
		totalDuration() {
			return this.localItems.reduce((sum, item) => {
				const d = item.estimatedDuration
				return sum + (d != null ? d : 0)
			}, 0)
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.5 */
		proposalItems() {
			return this.localItems.filter(i => i.status === 'voorstel')
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.4 */
		canEdit() {
			return ['scheduled', 'opened'].includes(this.lifecycle)
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.3 */
		canShowRecurring() {
			return true
		},
	},

	watch: {
		items: {
			immediate: true,
			deep: true,
			/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.1 */
			handler(val) {
				this.localItems = val ? val.slice() : []
			},
		},
	},

	methods: {
		// -----------------------------------------------------------------------
		// Drag-and-drop helpers
		// -----------------------------------------------------------------------

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.1 */
		onDragStart(event, index) {
			this.dragIndex = index
			if (event.dataTransfer) {
				event.dataTransfer.effectAllowed = 'move'
			}
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.1 */
		onDragOver(event, index) {
			if (this.dragIndex === null || this.dragIndex === index) return
			event.preventDefault()
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.1 */
		onDrop(event, targetIndex) {
			if (this.dragIndex === null || this.dragIndex === targetIndex) return
			const reordered = this.sortedItems.slice()
			const [moved] = reordered.splice(this.dragIndex, 1)
			reordered.splice(targetIndex, 0, moved)
			reordered.forEach((item, i) => { item.orderNumber = i + 1 })
			this.localItems = reordered
			this.dragIndex = null
			this.persistReorder()
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.1 */
		onDragEnd() {
			this.dragIndex = null
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.7 */
		moveUp(index) {
			if (index === 0) return
			const reordered = this.sortedItems.slice()
			;[reordered[index - 1], reordered[index]] = [reordered[index], reordered[index - 1]]
			reordered.forEach((item, i) => { item.orderNumber = i + 1 })
			this.localItems = reordered
			this.persistReorder()
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.7 */
		moveDown(index) {
			if (index >= this.sortedItems.length - 1) return
			const reordered = this.sortedItems.slice()
			;[reordered[index], reordered[index + 1]] = [reordered[index + 1], reordered[index]]
			reordered.forEach((item, i) => { item.orderNumber = i + 1 })
			this.localItems = reordered
			this.persistReorder()
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.1 */
		async persistReorder() {
			const ids = this.sortedItems.map(i => i.id)
			try {
				const response = await fetch(
					OC.generateUrl(`/apps/decidesk/api/agendas/${this.meetingId}/reorder`),
					{
						method: 'PUT',
						headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
						body: JSON.stringify({ ids }),
					},
				)
				if (!response.ok) {
					console.error('Failed to persist agenda reorder:', response.status)
					return
				}
				this.$emit('reordered', ids)
			} catch (e) {
				console.error('Failed to persist agenda reorder:', e)
			}
		},

		// -----------------------------------------------------------------------
		// Spokesperson
		// -----------------------------------------------------------------------

		getSpokesperson(item) {
			return item?.relations?.spokesperson?.[0]?.owner ?? null
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.6 */
		openSpokespersonDialog(item) {
			this.spokespersonDialog = { open: true, item }
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.6 */
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

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.6 */
		async removeSpokesperson(item) {
			try {
				const relations = { ...(item.relations ?? {}) }
				delete relations.spokesperson
				await this.objectStore.saveObject('agenda-item', { ...item, relations })
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

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-5.2 */
		coiCount(item) {
			const notes = item?.notes ?? []
			return notes.filter(n => (n.title ?? '').startsWith('COI:')).length
		},

		// -----------------------------------------------------------------------
		// Recurring items
		// -----------------------------------------------------------------------

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.3 */
		async loadRecurringItems() {
			try {
				const all = await this.objectStore.fetchCollection('agenda-item', { isRecurring: true })
				this.recurringItems = all ?? []
			} catch (e) {
				console.error('Failed to load recurring items:', e)
			}
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.3 */
		toggleRecurring(id) {
			const idx = this.selectedRecurring.indexOf(id)
			if (idx === -1) {
				this.selectedRecurring.push(id)
			} else {
				this.selectedRecurring.splice(idx, 1)
			}
		},

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.3 */
		async addSelectedRecurring() {
			const nextOrder = this.localItems.length + 1
			let order = nextOrder

			for (const srcId of this.selectedRecurring) {
				const src = this.recurringItems.find(r => r.id === srcId)
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
			this.selectedRecurring = []
			this.$emit('item-updated', null)
		},

		// -----------------------------------------------------------------------
		// Proposal inbox (chair)
		// -----------------------------------------------------------------------

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.5 */
		async approveProposal(item) {
			const nextOrder = this.localItems.filter(i => i.status !== 'voorstel').length + 1
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

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.5 */
		async rejectProposal(item) {
			try {
				await this.objectStore.saveObject('agenda-item', { ...item, status: 'afgewezen' })
				this.$emit('item-updated', item.id)
			} catch (e) {
				console.error('Failed to reject proposal:', e)
			}
		},

		// -----------------------------------------------------------------------
		// Submit proposal (all participants)
		// -----------------------------------------------------------------------

		/** @spec openspec/changes/p2-agenda-management/tasks.md#task-2.4 */
		async submitProposal() {
			try {
				await this.objectStore.saveObject('agenda-item', {
					title: this.proposalTitle,
					description: this.proposalDescription,
					status: 'voorstel',
					itemType: 'discussion',
					orderNumber: 999,
					relations: { meeting: [{ id: this.meetingId }] },
				})
				this.proposalTitle = ''
				this.proposalDescription = ''
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
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
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

.agenda-builder__proposal-list,
.agenda-builder__recurring-list,
.agenda-builder__participant-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.agenda-builder__proposal-item,
.agenda-builder__recurring-item,
.agenda-builder__participant-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline) 0;
	border-bottom: 1px solid var(--color-border);
}

.agenda-builder__proposal-item:last-child,
.agenda-builder__recurring-item:last-child,
.agenda-builder__participant-item:last-child {
	border-bottom: none;
}

.agenda-builder__proposal-actions {
	display: flex;
	gap: var(--default-grid-baseline);
}
</style>
