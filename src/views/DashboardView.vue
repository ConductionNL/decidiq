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
	<CnDashboardPage
		:title="t('decidesk', 'Dashboard')"
		:widgets="dashboardWidgets"
		:layout="dashboardLayout"
		:loading="loading">
		<!-- KPI cards -->
		<template #widget-kpi-stats>
			<CnKpiGrid :columns="4">
				<CnStatsBlock
					:title="t('decidesk', 'Upcoming meetings')"
					:count="kpi.upcomingMeetings"
					:count-label="t('decidesk', 'scheduled')"
					:icon="CalendarClock"
					variant="primary"
					horizontal />
				<CnStatsBlock
					:title="t('decidesk', 'Pending motions')"
					:count="kpi.pendingMotions"
					:count-label="t('decidesk', 'in progress')"
					:icon="FileDocumentOutline"
					variant="warning"
					horizontal />
				<CnStatsBlock
					:title="t('decidesk', 'Open action items')"
					:count="kpi.openActionItems"
					:count-label="t('decidesk', 'to do')"
					:icon="ClipboardCheckOutline"
					variant="default"
					horizontal />
				<CnStatsBlock
					:title="t('decidesk', 'Recent decisions')"
					:count="kpi.recentDecisions"
					:count-label="t('decidesk', 'last 30 days')"
					:icon="GavelIcon"
					variant="success"
					horizontal />
			</CnKpiGrid>
		</template>

		<!-- Meeting lifecycle distribution chart -->
		<template #widget-meeting-chart>
			<CnChartWidget
				v-if="hasChartData"
				type="donut"
				:series="chartSeries"
				:chart-options="chartOptions" />
			<p v-else class="decidesk-dashboard__empty">
				{{ t('decidesk', 'No meetings found. Create a meeting to see status distribution.') }}
			</p>
		</template>

		<!-- Quick-access navigation tiles -->
		<template #widget-quick-access>
			<div class="decidesk-dashboard__tiles">
				<CnTileWidget
					v-for="tile in tiles"
					:key="tile.route"
					:tile="tile.tileConfig" />
			</div>
		</template>
	</CnDashboardPage>
</template>

<script>
import { CnDashboardPage, CnKpiGrid, CnStatsBlock, CnChartWidget, CnTileWidget } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../store/modules/object.js'

import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'

/**
 * Dashboard view showing KPI cards, meeting status chart, and quick-access tiles.
 *
 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-5.1
 */
export default {
	name: 'DashboardView',
	components: {
		CnDashboardPage,
		CnKpiGrid,
		CnStatsBlock,
		CnChartWidget,
		CnTileWidget,
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
				{
					route: 'MeetingList',
					tileConfig: {
						title: this.t('decidesk', 'Vergaderingen'),
						icon: 'mdi:calendar-blank',
						iconType: 'class',
						linkType: 'url',
						linkValue: '/meetings',
					},
				},
				{
					route: 'MotionList',
					tileConfig: {
						title: this.t('decidesk', 'Moties'),
						icon: 'mdi:file-document-outline',
						iconType: 'class',
						linkType: 'url',
						linkValue: '/motions',
					},
				},
				{
					route: 'DecisionList',
					tileConfig: {
						title: this.t('decidesk', 'Besluiten'),
						icon: 'mdi:gavel',
						iconType: 'class',
						linkType: 'url',
						linkValue: '/decisions',
					},
				},
				{
					route: 'ParticipantList',
					tileConfig: {
						title: this.t('decidesk', 'Deelnemers'),
						icon: 'mdi:account-group-outline',
						iconType: 'class',
						linkType: 'url',
						linkValue: '/participants',
					},
				},
				{
					route: 'GovernanceBodyList',
					tileConfig: {
						title: this.t('decidesk', 'Bestuursorganen'),
						icon: 'mdi:domain',
						iconType: 'class',
						linkType: 'url',
						linkValue: '/governance-bodies',
					},
				},
			],
			dashboardWidgets: [
				{ id: 'kpi-stats', title: '', type: 'custom' },
				{ id: 'meeting-chart', title: this.t('decidesk', 'Meeting status distribution'), type: 'custom' },
				{ id: 'quick-access', title: this.t('decidesk', 'Quick access'), type: 'custom' },
			],
			dashboardLayout: [
				{ id: 1, widgetId: 'kpi-stats', gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 3, showTitle: false },
				{ id: 2, widgetId: 'meeting-chart', gridX: 0, gridY: 3, gridWidth: 6, gridHeight: 5 },
				{ id: 3, widgetId: 'quick-access', gridX: 6, gridY: 3, gridWidth: 6, gridHeight: 5 },
			],
		}
	},

	computed: {
		/**
		 * Chart series for meeting lifecycle distribution donut.
		 *
		 * @spec openspec/changes/p1-dashboard-and-navigation/tasks.md#task-5.4
		 * @return {Array<number>} Counts per lifecycle state.
		 */
		chartSeries() {
			const states = ['draft', 'scheduled', 'opened', 'paused', 'adjourned', 'closed']
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
					this.t('decidesk', 'Paused'),
					this.t('decidesk', 'Adjourned'),
					this.t('decidesk', 'Closed'),
				],
				colors: [
					'var(--color-text-maxcontrast)',
					'var(--color-primary)',
					'var(--color-success)',
					'var(--color-info)',
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

	methods: {},
}
</script>

<style scoped>
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
</style>
