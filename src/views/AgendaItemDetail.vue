<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/p2-agenda-management/tasks.md#task-7
-->
<template>
	<div class="agenda-item-detail">
		<header class="agenda-item-detail__header">
			<h2>{{ item.title || t('decidesk', 'Agenda item') }}</h2>
			<span class="agenda-item-detail__badge" :class="typeBadgeClass">
				{{ typeLabel }}
			</span>
		</header>

		<!-- Description -->
		<p v-if="item.description" class="agenda-item-detail__description">
			{{ item.description }}
		</p>

		<!-- Meta info -->
		<div class="agenda-item-detail__meta">
			<div v-if="item.estimatedDuration" class="agenda-item-detail__meta-item">
				<strong>{{ t('decidesk', 'Estimated duration') }}:</strong> {{ item.estimatedDuration }} min
			</div>
			<div v-if="spokesperson" class="agenda-item-detail__meta-item">
				<strong>{{ t('decidesk', 'Spokesperson') }}:</strong> {{ spokesperson }}
			</div>
			<div v-if="item.orderNumber" class="agenda-item-detail__meta-item">
				<strong>{{ t('decidesk', 'Order') }}:</strong> {{ item.orderNumber }}
			</div>
		</div>

		<!-- BOB phase timeline for discussion/decision items -->
		<div v-if="showBobPhase" class="agenda-item-detail__bob">
			<h3>{{ t('decidesk', 'BOB Phase') }}</h3>
			<div class="agenda-item-detail__bob-stages" role="list" :aria-label="t('decidesk', 'BOB Phase stages')">
				<div v-for="stage in bobStages"
					:key="stage.value"
					class="agenda-item-detail__bob-stage"
					:class="{
						'agenda-item-detail__bob-stage--active': item.status === stage.value,
						'agenda-item-detail__bob-stage--completed': isStageCompleted(stage.value),
					}"
					role="listitem"
					:aria-label="stage.label"
					:aria-current="item.status === stage.value ? 'step' : undefined">
					{{ stage.label }}
				</div>
			</div>
		</div>

		<!-- COI section -->
		<div class="agenda-item-detail__coi">
			<h3>{{ t('decidesk', 'Conflict of interest') }}</h3>
			<div v-if="coiNotes.length > 0" class="agenda-item-detail__coi-list">
				<div v-for="(note, idx) in coiNotes" :key="idx" class="agenda-item-detail__coi-item">
					<span class="agenda-item-detail__badge agenda-item-detail__badge--coi">COI</span>
					<span>{{ note.title.replace('COI: ', '') }}</span>
					<span v-if="note.body" class="agenda-item-detail__coi-reason"> — {{ note.body }}</span>
				</div>
			</div>
			<button class="agenda-item-detail__btn"
				@click="showCoiDialog = true">
				{{ t('decidesk', 'Declare conflict of interest') }}
			</button>
		</div>

		<!-- Linked motions (decision items only) -->
		<div v-if="item.itemType === 'decision'" class="agenda-item-detail__motions">
			<h3>{{ t('decidesk', 'Linked motions') }}</h3>
			<ul v-if="linkedMotions.length > 0" class="agenda-item-detail__motion-list">
				<li v-for="motion in linkedMotions" :key="motion.id || motion.uuid">
					<a href="#" @click.prevent="goToMotion(motion)">{{ motion.title }}</a>
				</li>
			</ul>
			<p v-else class="agenda-item-detail__empty">
				{{ t('decidesk', 'No motions linked') }}
			</p>
			<button class="agenda-item-detail__btn"
				@click="showMotionDialog = true">
				{{ t('decidesk', 'Link motion') }}
			</button>
		</div>

		<!-- Informational items tooltip for motions -->
		<p v-if="item.itemType === 'informational'" class="agenda-item-detail__hint">
			{{ t('decidesk', 'Only decision-type items support motions') }}
		</p>

		<!-- COI declaration dialog -->
		<div v-if="showCoiDialog" class="agenda-item-detail__dialog-overlay" @click.self="showCoiDialog = false">
			<div class="agenda-item-detail__dialog" role="dialog" :aria-label="t('decidesk', 'Declare conflict of interest')">
				<h3>{{ t('decidesk', 'Declare conflict of interest') }}</h3>
				<label for="coi-reason" class="agenda-item-detail__label">
					{{ t('decidesk', 'Reason for recusal') }}
				</label>
				<textarea
					id="coi-reason"
					v-model="coiReason"
					class="agenda-item-detail__textarea"
					:placeholder="t('decidesk', 'Provide a reason for the conflict of interest')"
					rows="3"
					required />
				<p v-if="coiError" class="agenda-item-detail__error">{{ coiError }}</p>
				<div class="agenda-item-detail__dialog-actions">
					<button class="agenda-item-detail__btn agenda-item-detail__btn--primary"
						@click="submitCoi">
						{{ t('decidesk', 'Submit') }}
					</button>
					<button class="agenda-item-detail__btn"
						@click="showCoiDialog = false">
						{{ t('decidesk', 'Cancel') }}
					</button>
				</div>
			</div>
		</div>

		<!-- Link motion dialog -->
		<div v-if="showMotionDialog" class="agenda-item-detail__dialog-overlay" @click.self="showMotionDialog = false">
			<div class="agenda-item-detail__dialog" role="dialog" :aria-label="t('decidesk', 'Link motion')">
				<h3>{{ t('decidesk', 'Link motion') }}</h3>
				<ul v-if="availableMotions.length > 0" class="agenda-item-detail__motion-select">
					<li v-for="motion in availableMotions"
						:key="motion.id || motion.uuid"
						class="agenda-item-detail__motion-option"
						@click="linkMotion(motion)">
						{{ motion.title }}
					</li>
				</ul>
				<p v-else class="agenda-item-detail__empty">
					{{ t('decidesk', 'No motions available') }}
				</p>
				<div class="agenda-item-detail__dialog-actions">
					<button class="agenda-item-detail__btn"
						@click="showMotionDialog = false">
						{{ t('decidesk', 'Cancel') }}
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { useObjectStore } from '../store/modules/object.js'

/**
 * Extended agenda item detail view with BOB phases, COI, and motion linking.
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-7
 */
export default {
	name: 'AgendaItemDetail',

	data() {
		return {
			item: {},
			availableMotions: [],
			showCoiDialog: false,
			showMotionDialog: false,
			coiReason: '',
			coiError: '',
		}
	},

	computed: {
		itemId() {
			return this.$route.params.id
		},

		meetingId() {
			// Prefer a direct FK field, then fall back to relations.
			if (this.item.meeting && typeof this.item.meeting === 'string') {
				return this.item.meeting
			}
			const rel = (this.item.relations || []).find((r) => r.schema === 'meeting')
			return rel ? (rel.id || rel.uuid || null) : null
		},

		typeLabel() {
			const labels = {
				informational: this.t('decidesk', 'Informational'),
				discussion: this.t('decidesk', 'Discussion'),
				decision: this.t('decidesk', 'Decision'),
			}
			return labels[this.item.itemType] || this.item.itemType || ''
		},

		typeBadgeClass() {
			return {
				'agenda-item-detail__badge--informational': this.item.itemType === 'informational',
				'agenda-item-detail__badge--discussion': this.item.itemType === 'discussion',
				'agenda-item-detail__badge--decision': this.item.itemType === 'decision',
			}
		},

		showBobPhase() {
			return this.item.itemType === 'discussion' || this.item.itemType === 'decision'
		},

		bobStages() {
			return [
				{ value: 'beeldvorming', label: this.t('decidesk', 'Image forming') },
				{ value: 'oordeelsvorming', label: this.t('decidesk', 'Opinion forming') },
				{ value: 'besluitvorming', label: this.t('decidesk', 'Decision making') },
			]
		},

		coiNotes() {
			return (this.item.notes || []).filter((n) => (n.title || '').startsWith('COI:'))
		},

		linkedMotions() {
			const relations = this.item.relations || []
			return relations.filter(
				(r) => r.schema === 'motion' || r.type === 'motion',
			)
		},

		spokesperson() {
			const relations = this.item.relations || []
			const spokesRel = relations.find((r) => r.name === 'spokesperson' || r.type === 'spokesperson')
			return spokesRel ? (spokesRel.displayName || spokesRel.title || '') : ''
		},
	},

	async created() {
		await this.fetchItem()
	},

	watch: {
		showMotionDialog(val) {
			if (val) {
				this.fetchAvailableMotions()
			}
		},
	},

	methods: {
		async fetchAvailableMotions() {
			if (!this.meetingId) return
			const objectStore = useObjectStore()
			const results = await objectStore.fetchObjects('motion', { meeting: this.meetingId })
			this.availableMotions = Array.isArray(results) ? results : []
		},

		async fetchItem() {
			const objectStore = useObjectStore()
			const results = await objectStore.fetchObjects('agendaItem', { id: this.itemId })
			if (Array.isArray(results) && results.length > 0) {
				this.item = results[0]
			} else if (results && !Array.isArray(results)) {
				this.item = results
			}
		},

		isStageCompleted(stageValue) {
			const order = ['beeldvorming', 'oordeelsvorming', 'besluitvorming', 'afgerond']
			return order.indexOf(this.item.status) > order.indexOf(stageValue)
		},

		async submitCoi() {
			this.coiError = ''
			if (!this.coiReason.trim()) {
				this.coiError = this.t('decidesk', 'Provide a reason for the conflict of interest')
				return
			}

			const displayName = OC.getCurrentUser().displayName || OC.getCurrentUser().uid || ''
			const note = {
				title: 'COI: ' + displayName,
				body: this.coiReason.trim(),
			}

			// Add note via the item's notes array and save.
			const updatedNotes = [...(this.item.notes || []), note]
			const updatedItem = { ...this.item, notes: updatedNotes }

			const objectStore = useObjectStore()
			const url = new URL(objectStore.baseUrl, window.location.origin)
			await fetch(url.toString(), {
				method: 'PUT',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: OC.requestToken,
				},
				body: JSON.stringify(updatedItem),
			})

			this.showCoiDialog = false
			this.coiReason = ''
			await this.fetchItem()
		},

		async linkMotion(motion) {
			const relations = [...(this.item.relations || []), {
				schema: 'motion',
				type: 'motion',
				id: motion.id || motion.uuid,
				title: motion.title,
			}]
			const updatedItem = { ...this.item, relations }

			const objectStore = useObjectStore()
			const url = new URL(objectStore.baseUrl, window.location.origin)
			await fetch(url.toString(), {
				method: 'PUT',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: OC.requestToken,
				},
				body: JSON.stringify(updatedItem),
			})

			this.showMotionDialog = false
			await this.fetchItem()
		},

		goToMotion(motion) {
			// Navigate to motion detail if route exists.
			const id = motion.id || motion.uuid
			if (id) {
				this.$router.push({ path: '/motions/' + id })
			}
		},
	},
}
</script>

<style scoped>
.agenda-item-detail {
	padding: 16px;
	max-width: 900px;
}

.agenda-item-detail__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 12px;
}

.agenda-item-detail__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.agenda-item-detail__description {
	margin: 0 0 16px;
	line-height: 1.6;
}

.agenda-item-detail__meta {
	display: flex;
	gap: 24px;
	margin-bottom: 16px;
	color: var(--color-text-maxcontrast);
}

.agenda-item-detail__meta-item strong {
	color: var(--color-main-text);
}

.agenda-item-detail__badge {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	white-space: nowrap;
}

.agenda-item-detail__badge--informational {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.agenda-item-detail__badge--discussion {
	background: var(--color-primary-element-light);
	color: var(--color-primary-text);
}

.agenda-item-detail__badge--decision {
	background: var(--color-warning-element-light, var(--color-warning));
	color: var(--color-warning-text, var(--color-main-text));
}

.agenda-item-detail__badge--coi {
	background: var(--color-error-element-light, var(--color-error));
	color: var(--color-error-text, var(--color-main-text));
}

.agenda-item-detail__bob {
	margin-bottom: 20px;
}

.agenda-item-detail__bob h3 {
	margin: 0 0 8px;
	font-size: 16px;
	font-weight: 600;
}

.agenda-item-detail__bob-stages {
	display: flex;
	gap: 4px;
}

.agenda-item-detail__bob-stage {
	flex: 1;
	padding: 8px 12px;
	text-align: center;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.agenda-item-detail__bob-stage--active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: 600;
}

.agenda-item-detail__bob-stage--completed {
	background: var(--color-success-element-light, var(--color-success));
	color: var(--color-success-text, var(--color-main-text));
}

.agenda-item-detail__coi,
.agenda-item-detail__motions {
	margin-bottom: 20px;
}

.agenda-item-detail__coi h3,
.agenda-item-detail__motions h3 {
	margin: 0 0 8px;
	font-size: 16px;
	font-weight: 600;
}

.agenda-item-detail__coi-list {
	margin-bottom: 8px;
}

.agenda-item-detail__coi-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}

.agenda-item-detail__coi-reason {
	color: var(--color-text-maxcontrast);
}

.agenda-item-detail__motion-list {
	margin: 0 0 8px;
	padding-left: 1.2em;
}

.agenda-item-detail__motion-list a {
	color: var(--color-primary-element);
}

.agenda-item-detail__hint {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.agenda-item-detail__empty {
	color: var(--color-text-maxcontrast);
}

.agenda-item-detail__btn {
	padding: 8px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 14px;
}

.agenda-item-detail__btn:hover {
	background: var(--color-background-hover);
}

.agenda-item-detail__btn--primary {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
}

.agenda-item-detail__dialog-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10000;
}

.agenda-item-detail__dialog {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	padding: 24px;
	max-width: 480px;
	width: 100%;
}

.agenda-item-detail__dialog h3 {
	margin: 0 0 12px;
}

.agenda-item-detail__label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
}

.agenda-item-detail__textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-family: inherit;
	font-size: 14px;
	resize: vertical;
}

.agenda-item-detail__error {
	color: var(--color-error);
	margin: 4px 0;
}

.agenda-item-detail__dialog-actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
	justify-content: flex-end;
}

.agenda-item-detail__motion-select {
	list-style: none;
	margin: 0;
	padding: 0;
}

.agenda-item-detail__motion-option {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 4px;
	cursor: pointer;
}

.agenda-item-detail__motion-option:hover {
	background: var(--color-background-hover);
}
</style>
