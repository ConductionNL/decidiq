<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Meeting page widget: the video call of a digital or hybrid meeting
 (meeting-video-call, pla-08). Members get Join video call once a Talk room
 is linked; the chair or secretary creates the room (the body's members are
 invited) or links an existing one. The room is linked through
 OpenRegister's Talk integration, the same link the Integrations page shows.
 An in-person meeting shows nothing.
-->
<template>
	<div
		v-if="shown"
		class="decidiq-tab decidiq-tab--video-call"
		data-testid="meeting-video-call">
		<CnNoteCard
			v-if="error"
			type="error"
			:title="t('decidiq', 'That did not work')">
			{{ error }}
		</CnNoteCard>

		<template v-if="token">
			<p class="decidiq-video-call__hint">
				{{ t('decidiq', 'This meeting has a video call in Talk.') }}
			</p>
			<NcButton
				variant="primary"
				:href="joinUrl"
				target="_blank"
				rel="noopener"
				data-testid="meeting-video-call-join">
				<template #icon>
					<VideoIcon :size="20" />
				</template>
				{{ t('decidiq', 'Join video call') }}
			</NcButton>
		</template>

		<template v-else-if="canManage">
			<p class="decidiq-video-call__hint">
				{{
					t(
						'decidiq',
						'Create a Talk room for this meeting and invite the members of the body, or link a room that already exists.',
					)
				}}
			</p>
			<NcButton
				variant="primary"
				:disabled="busy"
				data-testid="meeting-video-call-create"
				@click="createRoom">
				{{ t('decidiq', 'Create video call') }}
			</NcButton>
			<div class="decidiq-video-call__link">
				<NcTextField
					v-model="linkInput"
					:label="t('decidiq', 'Talk room link')"
					data-testid="meeting-video-call-link-input" />
				<NcButton
					:disabled="busy || !linkToken"
					data-testid="meeting-video-call-link"
					@click="linkRoom">
					{{ t('decidiq', 'Link room') }}
				</NcButton>
			</div>
		</template>

		<p v-else-if="loaded" class="decidiq-video-call__hint">
			{{ t('decidiq', 'The video call has not been set up yet.') }}
		</p>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { NcButton, NcTextField } from '@nextcloud/vue'
import VideoIcon from 'vue-material-design-icons/Video.vue'
import { canManageAgenda } from '../../services/agendaRules.js'
import {
	hasVideoCall,
	joinPath,
	memberUids,
	roomToken,
	talkLinksPath,
	tokenFromInput,
} from '../../utils/videoCall.js'
import { objectIdLoader } from './objectIdLoader.js'
import { ensureRelationType } from './useRelationStore.js'

/**
 * The headers of a JSON request to decidiq or Talk, with the CSRF token.
 *
 * @return {object} The headers.
 */
function talkHeaders() {
	return {
		'Content-Type': 'application/json',
		Accept: 'application/json',
		'OCS-APIRequest': 'true',
		requesttoken: OC.requestToken,
	}
}

/**
 * Why a request was refused: the server's message or error, else the status.
 *
 * @param {Response} response The refused response.
 * @param {object} payload Its body.
 * @return {string} The message.
 */
function refusalMessage(response, payload) {
	return payload?.message || payload?.error || response.statusText
}

/**
 * The governance body a meeting belongs to, by id or reference.
 *
 * @param {object|null} meeting The meeting.
 * @return {string} The body id, or ''.
 */
function meetingBodyId(meeting) {
	return meeting?.governanceBody?.id || meeting?.governanceBody || ''
}

export default {
	name: 'MeetingVideoCallTab',
	components: { CnNoteCard, NcButton, NcTextField, VideoIcon },

	/** @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call */
	mixins: [objectIdLoader],

	data() {
		return {
			meeting: null,
			token: '',
			myRoles: null,
			loaded: false,
			busy: false,
			error: '',
			linkInput: '',
		}
	},

	computed: {
		/** @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call */
		shown() {
			return hasVideoCall(this.meeting)
		},

		/** @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call */
		canManage() {
			return canManageAgenda(this.myRoles)
		},

		/** @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call */
		joinUrl() {
			return generateUrl(joinPath(this.token))
		},

		/** @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call */
		linkToken() {
			return tokenFromInput(this.linkInput)
		},
	},

	methods: {
		/**
		 * A JSON request with the CSRF token.
		 *
		 * @param {string} url The absolute or app URL
		 * @param {object} options fetch options
		 * @return {Promise<object>} The answer
		 * @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call
		 */
		async request(url, options = {}) {
			const response = await fetch(url, { ...options, headers: talkHeaders() })
			const payload = await response.json().catch(() => ({}))
			if (!response.ok) {
				throw new Error(refusalMessage(response, payload))
			}
			return payload
		},

		/** @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call */
		async load() {
			this.loaded = false
			if (!this.objectId) return
			try {
				this.meeting = await ensureRelationType('meeting').fetchObject(
					'meeting',
					String(this.objectId),
				)
			} catch {
				this.meeting = null
			}
			if (!this.shown) return
			await Promise.all([this.loadRoom(), this.loadMyRoles()])
			this.loaded = true
		},

		/** @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call */
		async loadRoom() {
			try {
				const data = await this.request(
					generateUrl(talkLinksPath(String(this.objectId))),
				)
				this.token = roomToken(
					data.results || data.items || (Array.isArray(data) ? data : []),
				)
			} catch {
				this.token = ''
			}
		},

		/** @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call */
		async loadMyRoles() {
			try {
				this.myRoles = await this.request(
					generateUrl(
						`/apps/decidiq/api/meetings/${this.objectId}/my-roles`,
					),
				)
			} catch {
				// Fail closed: without an answer only Join shows.
				this.myRoles = null
			}
		},

		/**
		 * Create the Talk room linked to the meeting, then invite the body's
		 * current members (the creator is in the room already).
		 *
		 * @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call
		 */
		async createRoom() {
			this.busy = true
			this.error = ''
			try {
				const token = await this.newRoomToken()
				if (token) await this.inviteMembers(token)
				await this.loadRoom()
			} catch (e) {
				this.error =
					e?.message
					|| this.t('decidiq', 'The video call could not be created.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Create the Talk room linked to the meeting, named after it.
		 *
		 * @return {Promise<string>} The new room's token, or ''.
		 * @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call
		 */
		async newRoomToken() {
			const link = await this.request(
				generateUrl(`${talkLinksPath(String(this.objectId))}/new`),
				{
					method: 'POST',
					body: JSON.stringify({
						roomName: this.meeting?.title || this.t('decidiq', 'Meeting'),
						roomType: 2,
					}),
				},
			)
			return roomToken([link?.link || link])
		},

		/**
		 * @param {string} token The room token
		 * @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call
		 */
		async inviteMembers(token) {
			const bodyId = meetingBodyId(this.meeting)
			if (!bodyId) return
			const participants = await ensureRelationType(
				'participant',
			).fetchCollection('participant', { _limit: 500 })
			for (const uid of memberUids(participants, bodyId)) {
				await this.addParticipant(token, uid)
			}
		},

		/**
		 * Add one member to the Talk room; one who cannot be added does not
		 * stop the others.
		 *
		 * @param {string} token The room token
		 * @param {string} uid The member's Nextcloud user id
		 * @return {Promise<void>}
		 * @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call
		 */
		async addParticipant(token, uid) {
			try {
				await this.request(
					generateOcsUrl(`apps/spreed/api/v4/room/${token}/participants`),
					{
						method: 'POST',
						body: JSON.stringify({ newParticipant: uid, source: 'users' }),
					},
				)
			} catch {
				// One member who cannot be added does not stop the others.
			}
		},

		/** @spec openspec/specs/digital-meetings-and-recurrence/spec.md#requirement-req-mvc-001-a-digital-or-hybrid-meeting-has-its-video-call */
		async linkRoom() {
			this.busy = true
			this.error = ''
			try {
				await this.request(
					generateUrl(talkLinksPath(String(this.objectId))),
					{
						method: 'POST',
						body: JSON.stringify({ roomToken: this.linkToken }),
					},
				)
				this.linkInput = ''
				await this.loadRoom()
			} catch (e) {
				this.error =
					e?.message || this.t('decidiq', 'The room could not be linked.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.decidiq-tab--video-call {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
	padding: var(--default-grid-baseline);
}

.decidiq-video-call__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.decidiq-video-call__link {
	display: flex;
	align-items: flex-end;
	gap: var(--default-grid-baseline);
	width: 100%;
}
</style>
