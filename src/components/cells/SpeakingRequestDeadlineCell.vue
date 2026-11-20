<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The registration deadline of a pending speaking request on the secretariat
 overview (inspreekrecht-plenair, REQ-INS-010): open, closing soon or closed,
 computed from the meeting start and the body's registration deadline. It is
 a warning only, never a gate. Text carries the state, so it does not rely on
 colour. Other requests show no warning.

 @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-010-griffie-inspraak-overview-with-deadline-warnings-and-notifications
-->
<template>
	<span
		v-if="label"
		class="speaking-deadline"
		:class="'speaking-deadline--' + state"
		data-testid="speaking-request-deadline">
		<span v-if="state !== 'open'" aria-hidden="true">⚠ </span>{{ label }}
	</span>
</template>

<script>
import { deadlineHoursOf, meetingStartOf } from '../../services/speakingRequestApi.js'
import { deadlineState } from '../../utils/speakingRequests.js'

export default {
	name: 'SpeakingRequestDeadlineCell',
	props: {
		row: { type: Object, default: null },
		value: { type: [Boolean, String, Number, Object, Array], default: null },
	},

	data() {
		return { state: 'unknown' }
	},

	computed: {
		/** @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-010-griffie-inspraak-overview-with-deadline-warnings-and-notifications */
		label() {
			if (this.state === 'open') return this.t('decidiq', 'Registration open')
			if (this.state === 'closing') return this.t('decidiq', 'Registration closes soon')
			if (this.state === 'closed') return this.t('decidiq', 'Registration closed')
			return ''
		},
	},

	watch: {
		row: { immediate: true, handler() { this.load() } },
	},

	methods: {
		/** @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-010-griffie-inspraak-overview-with-deadline-warnings-and-notifications */
		async load() {
			this.state = 'unknown'
			const row = this.row
			if (!row || row.lifecycle !== 'registered' || !row.meeting || !row.governanceBody) return
			const [start, hours] = await Promise.all([meetingStartOf(row.meeting), deadlineHoursOf(row.governanceBody)])
			if (row !== this.row) return
			this.state = deadlineState(start, hours, Date.now()).state
		},
	},
}
</script>

<style scoped>
.speaking-deadline {
	white-space: nowrap;
}

.speaking-deadline--closing,
.speaking-deadline--closed {
	font-weight: bold;
	color: var(--color-warning-text);
}
</style>
