<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 PendingVotesKpiWidget — count of open voting-rounds awaiting the current
 user's vote (REQ-009).

 Resolves the current Nextcloud user to their participant record, fetches the
 open voting-rounds and the participant's cast votes, and counts the
 set-difference (open rounds the participant has NOT voted in). A user with no
 matching participant record is not a voting member, so the count is 0
 (Decision 4). variant="warning" while any vote is pending, else "default".
-->
<template>
	<CnStatsBlock
		:title="t('decidiq', 'Pending votes')"
		:count="count"
		:countLabel="t('decidiq', 'votes')"
		:icon="VoteOutline"
		:variant="variant"
		:loading="loading"
		:error="error"
		:route="{ name: 'Decisions' }"
		showZeroCount
		horizontal
		data-testid="pending-votes-kpi" />
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import VoteOutline from 'vue-material-design-icons/VoteOutline.vue'
import {
	getParticipants,
	getVotes,
	getVotingRounds,
} from '../../../services/dashboardData.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'
import {
	pendingInRange,
	pendingVotingRounds,
	resolveParticipantId,
} from './widgetLogic.js'

export default {
	name: 'PendingVotesKpiWidget',

	components: { CnStatsBlock },

	mixins: [dashboardRefreshMixin],

	inject: {
		/**
		 * Reactive dashboard date-range ref provided by CnDashboardPage
		 * (`{ from, to, preset }` or null). Scopes the count to the active
		 * window by votingDeadline; null / the "All" preset counts every
		 * pending round.
		 */
		cnDashboardDateRange: { default: null },
	},

	data() {
		return {
			VoteOutline,
			/** Open voting-rounds awaiting this participant's vote. */
			pending: [],
		}
	},

	computed: {
		/**
		 * The unwrapped active date window (`{ from, to, preset }`) or null.
		 *
		 * @return {object|null} The dashboard date range.
		 */
		activeRange() {
			const r = this.cnDashboardDateRange
			if (!r) {
				return null
			}
			return typeof r === 'object' && 'value' in r ? r.value : r
		},

		/**
		 * Number of pending votes for the current user within the active window.
		 *
		 * @return {number} Count of open rounds awaiting the user's vote.
		 */
		count() {
			return pendingInRange(this.pending, this.activeRange).length
		},

		/**
		 * CnStatsBlock variant: "warning" while votes are pending.
		 *
		 * @return {string} The stats-block variant.
		 */
		variant() {
			return this.count > 0 ? 'warning' : 'default'
		},
	},

	methods: {
		/**
		 * Fetch participants + open rounds + the user's votes and compute the
		 * pending set-difference. Called on mount and on dashboard refresh.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/dashboard/spec.md#requirement-my-pending-votes-widget
		 */
		async load() {
			this.loading = true
			this.error = null
			try {
				const uid = getCurrentUser()?.uid
				const [participants, openRounds, votes] = await Promise.all([
					getParticipants(),
					getVotingRounds({ lifecycle: 'open' }),
					getVotes(),
				])
				const participantId = resolveParticipantId(participants, uid)
				this.pending = pendingVotingRounds(openRounds, votes, participantId)
			} catch (e) {
				console.error('[decidiq] PendingVotesKpiWidget load failed', e)
				this.error = e
				this.pending = []
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
