<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 GovernanceHealthWidget — live two-series governance-health chart (REQ-004).

 A declarative manifest `type: "chart"` widget was ruled out: the lib's chart
 dataSource resolves a single metric per query and cannot assemble two named
 live series (design.md Decision 5). This is therefore a custom component that
 fetches up to 12 recent meetings carrying both materialized fields
 (quorumPercentage + actionItemCompletionRate) and plots them as two LIVE
 series — never hardcoded values.

 Chart rendering uses the lib's exported `CnChartWidget`, which accepts
 `:series` / `:categories` props directly (verified present in
 @conduction/nextcloud-vue's index — `CnChartWidget`). When fewer than two
 meetings carry the fields, a "Not enough data" placeholder is shown instead
 of an empty/fake chart.
-->
<template>
	<div class="governance-health" data-testid="governance-health">
		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="!hasData"
			:name="t('decidiq', 'Not enough data')"
			data-testid="governance-health-empty">
			<template #icon>
				<ChartLine :size="32" />
			</template>
		</NcEmptyContent>

		<CnChartWidget
			v-else
			type="line"
			:series="chart.series"
			:categories="categoryLabels"
			data-testid="governance-health-chart" />
	</div>
</template>

<script>
import { CnChartWidget } from '@conduction/nextcloud-vue'
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import { getMeetings } from '../../../services/dashboardData.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'
import {
	hasEnoughHealthData,
	healthDataPoints,
	healthSeries,
} from './widgetLogic.js'

export default {
	name: 'GovernanceHealthWidget',

	components: { NcEmptyContent, NcLoadingIcon, CnChartWidget, ChartLine },

	mixins: [dashboardRefreshMixin],

	data() {
		return {
			/** Meetings fetched from OR. */
			meetings: [],
		}
	},

	computed: {
		/**
		 * Usable health data points (both metrics present), ≤12 most recent.
		 *
		 * @return {Array<object>} Data-point meetings.
		 */
		points() {
			return healthDataPoints(this.meetings)
		},

		/**
		 * Whether there are enough points to draw the chart (≥2).
		 *
		 * @return {boolean} True when the chart can render.
		 */
		hasData() {
			return hasEnoughHealthData(this.points)
		},

		/**
		 * The two live series + raw category keys (ApexCharts shape). The
		 * series names are translated; the data is always live.
		 *
		 * @return {{ series: Array<object>, categories: string[] }} Chart data.
		 */
		chart() {
			const { series, categories } = healthSeries(this.points)
			const names = [
				t('decidiq', 'Quorum %'),
				t('decidiq', 'Action item completion %'),
			]
			return {
				series: series.map((s, i) => ({ ...s, name: names[i] || s.name })),
				categories,
			}
		},

		/**
		 * X-axis category labels formatted from the meeting scheduledDates.
		 *
		 * @return {string[]} Formatted date labels.
		 */
		categoryLabels() {
			return this.chart.categories.map((value) => {
				const d = new Date(value)
				return Number.isNaN(d.getTime())
					? String(value || '')
					: d.toLocaleDateString()
			})
		},
	},

	methods: {
		/**
		 * Fetch meetings for the health chart. Called on mount and on refresh.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = null
			try {
				this.meetings = await getMeetings()
			} catch (e) {
				console.error('[decidiq] GovernanceHealthWidget load failed', e)
				this.error = e
				this.meetings = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.governance-health {
	display: flex;
	flex-direction: column;
	min-height: 0;
}
</style>
