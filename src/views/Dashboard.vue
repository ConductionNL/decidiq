<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.
-->
<template>
	<div>
		<CnDashboardPage
			:title="t('decidesk', 'Dashboard')"
			:description="t('decidesk', 'Overzicht van notulen, besluiten en actiepunten.')"
			:widgets="widgetDefs"
			:layout="dashboardLayout"
			:loading="loading"
			:empty-label="t('decidesk', 'No widgets configured')"
			@layout-change="onLayoutChange">

			<!-- KPI: Notulen ter goedkeuring -->
			<template #widget-count-minutes-review>
				<CnStatsBlock
					:title="t('decidesk', 'Notulen ter goedkeuring')"
					:count="minutesInReviewCount"
					:count-label="t('decidesk', 'notulen')"
					:icon="FileDocumentOutlineIcon"
					variant="warning"
					horizontal
					:route="{ name: 'Minutes' }" />
			</template>

			<!-- KPI: Gepubliceerde besluiten -->
			<template #widget-count-published-decisions>
				<CnStatsBlock
					:title="t('decidesk', 'Gepubliceerde besluiten')"
					:count="publishedDecisionCount"
					:count-label="t('decidesk', 'besluiten')"
					:icon="CheckDecagramIcon"
					variant="success"
					horizontal
					:route="{ name: 'Decisions' }" />
			</template>

			<!-- KPI: Open actiepunten -->
			<template #widget-count-open-actions>
				<CnStatsBlock
					:title="t('decidesk', 'Open actiepunten')"
					:count="openActionItemCount"
					:count-label="t('decidesk', 'actiepunten')"
					:icon="CheckboxMarkedOutlineIcon"
					variant="primary"
					horizontal
					:route="{ name: 'ActionItems' }" />
			</template>

			<!-- Notulen widget -->
			<template #widget-notulen-actions>
				<NcButton type="tertiary" @click="$router.push({ name: 'Minutes' })">
					{{ t('decidesk', 'Bekijk alle') }}
				</NcButton>
			</template>
			<template #widget-notulen>
				<div class="widget-empty">
					{{ t('decidesk', 'Geen recente notulen') }}
				</div>
			</template>

			<!-- Besluiten widget -->
			<template #widget-besluiten-actions>
				<NcButton type="tertiary" @click="$router.push({ name: 'Decisions' })">
					{{ t('decidesk', 'Bekijk alle') }}
				</NcButton>
			</template>
			<template #widget-besluiten>
				<div class="widget-empty">
					{{ t('decidesk', 'Geen recente besluiten') }}
				</div>
			</template>
		</CnDashboardPage>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnDashboardPage, CnStatsBlock } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import CheckboxMarkedOutlineIcon from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'

const DEFAULT_LAYOUT = [
	{ id: 1, widgetId: 'count-minutes-review', gridX: 0, gridY: 0, gridWidth: 4, gridHeight: 2, showTitle: false },
	{ id: 2, widgetId: 'count-published-decisions', gridX: 4, gridY: 0, gridWidth: 4, gridHeight: 2, showTitle: false },
	{ id: 3, widgetId: 'count-open-actions', gridX: 8, gridY: 0, gridWidth: 4, gridHeight: 2, showTitle: false },
	{ id: 4, widgetId: 'notulen', gridX: 0, gridY: 2, gridWidth: 6, gridHeight: 4 },
	{ id: 5, widgetId: 'besluiten', gridX: 6, gridY: 2, gridWidth: 6, gridHeight: 4 },
]

export default {
	name: 'Dashboard',
	components: {
		NcButton,
		CnDashboardPage,
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
			loading: false,
			dashboardLayout: [...DEFAULT_LAYOUT],
		}
	},
	computed: {
		widgetDefs() {
			return [
				{ id: 'count-minutes-review', title: t('decidesk', 'Notulen ter goedkeuring'), type: 'custom' },
				{ id: 'count-published-decisions', title: t('decidesk', 'Gepubliceerde besluiten'), type: 'custom' },
				{ id: 'count-open-actions', title: t('decidesk', 'Open actiepunten'), type: 'custom' },
				{ id: 'notulen', title: t('decidesk', 'Notulen'), type: 'custom' },
				{ id: 'besluiten', title: t('decidesk', 'Besluiten'), type: 'custom' },
			]
		},
	},
	async created() {
		await this.loadDashboardData()
	},
	methods: {
		async loadDashboardData() {
			this.loading = true
			const base = generateUrl('/apps/openregister/api/objects')
			const headers = { requesttoken: OC.requestToken }
			try {
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
			} catch (err) {
				console.error('Dashboard fetch error:', err)
			} finally {
				this.loading = false
			}
		},
		onLayoutChange(newLayout) {
			this.dashboardLayout = newLayout
		},
	},
}
</script>

<style scoped>
.widget-empty {
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}
</style>
