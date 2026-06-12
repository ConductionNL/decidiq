<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Board portal — board meeting detail (Phase 8.1 portal view + 8.3 secretary).

 Three sections per meeting: agenda items, resolutions opened in the
 meeting, and minutes versions. Each list is fetched lazily and renders
 a quick navigation to its dedicated detail surface (ResolutionDetail
 for resolutions; minutes link to the existing decidesk Minutes view).

 @spec openspec/changes/board-meeting-resolutions/tasks.md#task-8.1
 @spec openspec/changes/board-meeting-resolutions/tasks.md#task-8.3
-->
<template>
	<div class="meeting-detail" data-testid="board-meeting-detail">
		<NcLoadingIcon v-if="loading" :size="48" />

		<template v-else-if="meeting">
			<header class="meeting-detail__header">
				<NcButton
					type="tertiary"
					data-testid="board-meeting-detail-back"
					:aria-label="t('decidesk', 'Back to meetings')"
					@click="$router.push({ name: 'BoardMeetingList' })">
					← {{ t('decidesk', 'Meetings') }}
				</NcButton>
				<h2>{{ meeting.title || meeting.meetingDate }}</h2>
				<dl class="meeting-detail__meta">
					<div><dt>{{ t('decidesk', 'Status') }}</dt><dd>{{ meeting.status }}</dd></div>
					<div><dt>{{ t('decidesk', 'Type') }}</dt><dd>{{ meeting.meetingType }}</dd></div>
					<div><dt>{{ t('decidesk', 'Format') }}</dt><dd>{{ meeting.format }}</dd></div>
					<div><dt>{{ t('decidesk', 'Language') }}</dt><dd>{{ meeting.language }}</dd></div>
					<div><dt>{{ t('decidesk', 'Quorum required') }}</dt><dd>{{ meeting.quorumRequired || '—' }}</dd></div>
				</dl>

				<!-- Statutory convocation deadline warning (BW 2:225 / BW 2:38) -->
				<NcNoteCard
					v-if="canSendNotice && deadlineInfo.level !== 'ok' && deadlineInfo.level !== 'unknown'"
					:type="deadlineInfo.level === 'overdue' ? 'error' : 'warning'"
					data-testid="board-meeting-deadline-warning">
					<template v-if="deadlineInfo.level === 'overdue'">
						{{ t('decidesk', 'The statutory notice deadline ({deadline}) has already passed.', { deadline: deadlineInfo.deadline }) }}
					</template>
					<template v-else>
						{{ t('decidesk', 'The statutory notice deadline ({deadline}) is {n} day(s) away.', { deadline: deadlineInfo.deadline, n: deadlineInfo.daysUntilDeadline }) }}
					</template>
				</NcNoteCard>

				<!-- Server-side warnings returned by the send-notice call -->
				<NcNoteCard
					v-for="(warning, i) in sendWarnings"
					:key="`warning-${i}`"
					type="warning"
					data-testid="board-meeting-send-warning">
					{{ warning }}
				</NcNoteCard>

				<div class="meeting-detail__actions">
					<NcButton
						v-if="canSendNotice"
						type="primary"
						data-testid="board-meeting-send-notice"
						:loading="actionInProgress"
						@click="sendNotice">
						{{ t('decidesk', 'Send notice') }}
					</NcButton>
				</div>

				<!-- Per-recipient convocation delivery tracking -->
				<section
					v-if="deliveries.length > 0"
					class="meeting-detail__deliveries"
					data-testid="board-meeting-deliveries">
					<h3>{{ t('decidesk', 'Notice deliveries ({n})', { n: deliveries.length }) }}</h3>
					<table class="meeting-detail__delivery-table">
						<thead>
							<tr>
								<th>{{ t('decidesk', 'Recipient') }}</th>
								<th>{{ t('decidesk', 'Role') }}</th>
								<th>{{ t('decidesk', 'Channel') }}</th>
								<th>{{ t('decidesk', 'Status') }}</th>
								<th>{{ t('decidesk', 'Sent at') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="delivery in deliveries" :key="delivery.recipient">
								<td>{{ delivery.displayName || delivery.recipient }}</td>
								<td>{{ delivery.role || '—' }}</td>
								<td>{{ delivery.channel }}</td>
								<td><span class="meeting-detail__badge">{{ delivery.status }}</span></td>
								<td>{{ delivery.sentAt }}</td>
							</tr>
						</tbody>
					</table>
				</section>
			</header>

			<section class="meeting-detail__agenda" data-testid="board-meeting-agenda">
				<h3>{{ t('decidesk', 'Agenda items ({n})', { n: agendaItems.length }) }}</h3>
				<ol class="meeting-detail__list" data-testid="board-meeting-agenda-list">
					<li v-for="item in agendaItems"
						:key="item.id"
						class="meeting-detail__list-item">
						{{ item.title }}
					</li>
				</ol>
			</section>

			<section class="meeting-detail__resolutions" data-testid="board-meeting-resolutions">
				<h3>{{ t('decidesk', 'Resolutions ({n})', { n: resolutions.length }) }}</h3>
				<ul role="list" class="meeting-detail__list">
					<li v-for="r in resolutions"
						:key="r.id"
						role="listitem"
						class="meeting-detail__list-item">
						<a class="meeting-detail__link"
							:data-testid="`board-resolution-row-${r.id}`"
							@click.prevent="openResolution(r)">
							<span>{{ r.resolutionNumber }} — {{ r.title }}</span>
							<span class="meeting-detail__badge">{{ r.status }}</span>
						</a>
					</li>
				</ul>
			</section>

			<section class="meeting-detail__minutes" data-testid="board-meeting-minutes">
				<h3>{{ t('decidesk', 'Minutes ({n})', { n: minutes.length }) }}</h3>
				<ul role="list" class="meeting-detail__list">
					<li v-for="m in minutes"
						:key="m.id"
						role="listitem"
						class="meeting-detail__list-item">
						<span>{{ m.language }} — {{ m.version }}</span>
						<span class="meeting-detail__badge">{{ m.eidasSignatureLevel || '—' }}</span>
					</li>
				</ul>
			</section>
		</template>

		<NcEmptyContent
			v-else
			:name="t('decidesk', 'Meeting not found')"
			:description="t('decidesk', 'The requested board meeting could not be loaded.')" />
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import { getNoticeDeadlineInfo } from '../services/noticeRules.js'

export default {
	name: 'BoardMeetingDetail',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
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
			meeting: null,
			agendaItems: [],
			resolutions: [],
			minutes: [],
			actionInProgress: false,
			sendWarnings: [],
		}
	},

	computed: {
		/**
		 * Notice can be sent from "scheduled" only (mirrors BoardMeetingService::TRANSITIONS).
		 *
		 * @return {boolean} True when the "send notice" action is allowed.
		 */
		canSendNotice() {
			return this.meeting && this.meeting.status === 'scheduled'
		},

		/**
		 * Pre-send statutory deadline hint (mirror of
		 * BoardMeetingService::getNoticeDeadlineInfo — the server is authoritative).
		 *
		 * @spec openspec/specs/meeting-management/spec.md
		 * @return {object} `{deadline, daysUntilDeadline, level}`.
		 */
		deadlineInfo() {
			return getNoticeDeadlineInfo(this.meeting || {})
		},

		/**
		 * Per-recipient convocation delivery entries recorded at send time.
		 *
		 * @spec openspec/specs/meeting-management/spec.md
		 * @return {Array<object>} Delivery entries (possibly empty).
		 */
		deliveries() {
			return Array.isArray(this.meeting?.noticeDeliveries) ? this.meeting.noticeDeliveries : []
		},
	},

	async mounted() {
		await this.fetchAll()
	},

	methods: {
		/**
		 * Fetch the meeting + agenda + resolutions + minutes in parallel,
		 * with an OR object-API fallback for the meeting itself.
		 *
		 * @spec openspec/specs/meeting-management/spec.md
		 * @return {Promise<void>}
		 */
		async fetchAll() {
			this.loading = true
			try {
				const [meetingResp, agendaResp, resolutionsResp, minutesResp] = await Promise.all([
					fetch(generateUrl(`/apps/decidesk/api/board-meetings/${this.id}`), { headers: { Accept: 'application/json', requesttoken: OC.requestToken } }),
					fetch(generateUrl(`/apps/decidesk/api/board-meetings/${this.id}/agenda`), { headers: { Accept: 'application/json', requesttoken: OC.requestToken } }),
					fetch(generateUrl(`/apps/decidesk/api/board-meetings/${this.id}/resolutions`), { headers: { Accept: 'application/json', requesttoken: OC.requestToken } }),
					fetch(generateUrl(`/apps/decidesk/api/board-meetings/${this.id}/minutes`), { headers: { Accept: 'application/json', requesttoken: OC.requestToken } }),
				])
				if (meetingResp.ok) {
					const meetingData = await meetingResp.json()
					this.meeting = meetingData?.meeting || meetingData?.result || null
				}
				if (!this.meeting) {
					// Fallback: the dedicated GET route is not registered — read
					// the board-meeting straight from OpenRegister's object API
					// (RBAC is enforced server-side by OpenRegister).
					this.meeting = await this.fetchMeetingFromObjectApi()
				}
				const agendaData = await agendaResp.json().catch(() => null)
				this.agendaItems = Array.isArray(agendaData?.items) ? agendaData.items : (agendaData?.results || [])
				const resolutionsData = await resolutionsResp.json().catch(() => null)
				this.resolutions = Array.isArray(resolutionsData?.resolutions) ? resolutionsData.resolutions : (resolutionsData?.results || [])
				const minutesData = await minutesResp.json().catch(() => null)
				this.minutes = Array.isArray(minutesData?.minutes) ? minutesData.minutes : (minutesData?.results || [])
			} catch (e) {
				console.error('[decidesk] BoardMeetingDetail fetch failed', e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * OpenRegister object-API fallback for the board-meeting fetch.
		 *
		 * @spec openspec/specs/meeting-management/spec.md
		 * @return {Promise<object|null>} The board-meeting payload, or null.
		 */
		async fetchMeetingFromObjectApi() {
			try {
				const response = await fetch(
					generateUrl(`/apps/openregister/api/objects/decidesk/board-meeting/${this.id}`),
					{ headers: { Accept: 'application/json', requesttoken: OC.requestToken } },
				)
				if (!response.ok) return null
				return await response.json()
			} catch (e) {
				console.error('[decidesk] board-meeting object-API fallback failed', e)
				return null
			}
		},

		/**
		 * Route to a resolution detail page.
		 *
		 * @param {object} resolution Resolution row
		 * @return {void}
		 */
		openResolution(resolution) {
			this.$router.push({ name: 'ResolutionDetail', params: { id: String(resolution.id) } })
		},

		/**
		 * Trigger the send-notice lifecycle action via the BoardMeetingController.
		 * Records per-recipient deliveries server-side and surfaces statutory
		 * deadline warnings from the response.
		 *
		 * @spec openspec/specs/meeting-management/spec.md
		 * @return {Promise<void>}
		 */
		async sendNotice() {
			this.actionInProgress = true
			this.sendWarnings = []
			try {
				const response = await fetch(
					generateUrl(`/apps/decidesk/api/board-meetings/${this.id}/send-notice`),
					{
						method: 'POST',
						headers: { Accept: 'application/json', 'Content-Type': 'application/json', requesttoken: OC.requestToken },
					},
				)
				const payload = await response.json()
				if (payload?.success && payload?.meeting) {
					this.meeting = payload.meeting
				}
				if (Array.isArray(payload?.warnings)) {
					this.sendWarnings = payload.warnings
				}
			} catch (e) {
				console.error('[decidesk] send-notice failed', e)
			} finally {
				this.actionInProgress = false
			}
		},
	},
}
</script>

<style scoped>
.meeting-detail {
	max-width: 960px;
	margin: 0 auto;
	padding: 16px;
}

.meeting-detail__meta {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 12px;
	margin: 12px 0;
}

.meeting-detail__meta dt {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #595959);
}

.meeting-detail__meta dd {
	font-weight: 500;
	margin: 0;
}

.meeting-detail__actions {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.meeting-detail__list {
	list-style: none;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.meeting-detail__list-item {
	padding: 8px 12px;
	border: 1px solid var(--color-border, #d0d0d0);
	border-radius: var(--border-radius, 8px);
	background: var(--color-main-background, #fff);
}

.meeting-detail__link {
	display: flex;
	justify-content: space-between;
	align-items: center;
	cursor: pointer;
	color: inherit;
}

.meeting-detail__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	background: var(--color-primary-light, #eaf3fb);
	color: var(--color-primary-text-dark, #1a4a72);
	font-size: 0.85em;
}

.meeting-detail__delivery-table {
	width: 100%;
	border-collapse: collapse;
	margin: 8px 0 16px;
}

.meeting-detail__delivery-table th,
.meeting-detail__delivery-table td {
	text-align: start;
	padding: 6px 10px;
	border-bottom: 1px solid var(--color-border, #d0d0d0);
}

.meeting-detail__delivery-table th {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #595959);
}
</style>
