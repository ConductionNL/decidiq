<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-5.1
 @spec openspec/changes/p1-crud-operations/tasks.md#task-5.2
 @spec openspec/changes/p1-crud-operations/tasks.md#task-5.3
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
					:title="t('decidesk', 'Governance Bodies')"
					:count="kpi.governanceBodies"
					:count-label="t('decidesk', 'total')"
					:icon="DomainIcon"
					variant="primary"
					horizontal />
				<CnStatsBlock
					:title="t('decidesk', 'Meetings')"
					:count="kpi.meetings"
					:count-label="t('decidesk', 'total')"
					:icon="CalendarBlank"
					variant="default"
					horizontal />
				<CnStatsBlock
					:title="t('decidesk', 'Participants')"
					:count="kpi.participants"
					:count-label="t('decidesk', 'total')"
					:icon="AccountGroupOutline"
					variant="success"
					horizontal />
				<CnStatsBlock
					:title="t('decidesk', 'Upcoming meetings')"
					:count="kpi.upcomingMeetings"
					:count-label="t('decidesk', 'scheduled')"
					:icon="CalendarClock"
					variant="warning"
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
	</CnDashboardPage>
</template>

<script>
import { CnDashboardPage, CnKpiGrid, CnStatsBlock, CnChartWidget } from '@conduction/nextcloud-vue'
import { useGovernanceBodyStore, useMeetingStore, useParticipantStore, useAgendaItemStore } from '../store/store.js'

import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import CalendarBlank from 'vue-material-design-icons/CalendarBlank.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import DomainIcon from 'vue-material-design-icons/Domain.vue'

/**
 * Dashboard view showing KPI cards and meeting status chart.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-5.1
 */
export default {
	name: 'DashboardView',
	components: {
		CnDashboardPage,
		CnKpiGrid,
		CnStatsBlock,
		CnChartWidget,
	},

	data() {
		return {
			loading: true,
			DomainIcon,
			CalendarBlank,
			CalendarClock,
			AccountGroupOutline,
			kpi: {
				governanceBodies: 0,
				meetings: 0,
				participants: 0,
				upcomingMeetings: 0,
			},
			meetings: [],
			dashboardWidgets: [
				{ id: 'kpi-stats', title: '', type: 'custom' },
				{ id: 'meeting-chart', title: this.t('decidesk', 'Meeting status distribution'), type: 'custom' },
			],
			dashboardLayout: [
				{ id: 1, widgetId: 'kpi-stats', gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 3, showTitle: false },
				{ id: 2, widgetId: 'meeting-chart', gridX: 0, gridY: 3, gridWidth: 12, gridHeight: 5 },
			],
		}
	},

	computed: {
		/**
		 * Chart series for meeting lifecycle distribution donut.
		 *
		 * @spec openspec/changes/p1-crud-operations/tasks.md#task-5.2
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
	 * @spec openspec/changes/p1-crud-operations/tasks.md#task-5.3
	 */
	async created() {
		const governanceBodyStore = useGovernanceBodyStore()
		const meetingStore = useMeetingStore()
		const participantStore = useParticipantStore()

		try {
			const [governanceBodies, meetingData, participants] = await Promise.all([
				governanceBodyStore.fetchObjects('governanceBody'),
				meetingStore.fetchObjects('meeting'),
				participantStore.fetchObjects('participant'),
			])

			this.meetings = meetingData || []

			this.kpi.governanceBodies = (governanceBodies || []).length
			this.kpi.meetings = (meetingData || []).length
			this.kpi.participants = (participants || []).length
			this.kpi.upcomingMeetings = (meetingData || [])
				.filter((m) => m.lifecycle === 'scheduled').length
		} catch (error) {
			console.error('Failed to fetch dashboard data:', error)
		} finally {
			this.loading = false
		}
	},
}
</script>

<style scoped>
.decidesk-dashboard__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 24px 0;
}
</style>
