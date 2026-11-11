<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 UpcomingMeetingsListWidget — list of upcoming meetings (REQ-011).

 Fetches lifecycle=scheduled meetings, keeps the future ones, sorts ascending
 by scheduledDate, and flags entries within the next 24 hours for an urgency
 highlight. Each row shows title, scheduled date, governance body name and
 agenda-item count; clicking a row navigates to the meeting detail view.
-->
<template>
	<div class="dashboard-list-widget" data-testid="upcoming-meetings-list">
		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="rows.length === 0"
			:name="t('decidiq', 'No upcoming meetings')"
			data-testid="upcoming-meetings-list-empty">
			<template #icon>
				<CalendarBlankOutline :size="32" />
			</template>
		</NcEmptyContent>

		<ul v-else class="dashboard-list-widget__list">
			<li
				v-for="meeting in rows"
				:key="meeting.id"
				:class="{ 'dashboard-list-widget__row--urgent': meeting._urgent }"
				:data-testid="`upcoming-meeting-row-${meeting.id}`"
				class="dashboard-list-widget__row"
				role="button"
				tabindex="0"
				@click="openMeeting(meeting)"
				@keydown.enter.prevent="openMeeting(meeting)"
				@keydown.space.prevent="openMeeting(meeting)">
				<div class="dashboard-list-widget__main">
					<span class="dashboard-list-widget__title">{{
						meeting.title || meeting.name
					}}</span>
					<span class="dashboard-list-widget__meta">{{
						bodyName(meeting)
					}}</span>
				</div>
				<div class="dashboard-list-widget__aside">
					<span class="dashboard-list-widget__date">{{
						formatDate(meeting.scheduledDate)
					}}</span>
					<span class="dashboard-list-widget__meta">
						{{
							t('decidiq', '{n} agenda items', {
								n: agendaCount(meeting),
							})
						}}
					</span>
					<span
						v-if="meeting._urgent"
						class="dashboard-list-widget__badge dashboard-list-widget__badge--urgent">
						{{ countdownLabel(meeting) }}
					</span>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import CalendarBlankOutline from 'vue-material-design-icons/CalendarBlankOutline.vue'
import { getMeetings } from '../../../services/dashboardData.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'
import { countdownBucket, isUrgent, upcomingMeetings } from './widgetLogic.js'

export default {
	name: 'UpcomingMeetingsListWidget',

	components: { NcEmptyContent, NcLoadingIcon, CalendarBlankOutline },

	mixins: [dashboardRefreshMixin],

	data() {
		return {
			/** Scheduled meetings fetched from OR. */
			meetings: [],
		}
	},

	computed: {
		/**
		 * Upcoming meetings, ascending by date, each tagged with a `_urgent`
		 * flag (within the next 24 hours).
		 *
		 * @return {Array<object>} Decorated, sorted meeting rows.
		 */
		rows() {
			const now = Date.now()
			return upcomingMeetings(this.meetings, now).map((m) => ({
				...m,
				_urgent: isUrgent(m.scheduledDate, now),
			}))
		},
	},

	methods: {
		/**
		 * Fetch scheduled meetings. Called on mount and on dashboard refresh.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/dashboard/spec.md#requirement-upcoming-meetings-widget
		 */
		async load() {
			this.loading = true
			this.error = null
			try {
				this.meetings = await getMeetings({ lifecycle: 'scheduled' })
			} catch (e) {
				console.error('[decidiq] UpcomingMeetingsListWidget load failed', e)
				this.error = e
				this.meetings = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Resolve the governance-body display name for a meeting row.
		 *
		 * @param {object} meeting The meeting object.
		 * @return {string} The body name, or empty string when absent.
		 */
		bodyName(meeting) {
			const body = meeting.governanceBody
			if (!body) {
				return ''
			}
			return typeof body === 'object' ? body.name || '' : String(body)
		},

		/**
		 * Count of agenda items linked to a meeting row.
		 *
		 * @param {object} meeting The meeting object.
		 * @return {number} Agenda-item count.
		 */
		agendaCount(meeting) {
			return Array.isArray(meeting.agendaItems)
				? meeting.agendaItems.length
				: 0
		},

		/**
		 * Map a meeting's countdown bucket to a translated urgency label.
		 *
		 * @param {object} meeting The meeting object.
		 * @return {string} "today" / "tomorrow" / a generic urgent label.
		 *
		 * @spec openspec/specs/dashboard/spec.md#requirement-upcoming-meetings-widget
		 */
		countdownLabel(meeting) {
			const { key } = countdownBucket(meeting.scheduledDate, Date.now())
			if (key === 'today' || key === 'overdue') {
				return t('decidiq', 'today')
			}
			if (key === 'tomorrow') {
				return t('decidiq', 'tomorrow')
			}
			return t('decidiq', 'today')
		},

		/**
		 * Format an ISO date for display.
		 *
		 * @param {string} value ISO date string.
		 * @return {string} Localised date-time, or the raw value on failure.
		 */
		formatDate(value) {
			const d = new Date(value)
			return Number.isNaN(d.getTime()) ? value || '' : d.toLocaleString()
		},

		/**
		 * Navigate to the meeting detail view.
		 *
		 * @param {object} meeting The clicked meeting.
		 * @return {void}
		 */
		openMeeting(meeting) {
			this.$router.push({
				name: 'MeetingDetail',
				params: { id: String(meeting.id) },
			})
		},
	},
}
</script>

<style scoped src="./dashboardListWidget.css"></style>

<style scoped>
.dashboard-list-widget__row--urgent {
	border-inline-start: 3px solid var(--color-warning, #c28900);
}

.dashboard-list-widget__main,
.dashboard-list-widget__aside {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.dashboard-list-widget__aside {
	align-items: flex-end;
	text-align: end;
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
	margin-top: 2px;
	padding: 1px 8px;
	border-radius: 12px;
	font-size: 0.8em;
}

.dashboard-list-widget__badge--urgent {
	background: var(--color-warning, #c28900);
	color: var(--color-primary-text, #fff);
}
</style>
