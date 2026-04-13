<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/p2-agenda-management/tasks.md#task-4
-->
<template>
	<div class="live-meeting">
		<header class="live-meeting__header">
			<h2>{{ meeting.title || t('decidesk', 'Live meeting') }}</h2>
			<span class="live-meeting__status">{{ t('decidesk', 'Live') }}</span>
		</header>

		<!-- Hamerstukken section -->
		<div v-if="hamerstukken.length > 0" class="live-meeting__section">
			<h3>{{ t('decidesk', 'Consent items') }}</h3>
			<ul class="live-meeting__list" role="list">
				<li v-for="item in hamerstukken"
					:key="item.id || item.uuid"
					class="live-meeting__item"
					:class="{ 'live-meeting__item--completed': item.status === 'afgerond' }">
					<span class="live-meeting__order">{{ item.orderNumber }}</span>
					<span class="live-meeting__title">{{ item.title }}</span>
					<span v-if="item.status === 'afgerond'" class="live-meeting__badge live-meeting__badge--success">
						{{ t('decidesk', 'Adopted') }}
					</span>
					<button v-if="isChair && item.status !== 'afgerond'"
						class="live-meeting__action"
						@click="removeFromHamerstukken(item)">
						{{ t('decidesk', 'Remove from consent items') }}
					</button>
				</li>
			</ul>
			<button v-if="isChair && hasUnprocessedHamerstukken"
				class="live-meeting__btn live-meeting__btn--primary"
				@click="confirmProcessHamerstukken">
				{{ t('decidesk', 'Adopt consent items') }}
			</button>
		</div>

		<!-- Main agenda -->
		<div class="live-meeting__section">
			<h3>{{ t('decidesk', 'Agenda') }}</h3>
			<AgendaBuilder
				v-if="isChair"
				:items="regularItems"
				:meeting-id="meetingId"
				:is-chair="true"
				:active-item-id="activeItemId"
				@items-updated="fetchAgendaItems"
				@add-item="showAddDialog = true" />

			<!-- Read-only view for non-chair -->
			<ul v-else class="live-meeting__list" role="list">
				<li v-for="item in regularItems"
					:key="item.id || item.uuid"
					class="live-meeting__item"
					:class="{ 'live-meeting__item--active': activeItemId === (item.id || item.uuid) }">
					<span class="live-meeting__order">{{ item.orderNumber }}</span>
					<span class="live-meeting__title">{{ item.title }}</span>
					<span class="live-meeting__badge" :class="typeBadgeClass(item.itemType)">
						{{ typeLabel(item.itemType) }}
					</span>
					<span v-if="activeItemId === (item.id || item.uuid)" class="live-meeting__badge live-meeting__badge--active">
						{{ t('decidesk', 'Active') }}
					</span>
				</li>
			</ul>
		</div>

		<!-- BOB phase panels for active items -->
		<div v-for="item in bobItems"
			:key="'bob-' + (item.id || item.uuid)"
			class="live-meeting__bob-panel">
			<h4>{{ item.title }} — {{ t('decidesk', 'BOB Phase') }}</h4>
			<div class="live-meeting__bob-stages">
				<div v-for="stage in bobStages"
					:key="stage.value"
					class="live-meeting__bob-stage"
					:class="{ 'live-meeting__bob-stage--active': item.status === stage.value, 'live-meeting__bob-stage--completed': isStageCompleted(item.status, stage.value) }"
					role="listitem"
					:aria-label="stage.label"
					:aria-current="item.status === stage.value ? 'step' : undefined">
					<span class="live-meeting__bob-label">{{ stage.label }}</span>
				</div>
			</div>
			<div v-if="isChair" class="live-meeting__bob-actions">
				<button v-if="item.status !== 'afgerond'"
					class="live-meeting__btn live-meeting__btn--primary"
					@click="advanceBobPhase(item)">
					{{ t('decidesk', 'Next phase') }}
				</button>
				<button class="live-meeting__btn"
					:class="{ 'live-meeting__btn--active': activeItemId === (item.id || item.uuid) }"
					@click="activateItem(item)">
					{{ t('decidesk', 'Activate this item') }}
				</button>
			</div>
		</div>
	</div>
</template>

<script>
import AgendaBuilder from '../components/AgendaBuilder.vue'
import { useAgendaStore } from '../store/modules/agenda.js'
import { useObjectStore } from '../store/modules/object.js'

/**
 * Live meeting agenda view with chair controls and BOB phase tracking.
 *
 * @spec openspec/changes/p2-agenda-management/tasks.md#task-4
 */
export default {
	name: 'LiveMeeting',

	components: {
		AgendaBuilder,
	},

	data() {
		return {
			meeting: {},
			agendaItems: [],
			activeItemId: null,
			showAddDialog: false,
			pollInterval: null,
		}
	},

	computed: {
		meetingId() {
			return this.$route.params.id
		},

		isChair() {
			return true
		},

		sortedItems() {
			return [...this.agendaItems].sort((a, b) => (a.orderNumber || 0) - (b.orderNumber || 0))
		},

		hamerstukken() {
			return this.sortedItems.filter(
				(item) => (item.tags || []).includes('hamerstuk'),
			)
		},

		regularItems() {
			return this.sortedItems.filter(
				(item) => !(item.tags || []).includes('hamerstuk')
					&& item.status !== 'voorstel'
					&& item.status !== 'afgewezen',
			)
		},

		hasUnprocessedHamerstukken() {
			return this.hamerstukken.some((item) => item.status !== 'afgerond')
		},

		bobItems() {
			return this.regularItems.filter(
				(item) => item.itemType === 'discussion' || item.itemType === 'decision',
			)
		},

		bobStages() {
			return [
				{ value: 'beeldvorming', label: this.t('decidesk', 'Image forming') },
				{ value: 'oordeelsvorming', label: this.t('decidesk', 'Opinion forming') },
				{ value: 'besluitvorming', label: this.t('decidesk', 'Decision making') },
			]
		},
	},

	async created() {
		await this.fetchMeeting()
		await this.fetchAgendaItems()
		// Auto-refresh every 30 seconds.
		this.pollInterval = setInterval(() => this.fetchAgendaItems(), 30000)
	},

	beforeDestroy() {
		if (this.pollInterval) {
			clearInterval(this.pollInterval)
		}
	},

	methods: {
		async fetchMeeting() {
			const objectStore = useObjectStore()
			const results = await objectStore.fetchObjects('meeting', { id: this.meetingId })
			if (Array.isArray(results) && results.length > 0) {
				this.meeting = results[0]
			} else if (results && !Array.isArray(results)) {
				this.meeting = results
			}
		},

		async fetchAgendaItems() {
			const objectStore = useObjectStore()
			const results = await objectStore.fetchObjects('agendaItem', { meeting: this.meetingId })
			this.agendaItems = Array.isArray(results) ? results : []
		},

		typeLabel(itemType) {
			const labels = {
				informational: this.t('decidesk', 'Informational'),
				discussion: this.t('decidesk', 'Discussion'),
				decision: this.t('decidesk', 'Decision'),
			}
			return labels[itemType] || itemType
		},

		typeBadgeClass(itemType) {
			return {
				'live-meeting__badge--informational': itemType === 'informational',
				'live-meeting__badge--discussion': itemType === 'discussion',
				'live-meeting__badge--decision': itemType === 'decision',
			}
		},

		isStageCompleted(currentStatus, stageValue) {
			const order = ['beeldvorming', 'oordeelsvorming', 'besluitvorming', 'afgerond']
			return order.indexOf(currentStatus) > order.indexOf(stageValue)
		},

		activateItem(item) {
			const id = item.id || item.uuid
			this.activeItemId = this.activeItemId === id ? null : id
			const agendaStore = useAgendaStore()
			agendaStore.setActiveItem(this.activeItemId)
		},

		async advanceBobPhase(item) {
			const agendaStore = useAgendaStore()
			await agendaStore.advanceBobPhase(item.id || item.uuid)
			await this.fetchAgendaItems()
		},

		async confirmProcessHamerstukken() {
			const count = this.hamerstukken.filter((i) => i.status !== 'afgerond').length
			const confirmed = window.confirm(
				count + ' ' + this.t('decidesk', 'agenda items will be adopted as consent items'),
			)
			if (!confirmed) return

			const agendaStore = useAgendaStore()
			await agendaStore.processHamerstukken(this.meetingId)
			await this.fetchAgendaItems()
		},

		async removeFromHamerstukken(item) {
			const updatedTags = (item.tags || []).filter((t) => t !== 'hamerstuk')
			const objectStore = useObjectStore()
			const url = new URL(objectStore.baseUrl, window.location.origin)
			await fetch(url.toString(), {
				method: 'PUT',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: OC.requestToken,
				},
				body: JSON.stringify({ ...item, tags: updatedTags }),
			})
			await this.fetchAgendaItems()
		},
	},
}
</script>

<style scoped>
.live-meeting {
	padding: 16px;
	max-width: 1200px;
}

.live-meeting__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.live-meeting__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.live-meeting__status {
	padding: 4px 12px;
	border-radius: var(--border-radius-pill);
	background: var(--color-error);
	color: var(--color-primary-element-text);
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
}

.live-meeting__section {
	margin-bottom: 20px;
}

.live-meeting__section h3 {
	margin: 0 0 8px;
	font-size: 16px;
	font-weight: 600;
}

.live-meeting__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.live-meeting__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 4px;
	background: var(--color-main-background);
}

.live-meeting__item--active {
	border-color: var(--color-primary);
	background: var(--color-primary-element-light);
}

.live-meeting__item--completed {
	opacity: 0.7;
}

.live-meeting__order {
	min-width: 28px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.live-meeting__title {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.live-meeting__badge {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	white-space: nowrap;
}

.live-meeting__badge--informational {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.live-meeting__badge--discussion {
	background: var(--color-primary-element-light);
	color: var(--color-primary-text);
}

.live-meeting__badge--decision {
	background: var(--color-warning-element-light, var(--color-warning));
	color: var(--color-warning-text, var(--color-main-text));
}

.live-meeting__badge--success {
	background: var(--color-success-element-light, var(--color-success));
	color: var(--color-success-text, var(--color-main-text));
}

.live-meeting__badge--active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.live-meeting__action {
	padding: 4px 8px;
	border: none;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 12px;
}

.live-meeting__action:hover {
	background: var(--color-background-hover);
}

.live-meeting__bob-panel {
	margin-bottom: 16px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.live-meeting__bob-panel h4 {
	margin: 0 0 8px;
	font-size: 15px;
	font-weight: 600;
}

.live-meeting__bob-stages {
	display: flex;
	gap: 4px;
	margin-bottom: 8px;
}

.live-meeting__bob-stage {
	flex: 1;
	padding: 8px 12px;
	text-align: center;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.live-meeting__bob-stage--active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: 600;
}

.live-meeting__bob-stage--completed {
	background: var(--color-success-element-light, var(--color-success));
	color: var(--color-success-text, var(--color-main-text));
}

.live-meeting__bob-label {
	display: block;
}

.live-meeting__bob-actions {
	display: flex;
	gap: 8px;
}

.live-meeting__btn {
	padding: 8px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 14px;
}

.live-meeting__btn:hover {
	background: var(--color-background-hover);
}

.live-meeting__btn--primary {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
}

.live-meeting__btn--primary:hover {
	background: var(--color-primary-element-hover);
}

.live-meeting__btn--active {
	border-color: var(--color-primary);
}
</style>
