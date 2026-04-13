<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/p2-agenda-management/tasks.md#task-7
-->
<template>
	<CnDetailPage
		:title="item.title || t('decidesk', 'Agenda item')"
		:description="item.description || ''">
		<template #header-actions>
			<CnStatusBadge
				:label="typeLabel"
				:variant="typeBadgeVariant"
				size="small" />
		</template>

		<!-- Core info card -->
		<CnDetailCard :title="t('decidesk', 'Details')">
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
		</CnDetailCard>

		<!-- BOB phase card (discussion/decision items only) -->
		<CnDetailCard v-if="showBobPhase" :title="t('decidesk', 'BOB Phase')">
			<CnTimelineStages
				:stages="bobTimelineStages"
				:current-stage="item.status"
				:aria-label="t('decidesk', 'BOB Phase stages')" />
		</CnDetailCard>

		<!-- COI card -->
		<CnDetailCard :title="t('decidesk', 'Conflict of interest')">
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
		</CnDetailCard>

		<!-- Linked motions card (decision items only) -->
		<CnDetailCard
			v-if="item.itemType === 'decision'"
			:title="t('decidesk', 'Linked motions')">
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
		</CnDetailCard>

		<!-- Informational items hint for motions -->
		<p v-if="item.itemType === 'informational'" class="agenda-item-detail__hint">
			{{ t('decidesk', 'Only decision-type items support motions') }}
		</p>

		<!-- COI declaration dialog (CnFormDialog — task 5.1) -->
		<CnFormDialog
			v-if="showCoiDialog"
			:dialog-title="t('decidesk', 'Declare conflict of interest')"
			:fields="coiFormFields"
			:confirm-label="t('decidesk', 'Submit')"
			:cancel-label="t('decidesk', 'Cancel')"
			@confirm="onCoiSubmit"
			@close="showCoiDialog = false" />

		<!-- Link motion dialog (CnFormDialog — task 2.4) -->
		<CnFormDialog
			v-if="showMotionDialog"
			:dialog-title="t('decidesk', 'Link motion')"
			:fields="motionFormFields"
			:confirm-label="t('decidesk', 'Link motion')"
			:cancel-label="t('decidesk', 'Cancel')"
			@confirm="onMotionSubmit"
			@close="showMotionDialog = false" />

		<!-- Object sidebar — Files, Notes, Audit Trail (task 7.3) -->
		<CnObjectSidebar
			v-if="item.id || item.uuid"
			:object-id="item.id || item.uuid"
			:object-type="t('decidesk', 'Agenda item')"
			register="decidesk"
			schema="agenda-item"
			:hidden-tabs="['tags', 'tasks']" />
	</CnDetailPage>
</template>

<script>
import CnDetailCard from '@conduction/nextcloud-vue/src/components/CnDetailCard/CnDetailCard.vue'
import CnDetailPage from '@conduction/nextcloud-vue/src/components/CnDetailPage/CnDetailPage.vue'
import CnFormDialog from '@conduction/nextcloud-vue/src/components/CnFormDialog/CnFormDialog.vue'
import CnObjectSidebar from '@conduction/nextcloud-vue/src/components/CnObjectSidebar/CnObjectSidebar.vue'
import CnStatusBadge from '@conduction/nextcloud-vue/src/components/CnStatusBadge/CnStatusBadge.vue'
import CnTimelineStages from '@conduction/nextcloud-vue/src/components/CnTimelineStages/CnTimelineStages.vue'
import { useObjectStore } from '../store/modules/object.js'

/**
 * Extended agenda item detail view with BOB phases, COI, and motion linking.
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-7
 */
export default {
	name: 'AgendaItemDetail',

	components: {
		CnDetailCard,
		CnDetailPage,
		CnFormDialog,
		CnObjectSidebar,
		CnStatusBadge,
		CnTimelineStages,
	},

	data() {
		return {
			item: {},
			availableMotions: [],
			showCoiDialog: false,
			showMotionDialog: false,
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

		typeBadgeVariant() {
			const variants = {
				informational: 'info',
				discussion: 'warning',
				decision: 'primary',
			}
			return variants[this.item.itemType] || 'default'
		},

		/** Stages array in the format CnTimelineStages expects. */
		bobTimelineStages() {
			return [
				{ id: 'beeldvorming', label: this.t('decidesk', 'Image forming') },
				{ id: 'oordeelsvorming', label: this.t('decidesk', 'Opinion forming') },
				{ id: 'besluitvorming', label: this.t('decidesk', 'Decision making') },
			]
		},

		/** Fields definition for the COI CnFormDialog. */
		coiFormFields() {
			return [
				{
					key: 'reason',
					label: this.t('decidesk', 'Reason for recusal'),
					widget: 'textarea',
					required: true,
					description: this.t('decidesk', 'Provide a reason for the conflict of interest'),
				},
			]
		},

		/** Fields definition for the link-motion CnFormDialog. */
		motionFormFields() {
			return [
				{
					key: 'motionId',
					label: this.t('decidesk', 'Motion'),
					widget: 'select',
					required: true,
					// CnFormDialog uses `enum` for static option values and `enumLabels` for display names.
					enum: this.availableMotions.map((m) => m.id || m.uuid),
					enumLabels: Object.fromEntries(
						this.availableMotions.map((m) => [m.id || m.uuid, m.title]),
					),
				},
			]
		},

		showBobPhase() {
			return this.item.itemType === 'discussion' || this.item.itemType === 'decision'
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

	watch: {
		showMotionDialog(val) {
			if (val) {
				this.fetchAvailableMotions()
			}
		},
	},

	async created() {
		await this.fetchItem()
	},

	methods: {
		async fetchAvailableMotions() {
			if (!this.meetingId) return
			const objectStore = useObjectStore()
			const results = await objectStore.fetchCollection('motion', { meeting: this.meetingId })
			this.availableMotions = Array.isArray(results) ? results : []
		},

		async fetchItem() {
			const objectStore = useObjectStore()
			const results = await objectStore.fetchCollection('agendaItem', { id: this.itemId })
			if (Array.isArray(results) && results.length > 0) {
				this.item = results[0]
			} else if (results && !Array.isArray(results)) {
				this.item = results
			}
		},

		async onCoiSubmit(formData) {
			const displayName = OC.getCurrentUser().displayName || OC.getCurrentUser().uid || ''
			const note = {
				title: 'COI: ' + displayName,
				body: (formData.reason || '').trim(),
			}

			// Add note via the item's notes array and save only the notes field.
			const objectStore = useObjectStore()
			await objectStore.saveObject('agendaItem', {
				id: this.item.id || this.item.uuid,
				notes: [...(this.item.notes || []), note],
			})

			this.showCoiDialog = false
			await this.fetchItem()
		},

		async onMotionSubmit(formData) {
			const motionId = formData.motionId
			const motion = this.availableMotions.find((m) => (m.id || m.uuid) === motionId)
			if (!motion) return

			const relations = [...(this.item.relations || []), {
				schema: 'motion',
				type: 'motion',
				id: motion.id || motion.uuid,
				title: motion.title,
			}]

			// Save only the relations field to prevent mass-assignment.
			const objectStore = useObjectStore()
			await objectStore.saveObject('agendaItem', {
				id: this.item.id || this.item.uuid,
				relations,
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
