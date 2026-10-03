<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 "Send the meeting file to the case system" on the minutes page, offered
 once the minutes are approved and a case system is connected
 (platform-case-system-document-exchange, matrix row plt-24).

 @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
-->
<template>
	<div
		v-if="connected && meetingId"
		class="case-send"
		data-testid="minutes-case-system">
		<h3 class="decidiq-tab__title">
			{{ t('decidiq', 'Case system') }}
		</h3>
		<p v-if="!sendable" class="decidiq-tab__meta">
			{{
				t('decidiq', 'Approve the minutes before sending the meeting file.')
			}}
		</p>
		<NcButton
			:disabled="!sendable || working"
			data-testid="minutes-case-system-send"
			@click="send">
			{{ t('decidiq', 'Send the meeting file to the case system') }}
		</NcButton>
		<p v-if="message" class="decidiq-tab__meta" role="status">
			{{ message }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { NcButton } from '@nextcloud/vue'
import { apiUrl, canSendMeetingFile } from '../utils/caseSystem.js'

export default {
	name: 'CaseSystemSendButton',

	components: { NcButton },

	props: {
		minutes: { type: Object, default: null },
		meetingId: { type: String, default: '' },
	},

	data() {
		return { connected: false, working: false, message: '' }
	},

	computed: {
		/** @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval */
		sendable() {
			return canSendMeetingFile(this.minutes)
		},
	},

	/** @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-001-the-case-system-is-an-integriq-connection */
	async mounted() {
		try {
			const status = await axios.get(apiUrl('/case-system/status'))
			this.connected = status.data?.connected === true
		} catch {
			this.connected = false
		}
	},

	methods: {
		/**
		 * Queue the meeting file for the case system.
		 *
		 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-005-the-meeting-file-goes-back-to-the-case-system-after-approval
		 */
		async send() {
			this.working = true
			this.message = ''
			try {
				await axios.post(
					apiUrl(`/meetings/${this.meetingId}/case-system/send`),
				)
				this.message = this.t(
					'decidiq',
					'The meeting file is being sent. The Case system widget on the meeting page shows each document.',
				)
			} catch (e) {
				this.message =
					e.response?.data?.message
					|| this.t('decidiq', 'The meeting file could not be sent.')
			} finally {
				this.working = false
			}
		},
	},
}
</script>
