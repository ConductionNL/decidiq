<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 UpcomingMeetingsKpiWidget — count of meetings with lifecycle=scheduled and
 scheduledDate >= now (REQ-008).

 Fetches the scheduled meetings server-side (lifecycle filter) and applies the
 `scheduledDate >= now` date comparison client-side (Declarative-vs-Imperative
 table). Clicking the card navigates to the Meetings view.
-->
<template>
	<CnStatsBlock
		:title="t('decidesk', 'Upcoming meetings')"
		:count="count"
		:count-label="t('decidesk', 'meetings')"
		:icon="CalendarClockOutline"
		:loading="loading"
		:route="{ name: 'Meetings' }"
		show-zero-count
		horizontal
		data-testid="upcoming-meetings-kpi" />
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import CalendarClockOutline from 'vue-material-design-icons/CalendarClockOutline.vue'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'
import { upcomingMeetings } from './widgetLogic.js'
import { getMeetings } from '../../../services/dashboardData.js'

export default {
	name: 'UpcomingMeetingsKpiWidget',

	components: { CnStatsBlock },

	mixins: [dashboardRefreshMixin],

	data() {
		return {
			CalendarClockOutline,
			/** Scheduled meetings fetched from OR. */
			meetings: [],
		}
	},

	computed: {
		/**
		 * Count of meetings scheduled at or after "now".
		 *
		 * @return {number} Upcoming-meeting count.
		 */
		count() {
			return upcomingMeetings(this.meetings, Date.now()).length
		},
	},

	methods: {
		/**
		 * Fetch scheduled meetings. Called on mount and on dashboard refresh.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = null
			try {
				this.meetings = await getMeetings({ lifecycle: 'scheduled' })
			} catch (e) {
				console.error('[decidesk] UpcomingMeetingsKpiWidget load failed', e)
				this.error = e
				this.meetings = []
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
