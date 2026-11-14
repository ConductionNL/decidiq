<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Long-term agenda board: the items of one governance body in period columns,
 in chronological order by each period's last day (design D2), plus empty
 quarters after the last. Dropping a card on another column opens the
 reschedule dialog with that period filled in; Cancel leaves the card where
 it was and writes nothing. Every card also has a Reschedule button, the same
 flow without dragging (WCAG 2.2 SC 2.5.7). The card only moves once the
 save succeeded.

 @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-005-per-body-board-view-with-drag-to-reschedule
-->
<template>
	<div class="decidiq-planned-board" data-testid="planned-agenda-board">
		<div class="decidiq-planned-board__header">
			<h2>{{ t('decidiq', 'Long-term agenda board') }}</h2>
			<NcSelect
				v-model="body"
				class="decidiq-planned-board__body"
				data-testid="planned-agenda-board-body"
				:inputLabel="t('decidiq', 'Governance body')"
				:options="bodies"
				label="name"
				:clearable="false" />
		</div>
		<p v-if="error" class="decidiq-planned-board__error" role="alert">
			{{ error }}
		</p>
		<p v-if="!body" class="decidiq-planned-board__muted">
			{{ t('decidiq', 'Choose a governance body to see its long-term agenda.') }}
		</p>
		<p v-else-if="loading" class="decidiq-planned-board__muted">
			{{ t('decidiq', 'Loading…') }}
		</p>
		<div v-else class="decidiq-planned-board__columns">
			<section
				v-for="period in columns"
				:key="period"
				class="decidiq-planned-board__column"
				:class="{ 'decidiq-planned-board__column--target': dropTarget === period }"
				data-testid="planned-agenda-board-column"
				:data-period="period"
				:aria-label="t('decidiq', 'Period {period}', { period })"
				@dragover.prevent="dropTarget = period"
				@dragleave="dropTarget = dropTarget === period ? '' : dropTarget"
				@drop.prevent="drop(period)">
				<h3>{{ period }}</h3>
				<ul>
					<li
						v-for="item in groups[period]"
						:key="item.id"
						class="decidiq-planned-board__card"
						:class="{ 'decidiq-planned-board__card--overdue': overdue(item) }"
						data-testid="planned-agenda-board-card"
						:draggable="!settled(item)"
						@dragstart="dragged = item">
						<router-link :to="{ name: 'PlannedAgendaDetail', params: { id: item.id } }">
							{{ item.subject }}
						</router-link>
						<span class="decidiq-planned-board__meta">
							{{ item.expectedType }} · {{ item.lifecycle }}
							<template v-if="shiftCount(item)">
								· {{ t('decidiq', 'Shifts: {count}', { count: shiftCount(item) }) }}
							</template>
						</span>
						<NcButton
							v-if="!settled(item)"
							variant="tertiary"
							data-testid="planned-agenda-board-reschedule"
							@click="startReschedule(item, '')">
							{{ t('decidiq', 'Reschedule…') }}
						</NcButton>
					</li>
				</ul>
			</section>
		</div>

		<PlannedAgendaRescheduleDialog
			v-if="pending"
			:item="pending.item"
			:initialPeriod="pending.toPeriod"
			@close="pending = null"
			@confirm="confirm" />
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { NcButton, NcSelect } from '@nextcloud/vue'
import PlannedAgendaRescheduleDialog from '../../dialogs/PlannedAgendaRescheduleDialog.vue'
import { ensureRelationType } from '../../components/tabs/useRelationStore.js'
import {
	boardColumns,
	buildReschedule,
	groupByPeriod,
	isOverdue,
	isTerminal,
	PLANNED_AGENDA_SCHEMA,
} from '../../utils/plannedAgenda.js'

export default {
	name: 'PlannedAgendaBoard',
	components: { NcButton, NcSelect, PlannedAgendaRescheduleDialog },

	data() {
		return {
			bodies: [],
			body: null,
			items: [],
			loading: false,
			error: '',
			dragged: null,
			dropTarget: '',
			pending: null,
		}
	},

	computed: {
		columns() {
			return boardColumns(this.items)
		},

		groups() {
			return groupByPeriod(this.items, this.columns)
		},
	},

	watch: {
		body() {
			this.loadItems()
		},
	},

	async mounted() {
		try {
			this.bodies = await ensureRelationType('governance-body').fetchCollection('governance-body', {})
			if (this.bodies.length === 1) this.body = this.bodies[0]
		} catch (e) {
			this.error = e?.message || this.t('decidiq', 'The governance bodies could not be loaded.')
		}
	},

	methods: {
		overdue(item) {
			return isOverdue(item, Date.now())
		},

		settled(item) {
			return isTerminal(item)
		},

		shiftCount(item) {
			return Array.isArray(item.shiftHistory) ? item.shiftHistory.length : 0
		},

		/**
		 * Read the selected body's items.
		 *
		 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-005-per-body-board-view-with-drag-to-reschedule
		 */
		async loadItems() {
			this.items = []
			this.error = ''
			if (!this.body?.id) return
			this.loading = true
			try {
				this.items = await ensureRelationType(PLANNED_AGENDA_SCHEMA).fetchCollection(
					PLANNED_AGENDA_SCHEMA,
					{ governanceBody: this.body.id },
				)
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'The long-term agenda could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * A card was dropped on a column: ask for the reason, or ignore a drop
		 * on its own column.
		 *
		 * @param {string} period The column's period.
		 */
		drop(period) {
			const item = this.dragged
			this.dragged = null
			this.dropTarget = ''
			if (!item || isTerminal(item) || item.plannedPeriod === period) return
			this.startReschedule(item, period)
		},

		startReschedule(item, toPeriod) {
			this.pending = { item, toPeriod }
		},

		/**
		 * Save the shift once and move the card to its new column.
		 *
		 * @param {{toPeriod: string, reason: string}} shift The new period and reason.
		 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-004-every-reschedule-records-a-mandatory-reason-and-preserves-full-shift-history
		 */
		async confirm({ toPeriod, reason }) {
			const { item } = this.pending
			this.error = ''
			try {
				const next = buildReschedule(item, {
					toPeriod,
					reason,
					user: getCurrentUser()?.uid || '',
					now: Date.now(),
				})
				const saved = await ensureRelationType(PLANNED_AGENDA_SCHEMA).saveObject(PLANNED_AGENDA_SCHEMA, next)
				this.items = this.items.map((candidate) => (candidate.id === item.id ? (saved || next) : candidate))
				this.pending = null
			} catch (e) {
				this.error = e?.message || this.t('decidiq', 'The item was not saved.')
				this.pending = null
			}
		},
	},
}
</script>

<style scoped>
.decidiq-planned-board {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
}

.decidiq-planned-board__header {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
	margin-block-end: calc(var(--default-grid-baseline, 4px) * 4);
}

.decidiq-planned-board__body {
	min-width: 260px;
}

.decidiq-planned-board__columns {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	overflow-x: auto;
	align-items: flex-start;
}

.decidiq-planned-board__column {
	flex: 0 0 260px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline, 4px) * 2);
	min-height: 120px;
}

.decidiq-planned-board__column--target {
	outline: 2px dashed var(--color-primary-element);
}

.decidiq-planned-board__column ul {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.decidiq-planned-board__card {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	padding: calc(var(--default-grid-baseline, 4px) * 2);
	box-shadow: 0 1px 2px var(--color-box-shadow);
	cursor: grab;
}

.decidiq-planned-board__card--overdue {
	border-left: 3px solid var(--color-warning);
}

.decidiq-planned-board__meta,
.decidiq-planned-board__muted {
	color: var(--color-text-maxcontrast);
}

.decidiq-planned-board__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
