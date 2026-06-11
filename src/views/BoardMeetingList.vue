<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Board portal — fleet-wide board-meetings index (Phase 8.3 secretary view).

 Flat list of every BoardMeeting across every board, with status badge
 and meeting-type. Each row links to BoardMeetingDetail.

 @spec openspec/changes/board-meeting-resolutions/tasks.md#task-8.3
-->
<template>
	<div class="meeting-list" data-testid="board-meeting-list">
		<header class="meeting-list__header">
			<h2>{{ t('decidesk', 'Board meetings') }}</h2>
		</header>

		<NcLoadingIcon v-if="loading" :size="48" />

		<NcEmptyContent
			v-else-if="meetings.length === 0"
			:name="t('decidesk', 'No board meetings yet')"
			:description="t('decidesk', 'Schedule a meeting from a board\'s detail page.')"
			data-testid="board-meeting-list-empty" />

		<table v-else class="meeting-list__table" data-testid="board-meeting-list-table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('decidesk', 'Title') }}
					</th>
					<th scope="col">
						{{ t('decidesk', 'Type') }}
					</th>
					<th scope="col">
						{{ t('decidesk', 'Date') }}
					</th>
					<th scope="col">
						{{ t('decidesk', 'Status') }}
					</th>
					<th scope="col">
						{{ t('decidesk', 'Language') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="meeting in meetings"
					:key="meeting.id"
					:data-testid="`board-meeting-row-${meeting.id}`"
					class="meeting-list__row"
					@click="openMeeting(meeting)">
					<td>{{ meeting.title || meeting.meetingDate }}</td>
					<td>{{ meeting.meetingType }}</td>
					<td>{{ meeting.meetingDate }}</td>
					<td><span class="meeting-list__badge">{{ meeting.status }}</span></td>
					<td>{{ meeting.language }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

export default {
	name: 'BoardMeetingList',

	components: {
		NcEmptyContent,
		NcLoadingIcon,
	},

	data() {
		return {
			loading: true,
			meetings: [],
		}
	},

	async mounted() {
		await this.fetchMeetings()
	},

	methods: {
		/**
		 * Fetch the flat list of all board meetings.
		 *
		 * @return {Promise<void>}
		 */
		async fetchMeetings() {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl('/apps/decidesk/api/board-meetings'),
					{ headers: { Accept: 'application/json', requesttoken: OC.requestToken } },
				)
				const payload = await response.json()
				this.meetings = Array.isArray(payload?.meetings) ? payload.meetings : (payload?.results || [])
			} catch (e) {
				console.error('[decidesk] BoardMeetingList fetch failed', e)
				this.meetings = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Navigate to meeting detail.
		 *
		 * @param {object} meeting Meeting row
		 * @return {void}
		 */
		openMeeting(meeting) {
			this.$router.push({ name: 'BoardMeetingDetail', params: { id: String(meeting.id) } })
		},
	},
}
</script>

<style scoped>
.meeting-list {
	max-width: 1080px;
	margin: 0 auto;
	padding: 16px;
}

.meeting-list__table {
	width: 100%;
	border-collapse: collapse;
}

.meeting-list__table th,
.meeting-list__table td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border, #e0e0e0);
	text-align: left;
}

.meeting-list__row {
	cursor: pointer;
}

.meeting-list__row:hover {
	background: var(--color-background-hover, #f5f5f5);
}

.meeting-list__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	background: var(--color-primary-light, #eaf3fb);
	color: var(--color-primary-text-dark, #1a4a72);
	font-size: 0.85em;
}
</style>
