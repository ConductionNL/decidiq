<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 ActiveDecisionsKpiWidget — count of decisions still undecided (REQ-013).

 The Decision schema has no lifecycle field, so "active" = outcome is null
 (outcome enum is [adopted, rejected]). OR/stats-block IS-NULL filter support
 is unverified, so the count is computed client-side after fetching decisions
 (Decision 5b). Clicking the card navigates to the Decisions view.
-->
<template>
	<CnStatsBlock
		:title="t('decidesk', 'Active decisions')"
		:count="count"
		:countLabel="t('decidesk', 'decisions')"
		:icon="GavelIcon"
		:loading="loading"
		:route="{ name: 'Decisions' }"
		showZeroCount
		horizontal
		data-testid="active-decisions-kpi" />
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'
import { getDecisions } from '../../../services/dashboardData.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'
import { activeDecisionCount } from './widgetLogic.js'

export default {
	name: 'ActiveDecisionsKpiWidget',

	components: { CnStatsBlock },

	mixins: [dashboardRefreshMixin],

	data() {
		return {
			GavelIcon,
			/** Decisions fetched from OR. */
			decisions: [],
		}
	},

	computed: {
		/**
		 * Count of decisions whose outcome is null (undecided ⇒ active).
		 *
		 * @return {number} Active-decision count.
		 */
		count() {
			return activeDecisionCount(this.decisions)
		},
	},

	methods: {
		/**
		 * Fetch decisions. Called on mount and on dashboard refresh.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = null
			try {
				this.decisions = await getDecisions()
			} catch (e) {
				console.error('[decidesk] ActiveDecisionsKpiWidget load failed', e)
				this.error = e
				this.decisions = []
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
