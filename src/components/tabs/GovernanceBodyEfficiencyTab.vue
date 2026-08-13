<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: meeting-efficiency analytics for a Governance Body
 (meeting-efficiency / analytics dashboard).

 Everything is computed client-side from OpenRegister objects via the shared
 object store: meeting duration vs scheduled (with average + overrun
 highlight), agenda completion rate, speaking-time distribution from
 EngagementRecords, cost trend over time, per-item cost breakdown (most
 expensive flagged), and allocated-vs-actual accuracy by item type with
 recommendations. The pure aggregate math lives in src/utils/meetingAnalytics.js
 (vitest-covered). Charts are dependency-free CSS bars.
-->
<template>
	<div
		class="decidesk-tab decidesk-tab--efficiency"
		data-testid="body-efficiency-tab">
		<div class="decidesk-tab__header">
			<h3 class="decidesk-tab__title">
				{{ t('decidesk', 'Efficiency') }}
			</h3>
		</div>

		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidesk', 'Could not load analytics')">
			{{ error }}
		</CnNoteCard>

		<div v-if="loading" class="decidesk-tab__loading">
			{{ t('decidesk', 'Loading analytics…') }}
		</div>

		<template v-else-if="!hasData">
			<p class="decidesk-tab__empty" data-testid="body-efficiency-empty">
				{{ t('decidesk', 'No meetings recorded for this body yet.') }}
			</p>
		</template>

		<template v-else>
			<!-- Duration trend -->
			<section
				class="efficiency-section"
				data-testid="body-efficiency-duration">
				<h4>{{ t('decidesk', 'Meeting duration') }}</h4>
				<p class="efficiency-section__summary">
					{{
						t('decidesk', 'Average actual duration: {minutes} min', {
							minutes: duration.averageActualMinutes,
						})
					}}
					<span
						v-if="duration.overrunCount > 0"
						class="efficiency-section__flag">
						{{
							t(
								'decidesk',
								'{n} meeting(s) exceeded the scheduled time',
								{ n: duration.overrunCount },
							)
						}}
					</span>
				</p>
				<ul class="efficiency-bars" role="list">
					<li
						v-for="p in duration.points"
						:key="p.id"
						class="efficiency-bars__row"
						role="listitem">
						<span class="efficiency-bars__label">{{
							p.title || t('decidesk', 'Meeting')
						}}</span>
						<span class="efficiency-bars__track">
							<span
								class="efficiency-bars__fill"
								:class="{ 'efficiency-bars__fill--over': p.overrun }"
								:style="{
									width: barWidth(
										p.actualMinutes,
										maxDurationMinutes,
									),
								}" />
						</span>
						<span class="efficiency-bars__value">{{
							formatMinutes(p.actualMinutes)
						}}</span>
					</li>
				</ul>
			</section>

			<!-- Agenda completion -->
			<section
				class="efficiency-section"
				data-testid="body-efficiency-completion">
				<h4>{{ t('decidesk', 'Agenda completion') }}</h4>
				<p class="efficiency-section__summary">
					{{
						t(
							'decidesk',
							'{completed} of {total} agenda items completed ({percent}%)',
							{
								completed: completion.completed,
								total: completion.total,
								percent: Math.round(completion.rate * 100),
							},
						)
					}}
				</p>
			</section>

			<!-- Speaking-time distribution -->
			<section
				v-if="speaking.rows.length"
				class="efficiency-section"
				data-testid="body-efficiency-speaking">
				<h4>{{ t('decidesk', 'Speaking-time distribution') }}</h4>
				<ul class="efficiency-bars" role="list">
					<li
						v-for="row in speaking.rows"
						:key="row.participantId"
						class="efficiency-bars__row"
						role="listitem">
						<span class="efficiency-bars__label">{{
							row.displayName
						}}</span>
						<span class="efficiency-bars__track">
							<span
								class="efficiency-bars__fill"
								:style="{ width: percentWidth(row.share) }" />
						</span>
						<span class="efficiency-bars__value"
							>{{ Math.round(row.share * 100) }}%</span
						>
					</li>
				</ul>
			</section>

			<!-- Cost trend -->
			<section
				v-if="cost.points.length"
				class="efficiency-section"
				data-testid="body-efficiency-cost">
				<h4>{{ t('decidesk', 'Cost trend') }}</h4>
				<p class="efficiency-section__summary">
					{{
						t(
							'decidesk',
							'Total: {total} · Average per meeting: {average}',
							{
								total: formatEur(cost.total),
								average: formatEur(cost.average),
							},
						)
					}}
				</p>
				<ul class="efficiency-bars" role="list">
					<li
						v-for="p in cost.points"
						:key="p.id"
						class="efficiency-bars__row"
						role="listitem">
						<span class="efficiency-bars__label">{{
							p.title || t('decidesk', 'Meeting')
						}}</span>
						<span class="efficiency-bars__track">
							<span
								class="efficiency-bars__fill"
								:style="{ width: barWidth(p.cost, maxCost) }" />
						</span>
						<span class="efficiency-bars__value">{{
							formatEur(p.cost)
						}}</span>
					</li>
				</ul>
			</section>

			<!-- Per-agenda-item cost breakdown (most recent meeting with data) -->
			<section
				v-if="itemCostBreakdown.length"
				class="efficiency-section"
				data-testid="body-efficiency-item-cost">
				<h4>{{ t('decidesk', 'Cost per agenda item') }}</h4>
				<p class="efficiency-section__summary">
					{{
						t('decidesk', 'Latest meeting: {title}', {
							title: latestCostMeetingTitle,
						})
					}}
				</p>
				<ul class="efficiency-bars" role="list">
					<li
						v-for="row in itemCostBreakdown"
						:key="row.id"
						class="efficiency-bars__row"
						role="listitem">
						<span
							class="efficiency-bars__label"
							:class="{
								'efficiency-bars__label--flag': row.mostExpensive,
							}">
							{{ row.title || t('decidesk', 'Item') }}
						</span>
						<span class="efficiency-bars__track">
							<span
								class="efficiency-bars__fill"
								:class="{
									'efficiency-bars__fill--over': row.mostExpensive,
								}"
								:style="{
									width: barWidth(row.cost, maxItemCost),
								}" />
						</span>
						<span class="efficiency-bars__value">{{
							formatEur(row.cost)
						}}</span>
					</li>
				</ul>
			</section>

			<!-- Time allocation accuracy + recommendations -->
			<section
				v-if="accuracy.length"
				class="efficiency-section"
				data-testid="body-efficiency-accuracy">
				<h4>{{ t('decidesk', 'Time allocation accuracy') }}</h4>
				<ul class="efficiency-accuracy" role="list">
					<li
						v-for="row in accuracy"
						:key="row.itemType"
						class="efficiency-accuracy__row"
						role="listitem">
						<strong>{{ row.itemType }}</strong
						>:
						{{
							t(
								'decidesk',
								'avg {actual} min actual vs {estimated} min allocated',
								{
									actual: row.avgActual,
									estimated: row.avgEstimated,
								},
							)
						}}
						<em
							v-if="row.recommendation"
							class="efficiency-accuracy__rec"
							>{{ row.recommendation }}</em
						>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { ensureRelationType } from './useRelationStore.js'
import {
	meetingDurationStats,
	agendaCompletionRate,
	speakingDistribution,
	costTrend,
	agendaItemCostBreakdown,
	timeAllocationAccuracy,
} from '../../utils/meetingAnalytics.js'
import { formatEur } from '../../utils/meetingCost.js'

/**
 * @spec openspec/specs/meeting-efficiency/spec.md
 */
export default {
	name: 'GovernanceBodyEfficiencyTab',

	components: { CnNoteCard },

	props: {
		objectId: { type: [String, Number], default: '' },
		objectType: { type: String, default: '' },
		register: { type: String, default: '' },
		schema: { type: String, default: '' },
	},

	data() {
		return {
			loading: false,
			error: '',
			meetings: [],
			agendaItems: [],
			participants: [],
			engagementRecords: [],
			hourlyRate: 0,
		}
	},

	computed: {
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		hasData() {
			return this.meetings.length > 0
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		nameMap() {
			const map = {}
			for (const p of this.participants) {
				map[p.id] = p.displayName || p.name || p.id
			}
			return map
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		duration() {
			return meetingDurationStats(this.meetings)
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		completion() {
			return agendaCompletionRate(this.agendaItems)
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		speaking() {
			return speakingDistribution(this.engagementRecords, this.nameMap)
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		cost() {
			return costTrend(this.meetings)
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		accuracy() {
			return timeAllocationAccuracy(this.agendaItems)
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		maxDurationMinutes() {
			return this.duration.points.reduce(
				(m, p) => Math.max(m, p.actualMinutes || 0),
				0,
			)
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		maxCost() {
			return this.cost.points.reduce((m, p) => Math.max(m, p.cost || 0), 0)
		},
		/**
		 * The most recent meeting that has a recorded cost — the subject of the
		 * per-agenda-item cost breakdown.
		 *
		 * @spec openspec/specs/meeting-efficiency/spec.md
		 */
		latestCostMeeting() {
			const withCost = this.meetings.filter((m) => Number(m.meetingCost) > 0)
			if (!withCost.length) return null
			return withCost.reduce((latest, m) => {
				const d =
					Date.parse(m.closedAt ?? m.openedAt ?? m.scheduledDate ?? 0) || 0
				const ld =
					Date.parse(
						latest.closedAt
							?? latest.openedAt
							?? latest.scheduledDate
							?? 0,
					) || 0
				return d >= ld ? m : latest
			})
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		latestCostMeetingTitle() {
			return this.latestCostMeeting?.title || this.t('decidesk', 'Meeting')
		},
		/**
		 * Per-agenda-item cost breakdown for the latest costed meeting, with the
		 * most expensive item flagged.
		 *
		 * @spec openspec/specs/meeting-efficiency/spec.md
		 */
		itemCostBreakdown() {
			const meeting = this.latestCostMeeting
			if (!meeting) return []
			const items = this.agendaItems.filter(
				(i) =>
					i?.meeting === meeting.id
					|| i?.['@self']?.relations?.meeting === meeting.id,
			)
			const attendeeCount = this.participants.length || 1
			return agendaItemCostBreakdown(items, attendeeCount, this.hourlyRate)
		},
		/** @spec openspec/specs/meeting-efficiency/spec.md */
		maxItemCost() {
			return this.itemCostBreakdown.reduce(
				(m, r) => Math.max(m, r.cost || 0),
				0,
			)
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/meeting-efficiency/spec.md */
			handler() {
				this.refresh()
			},
		},
	},

	methods: {
		/**
		 * Filter a collection to objects linked to this governance body, honouring
		 * both the structured `@self.relations` and the flat-property shapes.
		 *
		 * @param {Array} collection The collection to filter.
		 *
		 * @return {Array} Objects belonging to this body.
		 *
		 * @spec openspec/specs/meeting-efficiency/spec.md
		 */
		forThisBody(collection) {
			return (collection || []).filter(
				(o) =>
					o?.governanceBody === this.objectId
					|| o?.['@self']?.relations?.governanceBody === this.objectId,
			)
		},
		/**
		 * Load meetings, their agenda items + engagement records, and the body's
		 * participants from the shared store, then let the computed aggregates do
		 * the math.
		 *
		 * @spec openspec/specs/meeting-efficiency/spec.md
		 */
		async refresh() {
			if (!this.objectId) return
			this.loading = true
			this.error = ''
			try {
				// The body's own hourlyRate drives the per-item cost breakdown.
				const bodyStore = ensureRelationType('governance-body')
				try {
					const body = await bodyStore.fetchObject(
						'governance-body',
						this.objectId,
					)
					const rate = Number(body?.hourlyRate)
					this.hourlyRate = Number.isFinite(rate) && rate > 0 ? rate : 0
				} catch (bodyError) {
					this.hourlyRate = 0
				}

				const meetingStore = ensureRelationType('meeting')
				const meetings = await meetingStore.fetchCollection('meeting', {
					_limit: 200,
				})
				this.meetings = this.forThisBody(meetings)
				const meetingIds = new Set(this.meetings.map((m) => m.id))

				const itemStore = ensureRelationType('agenda-item')
				const items = await itemStore.fetchCollection('agenda-item', {
					_limit: 500,
				})
				this.agendaItems = (items || []).filter(
					(i) =>
						meetingIds.has(i?.meeting)
						|| meetingIds.has(i?.['@self']?.relations?.meeting),
				)

				const participantStore = ensureRelationType('participant')
				const participants = await participantStore.fetchCollection(
					'participant',
					{ _limit: 500 },
				)
				this.participants = (participants || []).filter(
					(p) =>
						p?.governanceBody === this.objectId
						|| meetingIds.has(p?.meeting)
						|| meetingIds.has(p?.['@self']?.relations?.meeting),
				)

				const engagementStore = ensureRelationType('engagement-record')
				const records = await engagementStore.fetchCollection(
					'engagement-record',
					{ _limit: 1000 },
				)
				this.engagementRecords = (records || []).filter((r) =>
					meetingIds.has(r?.meeting),
				)
			} catch (e) {
				this.error =
					e?.message || this.t('decidesk', 'Failed to load analytics.')
			} finally {
				this.loading = false
			}
		},
		/**
		 * @param value
		 * @param max
		 * @spec openspec/specs/meeting-efficiency/spec.md
		 */
		barWidth(value, max) {
			if (!Number.isFinite(value) || !Number.isFinite(max) || max <= 0)
				return '0%'
			return `${Math.round((value / max) * 100)}%`
		},
		/**
		 * @param share
		 * @spec openspec/specs/meeting-efficiency/spec.md
		 */
		percentWidth(share) {
			return `${Math.round((Number.isFinite(share) ? share : 0) * 100)}%`
		},
		/**
		 * @param minutes
		 * @spec openspec/specs/meeting-efficiency/spec.md
		 */
		formatMinutes(minutes) {
			return Number.isFinite(minutes)
				? this.t('decidesk', '{m} min', { m: minutes })
				: '—'
		},
		/** @spec exclude thin re-export of the pure formatter for template use */
		formatEur,
	},
}
</script>

<style scoped>
.decidesk-tab {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline);
}

.decidesk-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.decidesk-tab__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}

.decidesk-tab__empty,
.decidesk-tab__loading {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.efficiency-section {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.efficiency-section h4 {
	margin: 0;
}

.efficiency-section__summary {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.efficiency-section__flag {
	color: var(--color-error);
	font-weight: 600;
	margin-inline-start: 6px;
}

.efficiency-bars {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.efficiency-bars__row {
	display: grid;
	grid-template-columns: 8rem 1fr 4rem;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.efficiency-bars__label {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.efficiency-bars__label--flag {
	font-weight: 700;
	color: var(--color-error);
}

.efficiency-bars__track {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	height: 12px;
	overflow: hidden;
}

.efficiency-bars__fill {
	display: block;
	height: 100%;
	background: var(--color-primary-element);
}

.efficiency-bars__fill--over {
	background: var(--color-error);
}

.efficiency-bars__value {
	font-variant-numeric: tabular-nums;
	text-align: right;
}

.efficiency-accuracy {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.efficiency-accuracy__rec {
	display: block;
	color: var(--color-text-maxcontrast);
}
</style>
