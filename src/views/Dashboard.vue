<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.
@spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-8
-->
<template>
	<div class="decidesk-dashboard">
		<header class="decidesk-dashboard__header">
			<h2>{{ t('decidesk', 'Dashboard') }}</h2>
			<p class="decidesk-dashboard__lead">
				{{ t('decidesk', 'Overzicht van notulen, besluiten en actiepunten.') }}
			</p>
		</header>

		<CnKpiGrid :columns="4">
			<!-- Original KPIs -->
			<CnStatsBlock
				:title="t('decidesk', 'Open items')"
				:count="openActionItemCount"
				:count-label="t('decidesk', 'actiepunten')"
				:icon="FolderOutline"
				variant="primary"
				horizontal />
			<CnStatsBlock
				:title="t('decidesk', 'Verlopen')"
				:count="overdueActionItemCount"
				:count-label="t('decidesk', 'actiepunten')"
				:icon="AlertCircleOutline"
				variant="warning"
				horizontal />
			<CnStatsBlock
				:title="t('decidesk', 'Afgerond')"
				:count="completedActionItemCount"
				:count-label="t('decidesk', 'actiepunten')"
				:icon="CheckCircleOutline"
				variant="success"
				horizontal />
			<CnStatsBlock
				:title="t('decidesk', 'Gepubliceerde besluiten')"
				:count="publishedDecisionCount"
				:count-label="t('decidesk', 'besluiten')"
				:icon="CheckDecagramIcon"
				variant="default"
				horizontal />
		</CnKpiGrid>

		<!-- New KPI row for p2-minutes-and-decisions -->
		<!-- @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-8 -->
		<CnKpiGrid :columns="3" class="decidesk-dashboard__kpi-row">
			<CnStatsBlock
				:title="t('decidesk', 'Notulen ter goedkeuring')"
				:count="minutesInReviewCount"
				:count-label="t('decidesk', 'notulen')"
				:icon="FileDocumentOutlineIcon"
				variant="warning"
				horizontal />
			<CnStatsBlock
				:title="t('decidesk', 'Gepubliceerde besluiten')"
				:count="publishedDecisionCount"
				:count-label="t('decidesk', 'besluiten')"
				:icon="CheckDecagramIcon"
				variant="success"
				horizontal />
			<CnStatsBlock
				:title="t('decidesk', 'Open actiepunten')"
				:count="openActionItemCount"
				:count-label="t('decidesk', 'actiepunten')"
				:icon="CheckboxMarkedOutlineIcon"
				variant="primary"
				horizontal />
		</CnKpiGrid>

		<div class="decidesk-dashboard__columns">
			<CnConfigurationCard :title="t('decidesk', 'Notulen')">
				<p class="decidesk-dashboard__hint">
					<a @click="$router.push({ name: 'Minutes' })" class="decidesk-link">
						{{ t('decidesk', 'Bekijk alle notulen →') }}
					</a>
				</p>
			</CnConfigurationCard>
			<CnConfigurationCard :title="t('decidesk', 'Besluiten')">
				<p class="decidesk-dashboard__hint">
					<a @click="$router.push({ name: 'Decisions' })" class="decidesk-link">
						{{ t('decidesk', 'Bekijk alle besluiten →') }}
					</a>
				</p>
			</CnConfigurationCard>
		</div>
	</div>
</template>

<script>
import { CnConfigurationCard, CnKpiGrid, CnStatsBlock } from '@conduction/nextcloud-vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import CheckboxMarkedOutlineIcon from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import { useMinutesStore } from '../store/modules/minutes.js'
import { useDecisionStore } from '../store/modules/decisions.js'
import { useActionItemStore } from '../store/modules/actionItems.js'

export default {
	name: 'Dashboard',
	components: {
		CnConfigurationCard,
		CnKpiGrid,
		CnStatsBlock,
	},
	data() {
		return {
			AccountGroupOutline,
			AlertCircleOutline,
			CalendarClock,
			CheckCircleOutline,
			CheckDecagramIcon,
			CheckboxMarkedOutlineIcon,
			FileDocumentOutlineIcon,
			FolderOutline,
		}
	},
	computed: {
		/**
		 * Count of Minutes with lifecycle=review.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-8
		 */
		minutesInReviewCount() {
			return useMinutesStore().minutes.filter((m) => m.lifecycle === 'review').length
		},
		/**
		 * Count of Decisions with isPublished=true.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-8
		 */
		publishedDecisionCount() {
			return useDecisionStore().decisions.filter((d) => d.isPublished).length
		},
		/**
		 * Count of ActionItems with taskStatus open or in-progress.
		 *
		 * @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-8
		 */
		openActionItemCount() {
			return useActionItemStore().actionItems.filter(
				(ai) => ai.taskStatus === 'open' || ai.taskStatus === 'in-progress'
			).length
		},
		overdueActionItemCount() {
			return useActionItemStore().actionItems.filter((ai) => ai.taskStatus === 'overdue').length
		},
		completedActionItemCount() {
			return useActionItemStore().actionItems.filter((ai) => ai.taskStatus === 'completed').length
		},
	},
	created() {
		// Fetch all KPI counts in parallel.
		// @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-8
		Promise.all([
			useMinutesStore().fetchMinutes({ limit: 200 }),
			useDecisionStore().fetchDecisions({ limit: 200 }),
			useActionItemStore().fetchActionItems({ limit: 200 }),
		])
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

.decidesk-dashboard__kpi-row {
	margin-top: 16px;
}

.decidesk-dashboard__columns {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 16px;
	margin-top: 20px;
}

@media (max-width: 900px) {
	.decidesk-dashboard__columns {
		grid-template-columns: 1fr;
	}
}

.decidesk-dashboard__hint {
	margin: 0;
	line-height: 1.5;
	color: var(--color-text-maxcontrast);
}

.decidesk-link {
	cursor: pointer;
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
