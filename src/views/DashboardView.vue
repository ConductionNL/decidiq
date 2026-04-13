<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-5.1
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-5.2
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-5.3
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-5.4
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-5.5
 @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-5.6
-->
<template>
	<div class="decidesk-dashboard">
		<h1 class="decidesk-dashboard__title">
			{{ t('decidesk', 'Dashboard') }}
		</h1>

		<!-- KPI cards -->
		<CnKpiGrid :columns="4">
			<CnStatsBlock
				:title="t('decidesk', 'Upcoming meetings')"
				:count="loading ? '…' : kpi.upcomingMeetings"
				:count-label="t('decidesk', 'scheduled')"
				:icon="CalendarClock"
				variant="primary"
				horizontal />
			<CnStatsBlock
				:title="t('decidesk', 'Pending motions')"
				:count="loading ? '…' : kpi.pendingMotions"
				:count-label="t('decidesk', 'in progress')"
				:icon="FileDocumentOutline"
				variant="warning"
				horizontal />
			<CnStatsBlock
				:title="t('decidesk', 'Open action items')"
				:count="loading ? '…' : kpi.openActionItems"
				:count-label="t('decidesk', 'to do')"
				:icon="ClipboardCheckOutline"
				variant="default"
				horizontal />
			<CnStatsBlock
				:title="t('decidesk', 'Recent decisions')"
				:count="loading ? '…' : kpi.recentDecisions"
				:count-label="t('decidesk', 'last 30 days')"
				:icon="GavelIcon"
				variant="success"
				horizontal />
		</CnKpiGrid>

		<!-- Chart + Tiles row -->
		<div class="decidesk-dashboard__columns">
			<!-- Meeting lifecycle distribution chart -->
			<CnConfigurationCard :title="t('decidesk', 'Meeting status distribution')">
				<template v-if="loading">
					<div class="decidesk-dashboard__loading">
						<NcLoadingIcon :size="32" />
					</div>
				</template>
				<template v-else-if="hasChartData">
					<CnChartWidget
						type="donut"
						:series="chartSeries"
						:chart-options="chartOptions" />
				</template>
				<template v-else>
					<p class="decidesk-dashboard__empty">
						{{ t('decidesk', 'No meetings found. Create a meeting to see status distribution.') }}
					</p>
				</template>
			</CnConfigurationCard>

			<!-- Quick-access navigation tiles -->
			<CnConfigurationCard :title="t('decidesk', 'Quick access')">
				<div class="decidesk-dashboard__tiles">
					<button
						v-for="tile in tiles"
						:key="tile.route"
						class="decidesk-dashboard__tile"
						:title="tile.label"
						@click="$router.push({ name: tile.route })"
						@keydown.enter="$router.push({ name: tile.route })">
						<component :is="tile.icon" :size="32" />
						<span>{{ tile.label }}</span>
					</button>
				</div>
			</CnConfigurationCard>
		</div>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import { CnConfigurationCard, CnKpiGrid, CnStatsBlock, CnChartWidget } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/modules/object.js'

import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'
import CalendarBlank from 'vue-material-design-icons/CalendarBlank.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import DomainIcon from 'vue-material-design-icons/Domain.vue'

/**
 * Dashboard view showing KPI cards, meeting status chart, and quick-access tiles.
 *
 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-5.1
 */
export default {
	name: 'DashboardView',
	components: {
		NcLoadingIcon,
		CnConfigurationCard,
		CnKpiGrid,
		CnStatsBlock,
		CnChartWidget,
		CalendarClock,
		FileDocumentOutline,
		ClipboardCheckOutline,
		GavelIcon,
		CalendarBlank,
		AccountGroupOutline,
		DomainIcon,
	},

	data() {
		return {
			loading: true,
			CalendarClock,
			FileDocumentOutline,
			ClipboardCheckOutline,
			GavelIcon,
			kpi: {
				upcomingMeetings: 0,
				pendingMotions: 0,
				openActionItems: 0,
				recentDecisions: 0,
			},
			meetings: [],
			tiles: [
				{ route: 'MeetingList', label: this.t('decidesk', 'Vergaderingen'), icon: CalendarBlank },
				{ route: 'MotionList', label: this.t('decidesk', 'Moties'), icon: FileDocumentOutline },
				{ route: 'DecisionList', label: this.t('decidesk', 'Besluiten'), icon: GavelIcon },
				{ route: 'ParticipantList', label: this.t('decidesk', 'Deelnemers'), icon: AccountGroupOutline },
				{ route: 'GovernanceBodyList', label: this.t('decidesk', 'Bestuursorganen'), icon: DomainIcon },
			],
		}
	},

	computed: {
		/**
		 * Chart series for meeting lifecycle distribution donut.
		 *
		 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-5.4
		 * @returns {Array<number>} Counts per lifecycle state.
		 */
		chartSeries() {
			const states = ['draft', 'scheduled', 'opened', 'adjourned', 'closed']
			return states.map((state) => {
				return this.meetings.filter((m) => m.lifecycle === state).length
			})
		},

		chartOptions() {
			return {
				labels: [
					this.t('decidesk', 'Draft'),
					this.t('decidesk', 'Scheduled'),
					this.t('decidesk', 'Opened'),
					this.t('decidesk', 'Adjourned'),
					this.t('decidesk', 'Closed'),
				],
				colors: [
					'var(--color-text-maxcontrast)',
					'var(--color-primary)',
					'var(--color-success)',
					'var(--color-warning)',
					'var(--color-error)',
				],
			}
		},

		hasChartData() {
			return this.chartSeries.some((count) => count > 0)
		},
	},

	/**
	 * Fetch all KPI data in parallel on mount.
	 *
	 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-5.3
	 */
	async created() {
		const objectStore = useObjectStore()

		try {
			const [meetingData, motionData, actionItemData, decisionData] = await Promise.all([
				objectStore.fetchObjects('meeting'),
				objectStore.fetchObjects('motion'),
				objectStore.fetchObjects('actionItem'),
				objectStore.fetchObjects('decision'),
			])

			this.meetings = meetingData || []

			this.kpi.upcomingMeetings = (meetingData || [])
				.filter((m) => m.lifecycle === 'scheduled').length

			this.kpi.pendingMotions = (motionData || [])
				.filter((m) => m.lifecycle === 'submitted' || m.lifecycle === 'debating').length

			this.kpi.openActionItems = (actionItemData || [])
				.filter((a) => a.taskStatus === 'open' || a.taskStatus === 'in-progress').length

			const thirtyDaysAgo = new Date()
			thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30)
			this.kpi.recentDecisions = (decisionData || [])
				.filter((d) => d.outcome === 'adopted' && new Date(d.decisionDate) >= thirtyDaysAgo).length
		} catch (error) {
			console.error('Failed to fetch dashboard data:', error)
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * Truncate a title to 60 characters with ellipsis.
		 *
		 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-5.6
		 * @param {string} title The title to truncate.
		 * @returns {string} Truncated title.
		 */
		truncateTitle(title) {
			if (!title || title.length <= 60) {
				return title || ''
			}
			return title.substring(0, 57) + '…'
		},
	},
}
</script>

<style scoped>
.decidesk-dashboard {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.decidesk-dashboard__title {
	margin: 0 0 20px;
	font-size: 22px;
	font-weight: 600;
}

.decidesk-dashboard__columns {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 16px;
	margin-top: 16px;
}

@media (max-width: 900px) {
	.decidesk-dashboard__columns {
		grid-template-columns: 1fr;
	}
}

.decidesk-dashboard__loading {
	display: flex;
	justify-content: center;
	padding: 24px 0;
}

.decidesk-dashboard__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 24px 0;
}

.decidesk-dashboard__tiles {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
	gap: 12px;
}

.decidesk-dashboard__tile {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 16px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	transition: background-color 0.15s ease;
}

.decidesk-dashboard__tile:hover,
.decidesk-dashboard__tile:focus-visible {
	background: var(--color-background-hover);
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

.decidesk-dashboard__tile span {
	font-size: 13px;
	font-weight: 500;
	text-align: center;
}
</style>
