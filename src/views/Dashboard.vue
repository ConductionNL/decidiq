<template>
	<div class="decidesk-dashboard">
		<header class="decidesk-dashboard__header">
			<h2>{{ t('decidesk', 'Dashboard') }}</h2>
			<p class="decidesk-dashboard__lead">
				{{ t('decidesk', 'Overview of your governance activities and key metrics.') }}
			</p>
		</header>

		<CnKpiGrid :columns="4">
			<CnStatsBlock
				:title="t('decidesk', 'Notulen ter goedkeuring')"
				:count="minutesReviewCount"
				:count-label="t('decidesk', 'awaiting review')"
				:icon="NotebookOutline"
				variant="warning"
				horizontal />
			<CnStatsBlock
				:title="t('decidesk', 'Gepubliceerde besluiten')"
				:count="publishedDecisionsCount"
				:count-label="t('decidesk', 'published')"
				:icon="GavelIcon"
				variant="success"
				horizontal />
			<CnStatsBlock
				:title="t('decidesk', 'Open actiepunten')"
				:count="openActionItemsCount"
				:count-label="t('decidesk', 'open or in progress')"
				:icon="CheckboxMarkedOutline"
				variant="primary"
				horizontal />
			<CnStatsBlock
				:title="t('decidesk', 'Overdue')"
				:count="overdueCount"
				:count-label="t('decidesk', 'overdue items')"
				:icon="CalendarClock"
				variant="error"
				horizontal />
		</CnKpiGrid>

		<div class="decidesk-dashboard__columns">
			<CnConfigurationCard :title="t('decidesk', 'Recent activity')">
				<ul class="decidesk-dashboard__placeholder-list">
					<li>{{ t('decidesk', 'Placeholder: user opened a record') }}</li>
					<li>{{ t('decidesk', 'Placeholder: status changed to Review') }}</li>
					<li>{{ t('decidesk', 'Placeholder: comment added') }}</li>
				</ul>
			</CnConfigurationCard>

			<CnConfigurationCard :title="t('decidesk', 'Quick actions')">
				<p class="decidesk-dashboard__hint">
					{{ t('decidesk', 'Wire buttons here to create records, open lists, or deep links. Use the sidebar for Settings and Documentation.') }}
				</p>
			</CnConfigurationCard>
		</div>
	</div>
</template>

<script>
import { CnConfigurationCard, CnKpiGrid, CnStatsBlock } from '@conduction/nextcloud-vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import CheckboxMarkedOutline from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'
import NotebookOutline from 'vue-material-design-icons/NotebookOutline.vue'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'Dashboard',
	components: {
		CnConfigurationCard,
		CnKpiGrid,
		CnStatsBlock,
	},
	data() {
		return {
			NotebookOutline,
			GavelIcon,
			CheckboxMarkedOutline,
			CalendarClock,
			minutesReviewCount: 0,
			publishedDecisionsCount: 0,
			openActionItemsCount: 0,
			overdueCount: 0,
		}
	},
	async created() {
		await this.fetchKpiCounts()
	},
	methods: {
		async fetchKpiCounts() {
			const objectStore = useObjectStore()
			try {
				const [minutesItems, decisionItems, actionItems] = await Promise.all([
					objectStore.fetchObjects('minutes', { lifecycle: 'review' }),
					objectStore.fetchObjects('decision', { isPublished: 'true' }),
					objectStore.fetchObjects('actionItem'),
				])

				this.minutesReviewCount = (minutesItems || []).length
				this.publishedDecisionsCount = (decisionItems || []).length

				const allItems = (actionItems || [])
				this.openActionItemsCount = allItems.filter(
					(i) => i.taskStatus === 'open' || i.taskStatus === 'in-progress',
				).length
				this.overdueCount = allItems.filter(
					(i) => i.taskStatus === 'overdue',
				).length
			} catch (e) {
				console.error('Failed to fetch KPI counts:', e)
			}
		},
	},
}
</script>

<style scoped>
.decidesk-dashboard {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.decidesk-dashboard__header {
	margin-bottom: 20px;
}

.decidesk-dashboard__header h2 {
	margin: 0 0 8px;
	font-size: 22px;
	font-weight: 600;
}

.decidesk-dashboard__lead {
	margin: 0;
	color: var(--color-text-maxcontrast);
	line-height: 1.5;
}

.decidesk-dashboard__columns {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 16px;
}

@media (max-width: 900px) {
	.decidesk-dashboard__columns {
		grid-template-columns: 1fr;
	}
}

.decidesk-dashboard__placeholder-list {
	margin: 0;
	padding-left: 1.2em;
	line-height: 1.6;
}

.decidesk-dashboard__hint {
	margin: 0;
	line-height: 1.5;
	color: var(--color-text-maxcontrast);
}
</style>
