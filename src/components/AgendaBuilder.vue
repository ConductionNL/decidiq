<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/p2-agenda-management/tasks.md#task-2
-->
<template>
	<div class="agenda-builder">
		<header class="agenda-builder__header">
			<h3>{{ t('decidesk', 'Agenda') }}</h3>
			<span class="agenda-builder__duration">
				{{ t('decidesk', 'Total duration') }}: {{ totalDuration }} min
			</span>
		</header>

		<!-- Hamerstukken section -->
		<div v-if="hamerstukken.length > 0" class="agenda-builder__hamerstukken">
			<h4>{{ t('decidesk', 'Consent items') }}</h4>
			<ul class="agenda-builder__list" role="list">
				<li v-for="item in hamerstukken"
					:key="item.id || item.uuid"
					class="agenda-builder__item agenda-builder__item--hamerstuk">
					<span class="agenda-builder__order">{{ item.orderNumber }}</span>
					<span class="agenda-builder__title">{{ item.title }}</span>
					<span class="agenda-builder__badge agenda-builder__badge--hamerstuk">
						{{ t('decidesk', 'Consent item') }}
					</span>
					<span v-if="item.status === 'afgerond'" class="agenda-builder__badge agenda-builder__badge--completed">
						{{ t('decidesk', 'Adopted') }}
					</span>
					<span class="agenda-builder__duration-value">
						{{ item.estimatedDuration ? item.estimatedDuration + ' min' : '—' }}
					</span>
					<span v-if="getCoiCount(item) > 0" class="agenda-builder__badge agenda-builder__badge--coi">
						COI ({{ getCoiCount(item) }})
					</span>
					<button v-if="isChair && item.status !== 'afgerond'"
						class="agenda-builder__action"
						:aria-label="t('decidesk', 'Remove from consent items')"
						@click="removeFromHamerstukken(item)">
						{{ t('decidesk', 'Remove from consent items') }}
					</button>
				</li>
			</ul>
			<button v-if="isChair && hasUnprocessedHamerstukken"
				class="agenda-builder__publish-btn"
				@click="confirmProcessHamerstukken">
				{{ t('decidesk', 'Adopt consent items') }}
			</button>
		</div>

		<!-- Proposal inbox for chair -->
		<div v-if="isChair && proposals.length > 0" class="agenda-builder__proposals">
			<h4>{{ t('decidesk', 'Proposed agenda items') }}</h4>
			<ul class="agenda-builder__list" role="list">
				<li v-for="item in proposals"
					:key="item.id || item.uuid"
					class="agenda-builder__item agenda-builder__item--proposal">
					<span class="agenda-builder__title">{{ item.title }}</span>
					<CnStatusBadge
						:label="t('decidesk', 'Proposal')"
						variant="info"
						size="small" />
					<div class="agenda-builder__proposal-actions">
						<button class="agenda-builder__action agenda-builder__action--approve"
							:aria-label="t('decidesk', 'Approve')"
							@click="approveProposal(item)">
							{{ t('decidesk', 'Approve') }}
						</button>
						<button class="agenda-builder__action agenda-builder__action--reject"
							:aria-label="t('decidesk', 'Reject')"
							@click="rejectProposal(item)">
							{{ t('decidesk', 'Reject') }}
						</button>
					</div>
				</li>
			</ul>
		</div>

		<!-- Main agenda list with drag-drop -->
		<ul ref="agendaList"
			class="agenda-builder__list agenda-builder__list--main"
			role="list"
			:aria-label="t('decidesk', 'Agenda items')">
			<li v-for="(item, index) in regularItems"
				:key="item.id || item.uuid"
				class="agenda-builder__item"
				:class="{
					'agenda-builder__item--active': activeItemId === (item.id || item.uuid),
					'agenda-builder__item--dragging': dragIndex === index,
				}"
				:draggable="isChair ? 'true' : 'false'"
				:tabindex="0"
				:aria-label="item.title"
				role="listitem"
				@dragstart="onDragStart($event, index)"
				@dragover.prevent="onDragOver($event, index)"
				@drop="onDrop($event, index)"
				@dragend="onDragEnd"
				@keydown="onKeyDown($event, index)">
				<span class="agenda-builder__order">{{ item.orderNumber }}</span>
				<span class="agenda-builder__title">{{ item.title }}</span>
				<CnStatusBadge
					:label="typeLabel(item.itemType)"
					:variant="itemTypeVariant(item.itemType)"
					size="small" />
				<span class="agenda-builder__duration-value">
					{{ item.estimatedDuration ? item.estimatedDuration + ' min' : '—' }}
				</span>
				<span v-if="getSpokesperson(item)" class="agenda-builder__spokesperson">
					{{ getSpokesperson(item) }}
				</span>
				<span v-if="getAttachmentCount(item) > 0" class="agenda-builder__attachments">
					{{ getAttachmentCount(item) }} {{ t('decidesk', 'attachments') }}
				</span>
				<span v-if="getCoiCount(item) > 0" class="agenda-builder__badge agenda-builder__badge--coi">
					COI ({{ getCoiCount(item) }})
				</span>
				<div v-if="isChair" class="agenda-builder__item-actions">
					<button class="agenda-builder__action"
						:aria-label="t('decidesk', 'Assign spokesperson')"
						@click="$emit('assign-spokesperson', item)">
						{{ t('decidesk', 'Assign spokesperson') }}
					</button>
				</div>
			</li>
		</ul>

		<!-- Actions -->
		<div v-if="isChair" class="agenda-builder__actions">
			<button class="agenda-builder__action-btn"
				@click="$emit('add-item')">
				{{ t('decidesk', 'Add agenda item') }}
			</button>
			<button class="agenda-builder__action-btn"
				@click="$emit('add-recurring')">
				{{ t('decidesk', 'Add recurring items') }}
			</button>
		</div>

		<!-- Propose item (all participants) -->
		<div v-if="canPropose" class="agenda-builder__propose">
			<button class="agenda-builder__action-btn"
				@click="$emit('propose-item')">
				{{ t('decidesk', 'Propose agenda item') }}
			</button>
		</div>

		<!-- Action error display -->
		<p v-if="actionError" class="agenda-builder__error">
			{{ actionError }}
		</p>

		<!-- Consent items confirmation dialog -->
		<CnFormDialog
			v-if="showHamerstukkenConfirm"
			:dialog-title="pendingHamerstukkenCount + ' ' + t('decidesk', 'agenda items will be adopted as consent items')"
			:fields="[]"
			:confirm-label="t('decidesk', 'Adopt')"
			:cancel-label="t('decidesk', 'Cancel')"
			@confirm="doProcessHamerstukken"
			@close="showHamerstukkenConfirm = false" />
	</div>
</template>

<script>
import { useAgendaStore } from '../store/modules/agenda.js'
import { useObjectStore } from '../store/modules/object.js'
import CnFormDialog from '@conduction/nextcloud-vue/src/components/CnFormDialog/CnFormDialog.vue'
import CnStatusBadge from '@conduction/nextcloud-vue/src/components/CnStatusBadge/CnStatusBadge.vue'

/**
 * Drag-and-drop agenda builder component.
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-2
 */
export default {
	name: 'AgendaBuilder',

	components: {
		CnFormDialog,
		CnStatusBadge,
	},

	props: {
		items: {
			type: Array,
			default: () => [],
		},
		meetingId: {
			type: String,
			required: true,
		},
		isChair: {
			type: Boolean,
			default: false,
		},
		canPropose: {
			type: Boolean,
			default: false,
		},
		activeItemId: {
			type: String,
			default: null,
		},
	},

	emits: [
		'reorder',
		'add-item',
		'add-recurring',
		'propose-item',
		'assign-spokesperson',
		'items-updated',
	],

	data() {
		return {
			dragIndex: null,
			dropIndex: null,
			actionError: '',
			showHamerstukkenConfirm: false,
			pendingHamerstukkenCount: 0,
		}
	},

	computed: {
		sortedItems() {
			return [...this.items].sort((a, b) => (a.orderNumber || 0) - (b.orderNumber || 0))
		},

		hamerstukken() {
			return this.sortedItems.filter(
				(item) => (item.tags || []).includes('hamerstuk'),
			)
		},

		proposals() {
			return this.items.filter((item) => item.status === 'voorstel')
		},

		regularItems() {
			return this.sortedItems.filter(
				(item) => item.status !== 'voorstel'
					&& item.status !== 'afgewezen'
					&& !(item.tags || []).includes('hamerstuk'),
			)
		},

		hasUnprocessedHamerstukken() {
			return this.hamerstukken.some((item) => item.status !== 'afgerond')
		},

		totalDuration() {
			return this.sortedItems.reduce(
				(sum, item) => sum + (item.estimatedDuration || 0), 0,
			)
		},
	},

	methods: {
		typeLabel(itemType) {
			const labels = {
				informational: this.t('decidesk', 'Informational'),
				discussion: this.t('decidesk', 'Discussion'),
				decision: this.t('decidesk', 'Decision'),
			}
			return labels[itemType] || itemType
		},

		itemTypeVariant(itemType) {
			const variants = {
				informational: 'info',
				discussion: 'warning',
				decision: 'primary',
			}
			return variants[itemType] || 'default'
		},

		getSpokesperson(item) {
			const relations = item.relations || []
			const spokesRel = relations.find((r) => r.name === 'spokesperson' || r.type === 'spokesperson')
			return spokesRel ? (spokesRel.displayName || spokesRel.title || '') : ''
		},

		getAttachmentCount(item) {
			return (item.files || []).length
		},

		getCoiCount(item) {
			const notes = item.notes || []
			return notes.filter((n) => (n.title || '').startsWith('COI:')).length
		},

		// Drag-and-drop handlers.
		onDragStart(event, index) {
			if (!this.isChair) return
			this.dragIndex = index
			event.dataTransfer.effectAllowed = 'move'
		},

		onDragOver(event, index) {
			if (!this.isChair) return
			this.dropIndex = index
		},

		onDrop(event, index) {
			if (!this.isChair || this.dragIndex === null) return
			this.reorderAfterDrop(this.dragIndex, index)
			this.dragIndex = null
			this.dropIndex = null
		},

		onDragEnd() {
			this.dragIndex = null
			this.dropIndex = null
		},

		onKeyDown(event, index) {
			if (!this.isChair) return
			if (event.key === 'ArrowUp' && index > 0) {
				event.preventDefault()
				this.reorderAfterDrop(index, index - 1)
			} else if (event.key === 'ArrowDown' && index < this.regularItems.length - 1) {
				event.preventDefault()
				this.reorderAfterDrop(index, index + 1)
			}
		},

		async reorderAfterDrop(fromIndex, toIndex) {
			const items = [...this.regularItems]
			const [moved] = items.splice(fromIndex, 1)
			items.splice(toIndex, 0, moved)
			const ids = items.map((item) => item.id || item.uuid)

			this.actionError = ''
			const agendaStore = useAgendaStore()
			try {
				await agendaStore.reorderItems(this.meetingId, ids)
				this.$emit('reorder', ids)
				this.$emit('items-updated')
			} catch (err) {
				this.actionError = this.t('decidesk', 'Failed to reorder agenda items')
			}
		},

		async confirmProcessHamerstukken() {
			this.pendingHamerstukkenCount = this.hamerstukken.filter((i) => i.status !== 'afgerond').length
			this.showHamerstukkenConfirm = true
		},

		async doProcessHamerstukken() {
			this.showHamerstukkenConfirm = false
			this.actionError = ''
			const agendaStore = useAgendaStore()
			try {
				await agendaStore.processHamerstukken(this.meetingId)
				this.$emit('items-updated')
			} catch (err) {
				this.actionError = this.t('decidesk', 'Failed to process consent items')
			}
		},

		async removeFromHamerstukken(item) {
			const objectStore = useObjectStore()
			const updatedTags = (item.tags || []).filter((t) => t !== 'hamerstuk')
			// Send only the fields being changed to prevent mass-assignment.
			await objectStore.saveObject({ id: item.id || item.uuid, tags: updatedTags })
			this.$emit('items-updated')
		},

		async approveProposal(item) {
			const objectStore = useObjectStore()
			const maxOrder = Math.max(...this.sortedItems.map((i) => i.orderNumber || 0), 0)
			// Send only the fields being changed to prevent mass-assignment.
			await objectStore.saveObject({
				id: item.id || item.uuid,
				status: 'beeldvorming',
				orderNumber: maxOrder + 1,
			})
			this.$emit('items-updated')
		},

		async rejectProposal(item) {
			const objectStore = useObjectStore()
			// Send only the fields being changed to prevent mass-assignment.
			await objectStore.saveObject({ id: item.id || item.uuid, status: 'afgewezen' })
			this.$emit('items-updated')
		},
	},
}
</script>

<style scoped>
.agenda-builder {
	padding: 16px 0;
}

.agenda-builder__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.agenda-builder__header h3 {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.agenda-builder__duration {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.agenda-builder__hamerstukken,
.agenda-builder__proposals {
	margin-bottom: 16px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.agenda-builder__hamerstukken h4,
.agenda-builder__proposals h4 {
	margin: 0 0 8px;
	font-size: 15px;
	font-weight: 600;
}

.agenda-builder__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.agenda-builder__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 4px;
	background: var(--color-main-background);
	cursor: default;
	flex-wrap: wrap;
}

.agenda-builder__item[draggable='true'] {
	cursor: grab;
}

.agenda-builder__item--dragging {
	opacity: 0.5;
}

.agenda-builder__item--active {
	border-color: var(--color-primary);
	background: var(--color-primary-element-light);
}

.agenda-builder__order {
	min-width: 28px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.agenda-builder__title {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.agenda-builder__badge {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	white-space: nowrap;
}

.agenda-builder__badge--informational {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.agenda-builder__badge--discussion {
	background: var(--color-primary-element-light);
	color: var(--color-primary-text);
}

.agenda-builder__badge--decision {
	background: var(--color-warning-element-light, var(--color-warning));
	color: var(--color-warning-text, var(--color-main-text));
}

.agenda-builder__badge--hamerstuk {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.agenda-builder__badge--completed {
	background: var(--color-success-element-light, var(--color-success));
	color: var(--color-success-text, var(--color-main-text));
}

.agenda-builder__badge--proposal {
	background: var(--color-primary-element-light);
	color: var(--color-primary-text);
}

.agenda-builder__badge--coi {
	background: var(--color-error-element-light, var(--color-error));
	color: var(--color-error-text, var(--color-main-text));
}

.agenda-builder__duration-value {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	white-space: nowrap;
}

.agenda-builder__spokesperson {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.agenda-builder__attachments {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.agenda-builder__item-actions,
.agenda-builder__proposal-actions {
	display: flex;
	gap: 4px;
}

.agenda-builder__action {
	padding: 4px 8px;
	border: none;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 12px;
}

.agenda-builder__action:hover {
	background: var(--color-background-hover);
}

.agenda-builder__action--approve {
	background: var(--color-success-element-light, var(--color-success));
}

.agenda-builder__action--reject {
	background: var(--color-error-element-light, var(--color-error));
}

.agenda-builder__actions,
.agenda-builder__propose {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}

.agenda-builder__action-btn,
.agenda-builder__publish-btn {
	padding: 8px 16px;
	border: none;
	border-radius: var(--border-radius);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	cursor: pointer;
	font-size: 14px;
}

.agenda-builder__action-btn:hover,
.agenda-builder__publish-btn:hover {
	background: var(--color-primary-element-hover);
}

.agenda-builder__error {
	color: var(--color-error);
	margin: 8px 0 0;
}
</style>
