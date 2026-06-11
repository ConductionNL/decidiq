<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Board portal — board detail (members + meetings) (Phase 8.1 portal view).

 Shows a single board's metadata, the BoardMember roster (with role +
 independence status), and a quick links list of upcoming/past
 BoardMeetings. Members and meetings are fetched in parallel.

 @spec openspec/changes/board-meeting-resolutions/tasks.md#task-8.1
 @spec openspec/changes/board-meeting-resolutions/tasks.md#task-8.2
-->
<template>
	<div class="board-detail" data-testid="board-detail">
		<NcLoadingIcon v-if="loading" :size="48" />

		<template v-else-if="board">
			<header class="board-detail__header">
				<NcButton
					type="tertiary"
					data-testid="board-detail-back"
					:aria-label="t('decidesk', 'Back to boards')"
					@click="$router.push({ name: 'BoardList' })">
					← {{ t('decidesk', 'Boards') }}
				</NcButton>
				<h2>{{ board.name }}</h2>
				<dl class="board-detail__meta">
					<div><dt>{{ t('decidesk', 'Type') }}</dt><dd>{{ board.type }}</dd></div>
					<div><dt>{{ t('decidesk', 'Governance model') }}</dt><dd>{{ board.governanceModel }}</dd></div>
					<div><dt>{{ t('decidesk', 'Default language') }}</dt><dd>{{ board.defaultLanguage }}</dd></div>
					<div><dt>{{ t('decidesk', 'Quorum rule') }}</dt><dd>{{ board.quorumRule || '—' }}</dd></div>
				</dl>
			</header>

			<section class="board-detail__members" data-testid="board-detail-members">
				<h3>{{ t('decidesk', 'Members ({n})', { n: members.length }) }}</h3>
				<ul role="list" class="board-detail__list">
					<li v-for="m in members"
						:key="m.id"
						role="listitem"
						class="board-detail__list-item">
						<span class="board-detail__primary">{{ m.persoonKoppeling || m.id }}</span>
						<span class="board-detail__role">{{ m.rol }}</span>
						<span class="board-detail__badge">{{ m.independenceStatus }}</span>
					</li>
				</ul>
			</section>

			<section class="board-detail__meetings" data-testid="board-detail-meetings">
				<header class="board-detail__section-header">
					<h3>{{ t('decidesk', 'Meetings ({n})', { n: meetings.length }) }}</h3>
					<NcButton
						type="primary"
						data-testid="board-detail-create-meeting"
						:aria-label="t('decidesk', 'Schedule a board meeting')"
						@click="showCreateMeetingModal = true">
						+ {{ t('decidesk', 'New meeting') }}
					</NcButton>
				</header>
				<ul role="list" class="board-detail__list">
					<li v-for="meeting in meetings"
						:key="meeting.id"
						role="listitem"
						class="board-detail__list-item">
						<a class="board-detail__link"
							:data-testid="`board-meeting-row-${meeting.id}`"
							@click.prevent="openMeeting(meeting)">
							<span class="board-detail__primary">{{ meeting.title || meeting.meetingDate }}</span>
							<span class="board-detail__role">{{ meeting.meetingType }}</span>
							<span class="board-detail__badge">{{ meeting.status }}</span>
						</a>
					</li>
				</ul>
			</section>
		</template>

		<NcEmptyContent
			v-else
			:name="t('decidesk', 'Board not found')"
			:description="t('decidesk', 'The requested board could not be loaded.')" />

		<BoardMeetingCreateModal
			v-if="showCreateMeetingModal"
			:board-id="id"
			@close="showCreateMeetingModal = false"
			@created="onMeetingCreated" />
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import BoardMeetingCreateModal from '../modals/BoardMeetingCreateModal.vue'

export default {
	name: 'BoardDetail',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		BoardMeetingCreateModal,
	},

	props: {
		id: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: true,
			board: null,
			members: [],
			meetings: [],
			showCreateMeetingModal: false,
		}
	},

	async mounted() {
		await this.fetchAll()
	},

	methods: {
		/**
		 * Fetch board + members + meetings in parallel.
		 *
		 * @return {Promise<void>}
		 */
		async fetchAll() {
			this.loading = true
			try {
				const [boardResp, membersResp, meetingsResp] = await Promise.all([
					fetch(generateUrl(`/apps/decidesk/api/boards/${this.id}`), { headers: { Accept: 'application/json', requesttoken: OC.requestToken } }),
					fetch(generateUrl(`/apps/decidesk/api/boards/${this.id}/members`), { headers: { Accept: 'application/json', requesttoken: OC.requestToken } }),
					fetch(generateUrl(`/apps/decidesk/api/boards/${this.id}/meetings`), { headers: { Accept: 'application/json', requesttoken: OC.requestToken } }),
				])
				const boardData = await boardResp.json()
				this.board = boardData?.board || boardData?.result || null
				const membersData = await membersResp.json()
				this.members = Array.isArray(membersData?.members) ? membersData.members : (membersData?.results || [])
				const meetingsData = await meetingsResp.json()
				this.meetings = Array.isArray(meetingsData?.meetings) ? meetingsData.meetings : (meetingsData?.results || [])
			} catch (e) {
				console.error('[decidesk] BoardDetail fetch failed', e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Navigate to a meeting's detail page.
		 *
		 * @param {object} meeting Meeting row
		 * @return {void}
		 */
		openMeeting(meeting) {
			this.$router.push({ name: 'BoardMeetingDetail', params: { id: String(meeting.id) } })
		},

		/**
		 * Handle a newly-created meeting from the modal.
		 *
		 * @param {object} meeting Created meeting row
		 * @return {void}
		 */
		onMeetingCreated(meeting) {
			if (meeting && meeting.id) {
				this.meetings = [...this.meetings, meeting]
			}
			this.showCreateMeetingModal = false
		},
	},
}
</script>

<style scoped>
.board-detail {
	max-width: 960px;
	margin: 0 auto;
	padding: 16px;
}

.board-detail__header {
	margin-bottom: 24px;
}

.board-detail__meta {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
	gap: 12px;
	margin: 12px 0;
}

.board-detail__meta div {
	display: flex;
	flex-direction: column;
}

.board-detail__meta dt {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #595959);
}

.board-detail__meta dd {
	font-weight: 500;
	margin: 0;
}

.board-detail__section-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.board-detail__list {
	list-style: none;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.board-detail__list-item {
	border: 1px solid var(--color-border, #d0d0d0);
	border-radius: var(--border-radius, 8px);
	padding: 8px 12px;
	background: var(--color-main-background, #fff);
}

.board-detail__link {
	display: grid;
	grid-template-columns: 2fr 1fr 1fr;
	gap: 12px;
	cursor: pointer;
	color: inherit;
}

.board-detail__primary {
	font-weight: 500;
}

.board-detail__role,
.board-detail__badge {
	color: var(--color-text-maxcontrast, #595959);
	font-size: 0.9em;
}
</style>
