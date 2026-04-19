<template>
	<div class="action-item-analytics-widget">
		<h2>{{ t('decidesk', 'Action Item Analytics') }}</h2>

		<!-- KPI Cards -->
		<div class="kpi-cards">
			<div class="kpi-card">
				<div class="kpi-value">
					{{ summary.totalOpen }}
				</div>
				<div class="kpi-label">
					{{ t('decidesk', 'Open Items') }}
				</div>
			</div>
			<div class="kpi-card kpi-warning">
				<div class="kpi-value">
					{{ summary.totalOverdue }}
				</div>
				<div class="kpi-label">
					{{ t('decidesk', 'Overdue') }}
				</div>
			</div>
			<div class="kpi-card kpi-success">
				<div class="kpi-value">
					{{ summary.completedThisMonth }}
				</div>
				<div class="kpi-label">
					{{ t('decidesk', 'Completed This Month') }}
				</div>
			</div>
			<div class="kpi-card">
				<div class="kpi-value">
					{{ summary.avgDaysToClose }}
				</div>
				<div class="kpi-label">
					{{ t('decidesk', 'Avg Days to Close') }}
				</div>
			</div>
		</div>

		<!-- Completion Rates Chart -->
		<div v-if="completionRates.length > 0" class="completion-rates">
			<h3>{{ t('decidesk', 'Meeting Completion Rates') }}</h3>
			<div class="chart-placeholder">
				<p>{{ t('decidesk', 'Chart: Last 6 meetings completion rates') }}</p>
				<ul>
					<li v-for="rate in completionRates" :key="rate.meetingTitle">
						{{ rate.meetingTitle }}: {{ rate.completionRate }}%
					</li>
				</ul>
			</div>
		</div>

		<!-- My Action Items -->
		<div v-if="myItems.overdue.length > 0 || myItems.thisWeek.length > 0 || myItems.later.length > 0" class="my-items">
			<h3>{{ t('decidesk', 'My Action Items') }}</h3>

			<div v-if="myItems.overdue.length > 0" class="items-group">
				<h4 style="color: var(--color-error)">
					{{ t('decidesk', 'Overdue') }}
				</h4>
				<ul>
					<li v-for="item in myItems.overdue" :key="item.id" @click="goToItem(item.id)">
						{{ item.title }}
					</li>
				</ul>
			</div>

			<div v-if="myItems.thisWeek.length > 0" class="items-group">
				<h4 style="color: var(--color-warning)">
					{{ t('decidesk', 'This Week') }}
				</h4>
				<ul>
					<li v-for="item in myItems.thisWeek" :key="item.id" @click="goToItem(item.id)">
						{{ item.title }}
					</li>
				</ul>
			</div>

			<div v-if="myItems.later.length > 0" class="items-group">
				<h4>{{ t('decidesk', 'Later') }}</h4>
				<ul>
					<li v-for="item in myItems.later" :key="item.id" @click="goToItem(item.id)">
						{{ item.title }}
					</li>
				</ul>
			</div>
		</div>

		<div v-if="error" class="error-message">
			{{ error }}
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'

export default {
	name: 'ActionItemAnalyticsWidget',
	data() {
		return {
			summary: {
				totalOpen: 0,
				totalOverdue: 0,
				completedThisMonth: 0,
				avgDaysToClose: 0,
			},
			completionRates: [],
			myItems: {
				overdue: [],
				thisWeek: [],
				later: [],
			},
			error: '',
		}
	},
	created() {
		this.loadAnalytics()
	},
	methods: {
		async loadAnalytics() {
			try {
				// Load summary
				const summaryResponse = await axios.get('/apps/decidesk/api/analytics/action-items')
				Object.assign(this.summary, summaryResponse.data)

				// Load completion rates
				const ratesResponse = await axios.get('/apps/decidesk/api/analytics/action-items/completion-rates')
				this.completionRates = ratesResponse.data.results || []

				// Load my items
				const myItemsResponse = await axios.get('/apps/decidesk/api/analytics/action-items/my-items')
				Object.assign(this.myItems, myItemsResponse.data)
			} catch (err) {
				this.error = t('decidesk', 'Failed to load analytics')
				console.error(err)
			}
		},
		goToItem(itemId) {
			this.$router.push({ name: 'ActionItemDetail', params: { id: itemId } })
		},
	},
	setup() {
		return { t }
	},
}
</script>

<style scoped>
.action-item-analytics-widget {
	padding: 20px;
	background: var(--color-background-secondary);
	border-radius: 8px;
	margin-bottom: 20px;
}

.kpi-cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 15px;
	margin-bottom: 20px;
}

.kpi-card {
	background: white;
	padding: 15px;
	border-radius: 4px;
	text-align: center;
	box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}

.kpi-card.kpi-warning {
	border-left: 4px solid var(--color-warning);
}

.kpi-card.kpi-success {
	border-left: 4px solid var(--color-success);
}

.kpi-value {
	font-size: 28px;
	font-weight: bold;
	color: var(--color-text);
	margin-bottom: 5px;
}

.kpi-label {
	font-size: 12px;
	color: var(--color-text-secondary);
	text-transform: uppercase;
}

.completion-rates,
.my-items {
	margin-bottom: 20px;
}

h3 {
	margin-top: 0;
	margin-bottom: 15px;
	color: var(--color-text);
}

.items-group {
	margin-bottom: 15px;
}

.items-group h4 {
	margin: 0 0 8px 0;
	font-size: 14px;
}

.items-group ul {
	list-style: none;
	padding: 0;
	margin: 0;
}

.items-group li {
	padding: 8px 12px;
	background: white;
	margin-bottom: 4px;
	border-radius: 4px;
	cursor: pointer;
	transition: background-color 0.2s;
}

.items-group li:hover {
	background-color: var(--color-background-hover);
}

.chart-placeholder {
	background: white;
	padding: 15px;
	border-radius: 4px;
}

.error-message {
	padding: 15px;
	background-color: var(--color-error);
	color: white;
	border-radius: 4px;
}
</style>
