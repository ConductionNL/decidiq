<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Room system: what the room's conference and voting system sent for this
 meeting, rejected messages first, each with its seat, member, status and
 reason, and "Apply again" on a rejected one once the clerk fixed the cause
 (live-room-conference-system-link). While no room system is linked it says so.

 @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-006-a-message-that-cannot-be-applied-is-kept-and-can-be-applied-again
-->
<template>
	<div class="room-system" data-testid="meeting-room-system">
		<NcLoadingIcon v-if="loading" :size="24" />
		<p
			v-else-if="!connected"
			class="room-system__empty"
			data-testid="meeting-room-system-not-connected">
			{{ t('decidiq', 'No room system is connected') }}
		</p>
		<p v-else-if="messages.length === 0" class="room-system__empty">
			{{ t('decidiq', 'The room system sent nothing for this meeting yet.') }}
		</p>
		<ul v-else class="room-system__messages">
			<li
				v-for="item in messages"
				:key="item.id"
				class="room-system__message" :class="[`room-system__message--${item.status}`]"
				data-testid="meeting-room-system-message">
				<strong>{{ kindLabel(item.kind) }}</strong>
				<span>{{ t('decidiq', 'Seat {seat}', { seat: item.seat || '?' }) }}</span>
				<span>{{ statusLabel(item.status) }}</span>
				<span v-if="item.rejectReason" class="room-system__reason">{{ item.rejectReason }}</span>
				<NcButton
					v-if="item.status === 'rejected'"
					:disabled="working === item.id"
					data-testid="meeting-room-system-apply"
					@click="applyAgain(item)">
					{{ t('decidiq', 'Apply again') }}
				</NcButton>
			</li>
		</ul>
		<p v-if="notice" class="room-system__notice" role="status">
			{{ notice }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { apiUrl } from '../../utils/caseSystem.js'
import { objectIdLoader } from './objectIdLoader.js'

export default {
	name: 'MeetingRoomSystemTab',

	components: { NcButton, NcLoadingIcon },

	/** @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-006-a-message-that-cannot-be-applied-is-kept-and-can-be-applied-again */
	mixins: [objectIdLoader],

	data() {
		return { loading: false, connected: true, working: '', messages: [], notice: '' }
	},

	methods: {
		/**
		 * Read whether a room system is connected and the meeting's messages.
		 *
		 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-001-the-room-system-is-an-integriq-connection
		 */
		async load() {
			if (!this.objectId) return
			this.loading = true
			try {
				const response = await axios.get(apiUrl(`/meetings/${this.objectId}/room-system`))
				this.connected = response.data?.connected !== false
				this.messages = response.data?.results || []
			} catch {
				this.messages = []
				this.notice = this.t('decidiq', 'The room system messages could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * A message kind as a reader says it.
		 *
		 * @param {string} kind speech or vote.
		 * @return {string} The label.
		 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-006-a-message-that-cannot-be-applied-is-kept-and-can-be-applied-again
		 */
		kindLabel(kind) {
			return (
				{
					speech: this.t('decidiq', 'Speech'),
					vote: this.t('decidiq', 'Vote'),
				}[kind] || kind
			)
		},

		/**
		 * A message status as a reader says it.
		 *
		 * @param {string} status received, applied or rejected.
		 * @return {string} The label.
		 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-006-a-message-that-cannot-be-applied-is-kept-and-can-be-applied-again
		 */
		statusLabel(status) {
			return (
				{
					received: this.t('decidiq', 'received'),
					applied: this.t('decidiq', 'applied'),
					rejected: this.t('decidiq', 'rejected'),
				}[status] || status
			)
		},

		/**
		 * Apply a rejected message again and show what came of it.
		 *
		 * @param {object} item The message.
		 * @spec openspec/changes/live-room-conference-system-link/specs/room-system-link/spec.md#requirement-req-rcsl-006-a-message-that-cannot-be-applied-is-kept-and-can-be-applied-again
		 */
		async applyAgain(item) {
			this.working = item.id
			this.notice = ''
			try {
				const response = await axios.post(apiUrl(`/room-system-messages/${item.id}/apply`))
				const stored = response.data || {}
				this.notice = stored.status === 'applied'
					? this.t('decidiq', 'The message is applied.')
					: (stored.rejectReason || this.t('decidiq', 'The message could not be applied.'))
				await this.load()
			} catch (e) {
				this.notice = e.response?.data?.message
					|| this.t('decidiq', 'The message could not be applied.')
			} finally {
				this.working = ''
			}
		},
	},
}
</script>

<style scoped>
.room-system__messages {
	list-style: none;
	padding: 0;
}

.room-system__message {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}

.room-system__empty,
.room-system__reason {
	color: var(--color-text-maxcontrast);
}

.room-system__message--rejected .room-system__reason {
	color: var(--color-error-text);
}
</style>
