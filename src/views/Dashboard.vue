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

		<!-- @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-8 -->
		<CnKpiGrid :columns="3">
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
					<a class="decidesk-link" @click="$router.push({ name: 'Minutes' })">
						{{ t('decidesk', 'Bekijk alle notulen →') }}
					</a>
				</p>
			</CnConfigurationCard>
			<CnConfigurationCard :title="t('decidesk', 'Besluiten')">
				<p class="decidesk-dashboard__hint">
					<a class="decidesk-link" @click="$router.push({ name: 'Decisions' })">
						{{ t('decidesk', 'Bekijk alle besluiten →') }}
					</a>
				</p>
			</CnConfigurationCard>
		</div>
	</div>
</template>

<script>
import { CnConfigurationCard, CnKpiGrid, CnStatsBlock } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import CheckboxMarkedOutlineIcon from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'

export default {
	name: 'Dashboard',
	components: {
		CnConfigurationCard,
		CnKpiGrid,
		CnStatsBlock,
	},
	data() {
		return {
			CheckDecagramIcon,
			CheckboxMarkedOutlineIcon,
			FileDocumentOutlineIcon,
			minutesInReviewCount: 0,
			publishedDecisionCount: 0,
			openActionItemCount: 0,
		}
	},
	async created() {
		// Fetch accurate KPI totals using _limit=1 + data.total so that counts are
		// never silently truncated on large installations (fixes 200-object cap).
		// @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-8
		const base = generateUrl('/apps/openregister/api/objects')
		const headers = { requesttoken: OC.requestToken }
		const [minutesRes, decisionsRes, openRes, inProgressRes] = await Promise.all([
			fetch(`${base}?register=decidesk&schema=minutes&lifecycle=review&_limit=1`, { headers }),
			fetch(`${base}?register=decidesk&schema=decision&isPublished=true&_limit=1`, { headers }),
			fetch(`${base}?register=decidesk&schema=action-item&taskStatus=open&_limit=1`, { headers }),
			fetch(`${base}?register=decidesk&schema=action-item&taskStatus=in-progress&_limit=1`, { headers }),
		])
		if (minutesRes.ok) this.minutesInReviewCount = ((await minutesRes.json()).total ?? 0)
		if (decisionsRes.ok) this.publishedDecisionCount = ((await decisionsRes.json()).total ?? 0)
		const openCount = openRes.ok ? ((await openRes.json()).total ?? 0) : 0
		const inProgressCount = inProgressRes.ok ? ((await inProgressRes.json()).total ?? 0) : 0
		this.openActionItemCount = openCount + inProgressCount
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
