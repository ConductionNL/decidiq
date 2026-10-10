<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 MeetingCalendarView — a month calendar of meetings, alongside the existing
 table view on the meeting index.

 A meeting is an event; a table is the wrong primary shape for one. The table
 stays (it sorts and filters better), and this is the second view rather than a
 replacement — reached through MeetingViewToggle in the index page's actions
 slot.

 Meetings are placed on the grid by `scheduledDate`. A meeting with no
 scheduledDate cannot be placed and is listed separately BELOW the grid rather
 than silently dropped: a calendar that quietly omits rows is the same class of
 defect as a client-side filter over server-paged data.

 @spec openspec/changes/configurable-types-domain-model/design.md
-->
<template>
	<div class="meeting-calendar" data-testid="meeting-calendar">
		<div class="meeting-calendar__toolbar">
			<NcButton
				:aria-label="t('decidiq', 'Previous month')"
				data-testid="meeting-calendar-prev"
				@click="step(-1)">
				<template #icon>
					<ChevronLeft :size="20" />
				</template>
			</NcButton>
			<h2 class="meeting-calendar__title" data-testid="meeting-calendar-title">
				{{ monthLabel }}
			</h2>
			<NcButton
				:aria-label="t('decidiq', 'Next month')"
				data-testid="meeting-calendar-next"
				@click="step(1)">
				<template #icon>
					<ChevronRight :size="20" />
				</template>
			</NcButton>
			<NcButton data-testid="meeting-calendar-today" @click="goToday">
				{{ t('decidiq', 'Today') }}
			</NcButton>
			<!-- The toggle is rendered HERE, not through the page's
			     actionsComponent slot: measured in the browser, a
			     type:"custom" page does not render actionsComponent, so
			     relying on it left the calendar with no way back to the
			     table — a dead end. The index page keeps its
			     actionsComponent, where it demonstrably does render. -->
			<MeetingViewToggle class="meeting-calendar__toggle" />
		</div>

		<div class="meeting-calendar__filters">
			<NcSelect
				v-model="audienceOption"
				class="meeting-calendar__filter"
				:options="audienceOptions"
				:inputLabel="t('decidiq', 'Audience')"
				:placeholder="t('decidiq', 'All audiences')"
				label="label"
				data-testid="meeting-calendar-audience" />
			<NcSelect
				v-model="bodyOption"
				class="meeting-calendar__filter"
				:options="bodyOptions"
				:inputLabel="t('decidiq', 'Body')"
				:placeholder="t('decidiq', 'All bodies')"
				label="label"
				data-testid="meeting-calendar-body" />
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<div class="meeting-calendar__grid" role="grid">
				<div
					v-for="name in weekdayNames"
					:key="'h-' + name"
					class="meeting-calendar__weekday"
					role="columnheader">
					{{ name }}
				</div>
				<div
					v-for="cell in cells"
					:key="cell.key"
					class="meeting-calendar__cell"
					:class="{
						'meeting-calendar__cell--outside': !cell.inMonth,
						'meeting-calendar__cell--today': cell.isToday,
					}"
					role="gridcell">
					<span class="meeting-calendar__daynum">{{ cell.day }}</span>
					<div
						v-for="meeting in cell.meetings"
						:key="meeting.id"
						class="meeting-calendar__entry">
						<button
							type="button"
							class="meeting-calendar__event"
							:data-testid="`meeting-calendar-event-${meeting.id}`"
							:title="meeting.title"
							@click="open(meeting)">
							{{ meeting.title }}
						</button>
						<!-- An evening's parallel sessions sit inside its event
						     (planning-parallel-sessions, REQ-PPS-003). -->
						<ul
							v-if="meeting.sessions.length"
							class="meeting-calendar__sessions"
							:aria-label="
								t('decidiq', 'Sessions of {title}', {
									title: meeting.title,
								})
							"
							:data-testid="`meeting-calendar-sessions-${meeting.id}`">
							<li
								v-for="session in meeting.sessions"
								:key="session.id">
								<button
									type="button"
									class="meeting-calendar__session"
									:data-testid="`meeting-calendar-session-${session.id}`"
									@click="open(session)">
									{{ sessionLabel(session) }}
								</button>
							</li>
						</ul>
					</div>
				</div>
			</div>

			<!-- Never silently dropped: a meeting with no scheduledDate has no
			     cell to sit in, so it is surfaced here instead. -->
			<section v-if="undated.length" class="meeting-calendar__undated">
				<h3>{{ t('decidiq', 'Meetings without a date') }}</h3>
				<ul>
					<li v-for="meeting in undated" :key="meeting.id">
						<button type="button" @click="open(meeting)">
							{{ meeting.title }}
						</button>
					</li>
				</ul>
			</section>

			<NcEmptyContent
				v-if="!meetings.length"
				:name="t('decidiq', 'No meetings')"
				data-testid="meeting-calendar-empty">
				<template #icon>
					<CalendarBlank :size="32" />
				</template>
			</NcEmptyContent>
		</template>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import CalendarBlank from 'vue-material-design-icons/CalendarBlank.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import MeetingViewToggle from './MeetingViewToggle.vue'
import { getMeetings } from '../../services/dashboardData.js'
import { useObjectStore } from '../../store/store.js'
import {
	AUDIENCES,
	calendarParams,
	NO_AUDIENCE,
	withoutAudience,
} from '../../utils/activityCalendar.js'
import { groupSessions, sessionLabel } from '../../utils/meetingSessions.js'

/** Milliseconds in one day, used to walk the six-week grid. */
const DAY_MS = 24 * 60 * 60 * 1000

export default {
	name: 'MeetingCalendarView',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		CalendarBlank,
		ChevronLeft,
		ChevronRight,
		MeetingViewToggle,
	},

	data() {
		const now = new Date()
		return {
			loading: true,
			meetings: [],
			year: now.getFullYear(),
			month: now.getMonth(),
			types: [],
			bodies: [],
		}
	},

	computed: {
		/**
		 * The audience filter's options: the five audiences and "No audience set".
		 *
		 * @return {Array<{id: string, label: string}>} Options.
		 *
		 * @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-002-the-calendar-filters-by-audience-and-by-body
		 */
		audienceOptions() {
			const labels = {
				council: this.t('decidiq', 'Council'),
				executive: this.t('decidiq', 'Executive'),
				'joint-arrangement': this.t('decidiq', 'Joint arrangement'),
				residents: this.t('decidiq', 'Residents'),
				staff: this.t('decidiq', 'Staff'),
			}
			return [
				...AUDIENCES.map((id) => ({ id, label: labels[id] })),
				{ id: NO_AUDIENCE, label: this.t('decidiq', 'No audience set') },
			]
		},

		/**
		 * The body filter's options.
		 *
		 * @return {Array<{id: string, label: string}>} Options.
		 *
		 * @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-002-the-calendar-filters-by-audience-and-by-body
		 */
		bodyOptions() {
			return this.bodies.map((body) => ({
				id: body.id ?? body['@self']?.id,
				label: body.name || body.title || '',
			}))
		},

		/**
		 * The chosen audience, kept in the address so the view can be shared.
		 *
		 * @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-002-the-calendar-filters-by-audience-and-by-body
		 */
		audienceOption: {
			/** @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-002-the-calendar-filters-by-audience-and-by-body */
			get() {
				const id = this.$route?.query?.audience || ''
				return this.audienceOptions.find((o) => o.id === id) || null
			},

			/** @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-002-the-calendar-filters-by-audience-and-by-body */
			set(option) {
				this.setQuery('audience', option ? option.id : '')
			},
		},

		/**
		 * The chosen body, kept in the address.
		 *
		 * @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-002-the-calendar-filters-by-audience-and-by-body
		 */
		bodyOption: {
			/** @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-002-the-calendar-filters-by-audience-and-by-body */
			get() {
				const id = this.$route?.query?.body || ''
				return this.bodyOptions.find((o) => o.id === id) || null
			},

			/** @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-002-the-calendar-filters-by-audience-and-by-body */
			set(option) {
				this.setQuery('body', option ? option.id : '')
			},
		},

		/**
		 * Localised weekday headers, Monday first.
		 *
		 * @return {Array<string>} Seven short weekday names.
		 *
		 * @spec openspec/changes/configurable-types-domain-model/tasks.md#task-1.23
		 */
		weekdayNames() {
			const fmt = new Intl.DateTimeFormat(undefined, { weekday: 'short' })
			// 2024-01-01 was a Monday, so this walks Mon..Sun.
			return Array.from({ length: 7 }, (_, i) =>
				fmt.format(new Date(Date.UTC(2024, 0, 1 + i))),
			)
		},

		/**
		 * The month being shown, as a localised heading.
		 *
		 * @return {string} e.g. "September 2026".
		 *
		 * @spec openspec/changes/configurable-types-domain-model/tasks.md#task-1.23
		 */
		monthLabel() {
			return new Intl.DateTimeFormat(undefined, {
				month: 'long',
				year: 'numeric',
			}).format(new Date(this.year, this.month, 1))
		},

		/**
		 * The meetings as events: an evening holds its loaded sessions, and a
		 * session whose evening is not loaded stands on its own.
		 *
		 * @return {Array<object>} Meetings, each with a `sessions` array.
		 *
		 * @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-003-the-calendar-and-the-meetings-list-group-sessions-under-their-evening
		 */
		events() {
			return groupSessions(this.meetings)
		},

		/**
		 * Meetings that carry a parseable scheduledDate, keyed by local Y-M-D.
		 *
		 * @return {Object<string, Array<object>>} Date key → meetings.
		 *
		 * @spec openspec/changes/configurable-types-domain-model/tasks.md#task-1.23
		 */
		byDate() {
			const map = {}
			for (const meeting of this.events) {
				const key = this.dateKey(meeting.scheduledDate)
				if (key === null) {
					continue
				}
				;(map[key] = map[key] || []).push(meeting)
			}
			return map
		},

		/**
		 * Meetings with no usable scheduledDate. Listed rather than dropped.
		 *
		 * @return {Array<object>} Undated meetings.
		 *
		 * @spec openspec/changes/configurable-types-domain-model/tasks.md#task-1.23
		 */
		undated() {
			return this.events.filter((m) => this.dateKey(m.scheduledDate) === null)
		},

		/**
		 * The 42 cells of a six-week month grid, Monday-first.
		 *
		 * @return {Array<object>} Cell descriptors with their meetings.
		 *
		 * @spec openspec/changes/configurable-types-domain-model/tasks.md#task-1.23
		 */
		cells() {
			const first = new Date(this.year, this.month, 1)
			// getDay() is Sunday-based; shift so Monday is column 0.
			const lead = (first.getDay() + 6) % 7
			const start = new Date(first.getTime() - lead * DAY_MS)
			const todayKey = this.dateKey(new Date())

			return Array.from({ length: 42 }, (_, i) => {
				const date = new Date(start.getTime() + i * DAY_MS)
				const key = this.dateKey(date)
				return {
					key,
					day: date.getDate(),
					inMonth: date.getMonth() === this.month,
					isToday: key === todayKey,
					meetings: this.byDate[key] || [],
				}
			})
		},
	},

	watch: {
		'$route.query.audience': {
			/** @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-002-the-calendar-filters-by-audience-and-by-body */
			handler() {
				this.load()
			},
		},

		'$route.query.body': {
			/** @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-002-the-calendar-filters-by-audience-and-by-body */
			handler() {
				this.load()
			},
		},
	},

	/** @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-002-the-calendar-filters-by-audience-and-by-body */
	async mounted() {
		const store = useObjectStore()
		try {
			const [types, bodies] = await Promise.all([
				store.fetchCollection('meeting-type', { _limit: 200 }),
				store.fetchCollection('governance-body', { _limit: 200 }),
			])
			this.types = types || []
			this.bodies = bodies || []
		} catch (e) {
			// eslint-disable-next-line no-console
			console.error('[decidiq] MeetingCalendarView filters failed to load', e)
		}
		await this.load()
	},

	methods: {
		/** @spec openspec/changes/planning-parallel-sessions/specs/meeting-management/spec.md#requirement-req-pps-003-the-calendar-and-the-meetings-list-group-sessions-under-their-evening */
		sessionLabel,

		/**
		 * Local Y-M-D key for a date-ish value, or null when unusable.
		 *
		 * Deliberately LOCAL rather than UTC: a meeting at 23:30 local time must
		 * land on the day the reader sees on the wall, not the next UTC day.
		 *
		 * @param {string|Date} value The scheduled date.
		 * @return {string|null} `YYYY-M-D`, or null when not parseable.
		 *
		 * @spec openspec/changes/configurable-types-domain-model/tasks.md#task-1.23
		 */
		dateKey(value) {
			if (!value) {
				return null
			}
			const date = value instanceof Date ? value : new Date(value)
			if (Number.isNaN(date.getTime())) {
				return null
			}
			return `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}`
		},

		/**
		 * Fetch the visible grid from the server, narrowed by body and audience.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-003-the-calendar-asks-the-server-for-the-visible-month
		 */
		async load() {
			this.loading = true
			try {
				const audience = this.$route?.query?.audience || ''
				const params = calendarParams({
					year: this.year,
					month: this.month,
					audience,
					body: this.$route?.query?.body || '',
					types: this.types,
				})
				const meetings = params === null ? [] : await getMeetings(params)
				this.meetings = withoutAudience(meetings || [], audience, this.types)
			} catch (e) {
				// A swallowed fetch error renders an empty calendar that is
				// indistinguishable from a month with no meetings, so the console
				// line is the only signal there is. Same pattern as the dashboard
				// widgets in src/views/dashboard/widgets/.
				// eslint-disable-next-line no-console
				console.error('[decidiq] MeetingCalendarView load failed', e)
				this.meetings = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Move the grid by whole months.
		 *
		 * @param {number} delta Months to add (negative goes back).
		 * @return {void}
		 *
		 * @spec openspec/changes/configurable-types-domain-model/tasks.md#task-1.23
		 */
		step(delta) {
			const next = new Date(this.year, this.month + delta, 1)
			this.year = next.getFullYear()
			this.month = next.getMonth()
			this.load()
		},

		/**
		 * Write one filter into the address, or drop it when empty.
		 *
		 * @param {string} key The query key.
		 * @param {string} value The value, empty to drop it.
		 * @return {void}
		 *
		 * @spec openspec/specs/activity-calendar/spec.md#requirement-req-acal-002-the-calendar-filters-by-audience-and-by-body
		 */
		setQuery(key, value) {
			const query = { ...(this.$route?.query || {}) }
			if (value) {
				query[key] = value
			} else {
				delete query[key]
			}
			this.$router?.replace({ query }).catch(() => {})
		},

		/**
		 * Return the grid to the current month.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/configurable-types-domain-model/tasks.md#task-1.23
		 */
		goToday() {
			const now = new Date()
			this.year = now.getFullYear()
			this.month = now.getMonth()
			this.load()
		},

		/**
		 * Open a meeting's detail page — the same destination a table row-click
		 * reaches, so the two views navigate identically.
		 *
		 * @param {object} meeting The clicked meeting.
		 * @return {void}
		 *
		 * @spec openspec/changes/configurable-types-domain-model/tasks.md#task-1.23
		 */
		open(meeting) {
			this.$router.push({
				name: 'MeetingDetail',
				params: { id: String(meeting.id) },
			})
		},
	},
}
</script>

<style scoped>
.meeting-calendar {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.meeting-calendar__toolbar {
	display: flex;
	align-items: center;
	gap: 8px;
}

.meeting-calendar__toggle {
	margin-inline-start: auto;
}

.meeting-calendar__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.meeting-calendar__filter {
	min-width: 220px;
}

.meeting-calendar__title {
	margin: 0;
	font-size: 1.1em;
	font-weight: 600;
}

.meeting-calendar__grid {
	display: grid;
	grid-template-columns: repeat(7, minmax(0, 1fr));
	gap: 1px;
	background: var(--color-border, #e0e0e0);
	border: 1px solid var(--color-border, #e0e0e0);
	border-radius: var(--border-radius-large, 8px);
	overflow: hidden;
}

.meeting-calendar__weekday {
	padding: 6px 8px;
	background: var(--color-background-dark, #f5f5f5);
	font-size: 0.85em;
	font-weight: 600;
	text-align: center;
}

.meeting-calendar__cell {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-height: 88px;
	padding: 4px;
	background: var(--color-main-background, #fff);
}

.meeting-calendar__cell--outside {
	background: var(--color-background-hover, #f5f5f5);
}

.meeting-calendar__cell--outside .meeting-calendar__daynum {
	opacity: 0.5;
}

.meeting-calendar__cell--today {
	box-shadow: inset 0 0 0 2px var(--color-primary-element, #0082c9);
}

.meeting-calendar__daynum {
	font-size: 0.8em;
	font-weight: 600;
}

.meeting-calendar__event {
	display: block;
	width: 100%;
	padding: 2px 6px;
	border: none;
	border-radius: var(--border-radius, 4px);
	background: var(--color-primary-element-light, #d5e8f5);
	color: var(--color-main-text, #222);
	font-size: 0.8em;
	text-align: start;
	text-overflow: ellipsis;
	white-space: nowrap;
	overflow: hidden;
	cursor: pointer;
}

.meeting-calendar__event:hover,
.meeting-calendar__event:focus-visible {
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-text, #fff);
}

.meeting-calendar__undated ul {
	list-style: none;
	margin: 0;
	padding: 0;
}

.meeting-calendar__undated button {
	border: none;
	background: none;
	color: var(--color-primary-element, #0082c9);
	cursor: pointer;
	padding: 4px 0;
}

.meeting-calendar__sessions {
	margin: 2px 0 0;
	padding: 0 0 0 8px;
	list-style: none;
	border-inline-start: 2px solid var(--color-primary-element, #00679e);
}

.meeting-calendar__session {
	display: block;
	width: 100%;
	padding: 1px 4px;
	border: none;
	background: transparent;
	color: var(--color-main-text, #222);
	font-size: 0.75em;
	text-align: start;
	text-overflow: ellipsis;
	white-space: nowrap;
	overflow: hidden;
	cursor: pointer;
}

.meeting-calendar__session:hover,
.meeting-calendar__session:focus-visible {
	background: var(--color-background-hover, #f0f0f0);
}
</style>
