<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 PendingVotesListWidget — voting-rounds awaiting the current user's vote
 (REQ-012).

 Resolves the current user to their participant record, computes the
 set-difference of open voting-rounds minus the rounds the participant has
 already voted in, and lists each with a deadline countdown. Entries with less
 than 24 hours remaining get a red urgency indicator. A user with no
 participant record — or who has voted in everything — sees the "No pending
 votes" empty state with a checkmark (Decision 4). Clicking a row navigates to
 the voting interface for that round's motion.
-->
<template>
	<div class="dashboard-list-widget" data-testid="pending-votes-list">
		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="rows.length === 0"
			:name="t('decidesk', 'No pending votes')"
			data-testid="pending-votes-list-empty">
			<template #icon>
				<CheckCircleOutline :size="32" />
			</template>
		</NcEmptyContent>

		<ul v-else class="dashboard-list-widget__list">
			<li v-for="round in rows"
				:key="round.id"
				:class="{ 'dashboard-list-widget__row--urgent': round._urgent }"
				:data-testid="`pending-vote-row-${round.id}`"
				class="dashboard-list-widget__row"
				@click="openVote(round)">
				<div class="dashboard-list-widget__main">
					<span class="dashboard-list-widget__title">{{ voteTitle(round) }}</span>
					<span class="dashboard-list-widget__meta">{{ countdownLabel(round) }}</span>
				</div>
				<div class="dashboard-list-widget__aside">
					<span v-if="round._urgent"
						class="dashboard-list-widget__badge dashboard-list-widget__badge--urgent">
						{{ t('decidesk', 'Urgent') }}
					</span>
					<NcButton type="primary" @click.stop="openVote(round)">
						{{ t('decidesk', 'Vote now') }}
					</NcButton>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'
import { resolveParticipantId, pendingVotingRounds, isUrgent, countdownBucket } from './widgetLogic.js'
import { getParticipants, getVotingRounds, getVotes } from '../../../services/dashboardData.js'

export default {
	name: 'PendingVotesListWidget',

	components: { NcButton, NcEmptyContent, NcLoadingIcon, CheckCircleOutline },

	mixins: [dashboardRefreshMixin],

	data() {
		return {
			/** Open voting-rounds awaiting this participant's vote. */
			pending: [],
		}
	},

	computed: {
		/**
		 * Pending rounds tagged with a `_urgent` flag (deadline < 24h).
		 *
		 * @return {Array<object>} Decorated pending rounds.
		 */
		rows() {
			const now = Date.now()
			return this.pending.map((r) => ({
				...r,
				_urgent: isUrgent(r.deadline, now),
			}))
		},
	},

	methods: {
		/**
		 * Fetch participants + open rounds + the user's votes and compute the
		 * pending set-difference. Called on mount and on dashboard refresh.
		 *
		 * @return {Promise<void>}
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
				console.error('[decidesk] PendingVotesListWidget load failed', e)
				this.error = e
				this.pending = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Resolve the title to show for a pending round (its motion title,
		 * falling back to the round's own title).
		 *
		 * @param {object} round The voting-round object.
		 * @return {string} A display title.
		 */
		voteTitle(round) {
			const motion = round.motion
			if (motion && typeof motion === 'object' && motion.title) {
				return motion.title
			}
			return round.title || round.name || t('decidesk', 'Voting round')
		},

		/**
		 * Map a round's deadline to a translated countdown label.
		 *
		 * @param {object} round The voting-round object.
		 * @return {string} A human countdown label.
		 */
		countdownLabel(round) {
			const { key } = countdownBucket(round.deadline, Date.now())
			if (key === 'overdue' || key === 'today') {
				return t('decidesk', 'Less than 24 hours remaining')
			}
			if (key === 'tomorrow') {
				return t('decidesk', 'tomorrow')
			}
			if (key === 'unknown') {
				return ''
			}
			return t('decidesk', 'today')
		},

		/**
		 * Navigate to the voting interface for a round's motion.
		 *
		 * @param {object} round The clicked voting-round.
		 * @return {void}
		 */
		openVote(round) {
			const motion = round.motion
			const motionId = motion && typeof motion === 'object' ? motion.id : motion
			if (motionId) {
				this.$router.push({ name: 'MotionDetail', params: { id: String(motionId) } })
			}
		},
	},
}
</script>

<style scoped>
.dashboard-list-widget {
	display: flex;
	flex-direction: column;
	min-height: 0;
}

.dashboard-list-widget__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.dashboard-list-widget__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border, #e0e0e0);
	cursor: pointer;
}

.dashboard-list-widget__row:hover {
	background: var(--color-background-hover, #f5f5f5);
}

.dashboard-list-widget__row--urgent {
	border-inline-start: 3px solid var(--color-error, #c93030);
}

.dashboard-list-widget__main {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.dashboard-list-widget__aside {
	display: flex;
	align-items: center;
	gap: 8px;
}

.dashboard-list-widget__title {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.dashboard-list-widget__meta {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #767676);
}

.dashboard-list-widget__badge {
	display: inline-block;
	padding: 1px 8px;
	border-radius: 12px;
	font-size: 0.8em;
}

.dashboard-list-widget__badge--urgent {
	background: var(--color-error, #c93030);
	color: var(--color-primary-text, #fff);
}
</style>
