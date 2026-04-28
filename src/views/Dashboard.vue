<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.
@spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-8
-->
<template>
	<CnDashboardPage
		:title="t('decidesk', 'Dashboard')"
		:description="t('decidesk', 'Overzicht van notulen, besluiten en actiepunten.')"
		:widgets="widgetDefs"
		:layout="dashboardLayout"
		:loading="loading && !hasData"
		:empty-label="t('decidesk', 'No widgets configured')"
		:unavailable-label="t('decidesk', 'Widget not available')">
		<!-- Header actions: New X + Refresh, matching procest / pipelinq -->
		<template #header-actions>
			<NcButton type="primary"
				@click="$router.push({ name: 'Decisions' })">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidesk', 'New Decision') }}
			</NcButton>
			<NcButton @click="$router.push({ name: 'ActionItems' })">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidesk', 'New Action Item') }}
			</NcButton>
			<NcButton @click="$router.push({ name: 'Minutes' })">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('decidesk', 'New Minutes') }}
			</NcButton>
			<NcButton :disabled="loading"
				:aria-label="t('decidesk', 'Refresh dashboard')"
				@click="loadCounts">
				<template #icon>
					<Refresh :size="20" :class="{ 'icon-spinning': loading }" />
				</template>
			</NcButton>
		</template>

		<!-- KPI: Notulen ter goedkeuring -->
		<template #widget-count-minutes-review>
			<CnStatsBlock
				:title="t('decidesk', 'Notulen ter goedkeuring')"
				:count="minutesInReviewCount"
				:count-label="t('decidesk', 'notulen')"
				:icon="FileDocumentOutline"
				variant="warning"
				horizontal
				:route="{ name: 'Minutes' }" />
		</template>

		<!-- KPI: Gepubliceerde besluiten -->
		<template #widget-count-decisions-published>
			<CnStatsBlock
				:title="t('decidesk', 'Gepubliceerde besluiten')"
				:count="publishedDecisionCount"
				:count-label="t('decidesk', 'besluiten')"
				:icon="CheckDecagram"
				variant="success"
				horizontal
				:route="{ name: 'Decisions' }" />
		</template>

		<!-- KPI: Open actiepunten -->
		<template #widget-count-action-items-open>
			<CnStatsBlock
				:title="t('decidesk', 'Open actiepunten')"
				:count="openActionItemCount"
				:count-label="t('decidesk', 'actiepunten')"
				:icon="CheckboxMarkedOutline"
				variant="primary"
				horizontal
				:route="{ name: 'ActionItems' }" />
		</template>

		<!-- Quick links: Notulen — content-only, CnWidgetWrapper supplies the card chrome + title -->
		<template #widget-quick-minutes>
			<p class="decidesk-dashboard__hint">
				<a class="decidesk-link" @click="$router.push({ name: 'Minutes' })">
					{{ t('decidesk', 'Bekijk alle notulen →') }}
				</a>
			</p>
		</template>

		<!-- Quick links: Besluiten -->
		<template #widget-quick-decisions>
			<p class="decidesk-dashboard__hint">
				<a class="decidesk-link" @click="$router.push({ name: 'Decisions' })">
					{{ t('decidesk', 'Bekijk alle besluiten →') }}
				</a>
			</p>
		</template>
	</CnDashboardPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnDashboardPage, CnStatsBlock } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import CheckDecagram from 'vue-material-design-icons/CheckDecagram.vue'
import CheckboxMarkedOutline from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

// Widget metadata — `title` is what CnWidgetWrapper renders as the
// header when `showTitle !== false` in the layout entry. KPI widgets
// (showTitle: false) keep the title for accessibility / a11y but the
// header bar is suppressed; quick-link widgets render the title bar.
const WIDGET_DEFS = computed => [
	{ id: 'count-minutes-review', title: computed('Notulen ter goedkeuring'), type: 'custom' },
	{ id: 'count-decisions-published', title: computed('Gepubliceerde besluiten'), type: 'custom' },
	{ id: 'count-action-items-open', title: computed('Open actiepunten'), type: 'custom' },
	{ id: 'quick-minutes', title: computed('Notulen'), type: 'custom' },
	{ id: 'quick-decisions', title: computed('Besluiten'), type: 'custom' },
]

const DEFAULT_LAYOUT = [
	// KPI row — borderless, header hidden (matches procest / pipelinq).
	{ id: 1, widgetId: 'count-minutes-review', gridX: 0, gridY: 0, gridWidth: 4, gridHeight: 2, showTitle: false },
	{ id: 2, widgetId: 'count-decisions-published', gridX: 4, gridY: 0, gridWidth: 4, gridHeight: 2, showTitle: false },
	{ id: 3, widgetId: 'count-action-items-open', gridX: 8, gridY: 0, gridWidth: 4, gridHeight: 2, showTitle: false },
	// Quick-link row — show the title bar so each card has the same
	// visible-header chrome procest's content widgets get.
	{ id: 4, widgetId: 'quick-minutes', gridX: 0, gridY: 2, gridWidth: 6, gridHeight: 3 },
	{ id: 5, widgetId: 'quick-decisions', gridX: 6, gridY: 2, gridWidth: 6, gridHeight: 3 },
]

export default {
	name: 'Dashboard',
	components: {
		NcButton,
		CnDashboardPage,
		CnStatsBlock,
		CheckDecagram,
		CheckboxMarkedOutline,
		FileDocumentOutline,
		Plus,
		Refresh,
	},
	data() {
		return {
			CheckDecagram,
			CheckboxMarkedOutline,
			FileDocumentOutline,
			dashboardLayout: DEFAULT_LAYOUT,
			minutesInReviewCount: 0,
			publishedDecisionCount: 0,
			openActionItemCount: 0,
			loading: false,
		}
	},
	computed: {
		// Computed so titles re-resolve when the locale changes.
		widgetDefs() {
			return WIDGET_DEFS((key) => t('decidesk', key))
		},
		hasData() {
			return this.minutesInReviewCount > 0
				|| this.publishedDecisionCount > 0
				|| this.openActionItemCount > 0
		},
	},
	async created() {
		await this.loadCounts()
	},
	methods: {
		async loadCounts() {
			this.loading = true
			// Fetch accurate KPI totals using _limit=1 + data.total so that counts are
			// never silently truncated on large installations.
			// @spec openspec/changes/p2-minutes-and-decisions/tasks.md#task-8
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
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
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

.icon-spinning {
	animation: cn-spin 1s linear infinite;
}

@keyframes cn-spin {
	from { transform: rotate(0deg); }
	to { transform: rotate(360deg); }
}
</style>
