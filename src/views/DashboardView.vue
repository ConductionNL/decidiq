<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 @spec openspec/changes/p1-crud-operations/tasks.md#task-5.1
 @spec openspec/changes/p1-crud-operations/tasks.md#task-5.2
 @spec openspec/changes/p1-crud-operations/tasks.md#task-5.3
 @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-8.1
 @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-8.2
 @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-8.3
-->
<template>
	<CnDashboardPage
		:title="t('decidesk', 'Dashboard')"
		:widgets="dashboardWidgets"
		:layout="dashboardLayout"
		:loading="loading">
		<!-- KPI cards — existing + new p2 cards -->
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

		<!-- Post-meeting KPI cards (p2-minutes-and-decisions) -->
		<template #widget-post-meeting-kpi>
			<CnKpiGrid :columns="3">
				<CnStatsBlock
					:title="t('decidesk', 'Notulen ter goedkeuring')"
					:count="kpi.minutesInReview"
					:count-label="t('decidesk', 'ter beoordeling')"
					:icon="NotebookOutlineIcon"
					variant="warning"
					horizontal />
				<CnStatsBlock
					:title="t('decidesk', 'Gepubliceerde besluiten')"
					:count="kpi.publishedDecisions"
					:count-label="t('decidesk', 'gepubliceerd')"
					:icon="GavelIcon"
					variant="success"
					horizontal />
				<CnStatsBlock
					:title="t('decidesk', 'Open actiepunten')"
					:count="kpi.openActionItems"
					:count-label="t('decidesk', 'open')"
					:icon="CheckboxMarkedOutlineIcon"
					variant="default"
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
import { useObjectStore } from '../store/store.js'

import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import CalendarBlank from 'vue-material-design-icons/CalendarBlank.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import CheckboxMarkedOutlineIcon from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import DomainIcon from 'vue-material-design-icons/Domain.vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'
import NotebookOutlineIcon from 'vue-material-design-icons/NotebookOutline.vue'

/**
 * Dashboard view showing KPI cards and meeting status chart.
 *
 * @spec openspec/changes/p1-crud-operations/tasks.md#task-5.1
 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-8.1
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
			CheckboxMarkedOutlineIcon,
			GavelIcon,
			NotebookOutlineIcon,
			kpi: {
				governanceBodies: 0,
				meetings: 0,
				participants: 0,
				upcomingMeetings: 0,
				// p2-minutes-and-decisions KPIs
				minutesInReview: 0,
				publishedDecisions: 0,
				openActionItems: 0,
			},
			meetings: [],
			dashboardWidgets: [
				{ id: 'kpi-stats', title: '', type: 'custom' },
				{ id: 'post-meeting-kpi', title: this.t('decidesk', 'Vergaderresultaten'), type: 'custom' },
				{ id: 'meeting-chart', title: this.t('decidesk', 'Meeting status distribution'), type: 'custom' },
			],
			dashboardLayout: [
				{ id: 1, widgetId: 'kpi-stats', gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 3, showTitle: false },
				{ id: 2, widgetId: 'post-meeting-kpi', gridX: 0, gridY: 3, gridWidth: 12, gridHeight: 3 },
				{ id: 3, widgetId: 'meeting-chart', gridX: 0, gridY: 6, gridWidth: 12, gridHeight: 5 },
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
	 * Fetch all KPI data in parallel on mount, including p2 post-meeting KPIs.
	 *
	 * @spec openspec/changes/p1-crud-operations/tasks.md#task-5.3
	 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-8.2
	 */
	async created() {
		const objectStore = useObjectStore()

		try {
			const [
				governanceBodyData,
				meetingData,
				participantData,
				minutesData,
				decisionData,
				actionItemData,
			] = await Promise.all([
				objectStore.fetchCollection('governance-body'),
				objectStore.fetchCollection('meeting'),
				objectStore.fetchCollection('participant'),
				objectStore.fetchCollection('minutes'),
				objectStore.fetchCollection('decision'),
				objectStore.fetchCollection('action-item'),
			])

			this.meetings = meetingData || []

			this.kpi.governanceBodies = (governanceBodyData || []).length
			this.kpi.meetings = (meetingData || []).length
			this.kpi.participants = (participantData || []).length
			this.kpi.upcomingMeetings = (meetingData || [])
				.filter((m) => m.lifecycle === 'scheduled').length

			// p2-minutes-and-decisions KPIs
			this.kpi.minutesInReview = (minutesData || [])
				.filter((m) => m.lifecycle === 'review').length
			this.kpi.publishedDecisions = (decisionData || [])
				.filter((d) => d.isPublished === true).length
			this.kpi.openActionItems = (actionItemData || [])
				.filter((a) => a.taskStatus === 'open' || a.taskStatus === 'in-progress').length
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
