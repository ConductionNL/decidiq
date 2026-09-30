<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Case system: every fetch from and send to the case system for this meeting,
 each with its documents and their status, and "Send again" for a send with
 failed documents (platform-case-system-document-exchange, matrix row plt-24).
 A failed document is only sent again when someone presses the button.

 @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
-->
<template>
	<div class="case-system" data-testid="meeting-case-system">
		<NcLoadingIcon v-if="loading" :size="24" />
		<p v-else-if="records.length === 0" class="case-system__empty">
			{{ t('decidiq', 'Nothing was exchanged with the case system yet.') }}
		</p>
		<ul v-else class="case-system__records">
			<li
				v-for="record in records"
				:key="record.id"
				class="case-system__record"
				data-testid="meeting-case-system-record">
				<div class="case-system__head">
					<strong>{{ record.direction === 'fetch' ? t('decidiq', 'Fetched from {case}', { case: record.targetLabel || record.target }) : t('decidiq', 'Sent to {case}', { case: record.targetLabel || record.target || t('decidiq', 'a new case') }) }}</strong>
					<span class="case-system__counts">
						{{ t('decidiq', '{sent} sent, {failed} failed, {pending} waiting', counts(record)) }}
					</span>
					<NcButton
						v-if="sendAgainOffered(record)"
						:disabled="working === record.id"
						data-testid="meeting-case-system-resend"
						@click="sendAgain(record)">
						{{ t('decidiq', 'Send again') }}
					</NcButton>
				</div>
				<ul class="case-system__lines">
					<li v-for="line in record.lines" :key="line.source || line.name" :class="`case-system__line--${line.status}`">
						{{ line.name }}: {{ statusLabel(line.status) }}<template v-if="line.confidential"> ({{ t('decidiq', 'confidential') }})</template><template v-if="line.error"> · {{ line.error }}</template>
					</li>
				</ul>
			</li>
		</ul>
		<p v-if="message" class="case-system__message" role="status">
			{{ message }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import {
	apiUrl,
	canSendAgain,
	lineCounts,
	newestFirst,
	recordsUrl,
} from '../../utils/caseSystem.js'

export default {
	name: 'MeetingCaseSystemTab',

	components: { NcButton, NcLoadingIcon },

	props: {
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return { loading: false, working: '', records: [], message: '' }
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/**
		 * Read the meeting's exchange records.
		 *
		 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
		 */
		async load() {
			if (!this.objectId) return
			this.loading = true
			try {
				const response = await axios.get(recordsUrl(String(this.objectId)))
				this.records = newestFirst(response.data?.results || [])
			} catch (e) {
				this.records = []
				this.message = this.t('decidiq', 'The case system records could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The status counts of a record.
		 *
		 * @param {object} record The record.
		 * @return {object} The counts.
		 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
		 */
		counts(record) {
			return lineCounts(record)
		},

		/**
		 * Whether a record offers Send again.
		 *
		 * @param {object} record The record.
		 * @return {boolean} True for a send with failed lines.
		 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
		 */
		sendAgainOffered(record) {
			return canSendAgain(record)
		},

		/**
		 * A line status as a reader says it.
		 *
		 * @param {string} status pending, sent or failed.
		 * @return {string} The label.
		 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
		 */
		statusLabel(status) {
			return {
				pending: this.t('decidiq', 'waiting'),
				sent: this.t('decidiq', 'sent'),
				failed: this.t('decidiq', 'failed'),
			}[status] || status
		},

		/**
		 * Queue the failed lines of one record to be sent again.
		 *
		 * @param {object} record The record.
		 * @spec openspec/specs/case-system-exchange/spec.md#requirement-req-csdx-006-every-exchange-is-recorded-and-a-failed-document-is-sent-again-on-request
		 */
		async sendAgain(record) {
			this.working = record.id
			this.message = ''
			try {
				await axios.post(apiUrl(`/case-exchange-records/${record.id}/resend`))
				this.message = this.t('decidiq', 'The failed documents will be sent again in a moment.')
			} catch (e) {
				this.message = e.response?.data?.message || this.t('decidiq', 'The documents could not be sent again.')
			} finally {
				this.working = ''
			}
		},
	},
}
</script>

<style scoped>
.case-system__records,
.case-system__lines {
	list-style: none;
	padding: 0;
}

.case-system__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.case-system__counts,
.case-system__empty {
	color: var(--color-text-maxcontrast);
}

.case-system__line--failed {
	color: var(--color-error-text);
}
</style>
