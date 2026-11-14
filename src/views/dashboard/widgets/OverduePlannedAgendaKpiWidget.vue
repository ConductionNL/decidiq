<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 OverduePlannedAgendaKpiWidget — count of long-term agenda items whose
 planned period has ended while they are still planned or postponed and
 nothing realised them (REQ-LTA-010).

 OpenRegister's aggregation endpoint takes scalar-equality filters only and
 has no relative "today" token, so a declarative stat cannot make this cut.
 The widget fetches the open items and applies the period-last-day rule
 client-side (design D6 fallback), the same way OverdueActionsKpiWidget does.
 Clicking it opens the long-term agenda filtered to the open states.
-->
<template>
	<CnStatsBlock
		:title="t('decidiq', 'Long-term agenda overdue')"
		:count="count"
		:countLabel="t('decidiq', 'items')"
		:icon="CalendarAlert"
		:variant="variant"
		:loading="loading"
		:error="error"
		:route="route"
		showZeroCount
		horizontal
		data-testid="overdue-planned-agenda-kpi" />
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import CalendarAlert from 'vue-material-design-icons/CalendarAlert.vue'
import { getPlannedAgendaItems } from '../../../services/dashboardData.js'
import { OPEN_STATES, overdueItems } from '../../../utils/plannedAgenda.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'OverduePlannedAgendaKpiWidget',

	components: { CnStatsBlock },

	mixins: [dashboardRefreshMixin],

	data() {
		return {
			CalendarAlert,
			/** Planned and postponed items fetched from OR. */
			items: [],
			route: { name: 'PlannedAgenda', query: { lifecycle: OPEN_STATES } },
		}
	},

	computed: {
		/**
		 * @return {number} The number of overdue items.
		 *
		 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-010-dashboard-kpi-for-overdue-termijnagenda-items
		 */
		count() {
			return overdueItems(this.items, Date.now()).length
		},

		/**
		 * @return {string} "warning" while any item is overdue.
		 */
		variant() {
			return this.count > 0 ? 'warning' : 'default'
		},
	},

	methods: {
		/**
		 * Fetch the open items. Called on mount and refresh.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-010-dashboard-kpi-for-overdue-termijnagenda-items
		 */
		async load() {
			this.loading = true
			this.error = null
			try {
				this.items = await getPlannedAgendaItems({ lifecycle: OPEN_STATES })
			} catch (e) {
				this.error = e
				this.items = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
:deep(.cn-stats-block__header h4) {
	white-space: normal;
	overflow: visible;
	line-height: 1.2;
}
</style>
