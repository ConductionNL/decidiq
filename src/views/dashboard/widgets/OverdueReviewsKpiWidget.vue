<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 OverdueReviewsKpiWidget — count of other positions (nevenfuncties) that have
 not ended and whose last review (or declaration, when never reviewed) is
 more than 12 months old (interests-and-integrity, REQ-INT-010).

 OpenRegister's aggregation endpoint honours only scalar-equality filters, so
 a declarative stat widget cannot evaluate "older than 12 months" and would
 show a wrong number. The design's KPI fallback applies: fetch the positions
 that have not ended and count client-side, the same pattern as the other
 dashboard KPI cards. Clicking opens the ancillary-positions index filtered
 to the outdated reviews.
-->
<template>
	<CnStatsBlock
		:title="t('decidiq', 'Other positions without a current review')"
		:count="count"
		:countLabel="t('decidiq', 'positions')"
		:icon="BriefcaseClockOutline"
		:variant="variant"
		:loading="loading"
		:error="error"
		:route="route"
		showZeroCount
		horizontal
		data-testid="overdue-reviews-kpi" />
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import BriefcaseClockOutline from 'vue-material-design-icons/BriefcaseClockOutline.vue'
import { ensureRelationType } from '../../../components/tabs/useRelationStore.js'
import {
	OPEN_STATES,
	overdueReviews,
	overdueReviewsRoute,
} from '../../../utils/integrityCompliance.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'OverdueReviewsKpiWidget',

	components: { CnStatsBlock },

	mixins: [dashboardRefreshMixin],

	data() {
		return {
			BriefcaseClockOutline,
			/** Other positions that have not ended, fetched from OR. */
			positions: [],
		}
	},

	computed: {
		/**
		 * Count of positions whose annual review is overdue.
		 *
		 * @return {number} The count.
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
		 */
		count() {
			return overdueReviews(this.positions, Date.now()).length
		},

		/**
		 * CnStatsBlock variant: "warning" while any review is overdue.
		 *
		 * @return {string} The stats-block variant.
		 */
		variant() {
			return this.count > 0 ? 'warning' : 'default'
		},

		/**
		 * The pre-filtered ancillary-positions index.
		 *
		 * @return {{name: string, query: object}} The route.
		 */
		route() {
			return overdueReviewsRoute()
		},
	},

	methods: {
		/**
		 * Fetch the positions that have not ended. Called on mount and refresh.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
		 */
		async load() {
			this.loading = true
			this.error = null
			try {
				this.positions = await ensureRelationType('ancillary-position')
					.fetchCollection('ancillary-position', {
						lifecycle: [...OPEN_STATES],
						_limit: 500,
					}) || []
			} catch (e) {
				this.error = e
				this.positions = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
/* Let the long KPI title wrap in the narrow horizontal stat tile. */
:deep(.cn-stats-block__header h4) {
	white-space: normal;
	overflow: visible;
	line-height: 1.2;
}
</style>
