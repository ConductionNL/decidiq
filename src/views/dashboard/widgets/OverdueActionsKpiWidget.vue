<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 OverdueActionsKpiWidget — count of action items past their dueDate that are
 not completed or cancelled (REQ-010).

 Fetches open / in-progress action items server-side and applies the compound
 "dueDate < now AND taskStatus NOT IN (completed, cancelled)" rule client-side
 (Declarative-vs-Imperative table). variant="error" while any item is overdue.
 Clicking the card navigates to the Action items view.
-->
<template>
	<CnStatsBlock
		:title="t('decidesk', 'Overdue actions')"
		:count="count"
		:countLabel="t('decidesk', 'actions')"
		:icon="AlertCircleOutline"
		:variant="variant"
		:loading="loading"
		:route="{ name: 'ActionItems' }"
		showZeroCount
		horizontal
		data-testid="overdue-actions-kpi" />
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import { getActionItems } from '../../../services/dashboardData.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'
import { overdueActions } from './widgetLogic.js'

export default {
	name: 'OverdueActionsKpiWidget',

	components: { CnStatsBlock },

	mixins: [dashboardRefreshMixin],

	data() {
		return {
			AlertCircleOutline,
			/** Open / in-progress action items fetched from OR. */
			items: [],
		}
	},

	computed: {
		/**
		 * Count of overdue action items.
		 *
		 * @return {number} Overdue-action count.
		 */
		count() {
			return overdueActions(this.items, Date.now()).length
		},

		/**
		 * CnStatsBlock variant: "error" while any action is overdue.
		 *
		 * @return {string} The stats-block variant.
		 */
		variant() {
			return this.count > 0 ? 'error' : 'default'
		},
	},

	methods: {
		/**
		 * Fetch open / in-progress action items. Called on mount and refresh.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = null
			try {
				this.items = await getActionItems({
					taskStatus: ['open', 'in-progress'],
				})
			} catch (e) {
				console.error('[decidesk] OverdueActionsKpiWidget load failed', e)
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
/* Let long KPI titles wrap rather than ellipsis-clip in the narrow horizontal
   stat tile (CnStatsBlock's title defaults to nowrap + ellipsis). */
:deep(.cn-stats-block__header h4) {
	white-space: normal;
	overflow: visible;
	line-height: 1.2;
}
</style>
