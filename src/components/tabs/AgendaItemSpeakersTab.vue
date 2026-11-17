<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Public speakers on this agenda item page (inspreekrecht-plenair, REQ-INS-007):
 the approved speaking slots in order and the pending requests, read for the
 item's meeting.

 @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-007-approved-insprekers-render-as-speaking-slots-on-the-agenda
-->
<template>
	<div data-testid="agenda-item-speakers">
		<p v-if="error" class="agenda-speakers__error" role="alert">
			{{ error }}
		</p>
		<SpeakingSlotsBlock v-else-if="meetingId" :meetingId="meetingId" :place="String(objectId)" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import SpeakingSlotsBlock from './SpeakingSlotsBlock.vue'
import { objectIdLoader } from './objectIdLoader.js'

export default {
	name: 'AgendaItemSpeakersTab',
	components: { SpeakingSlotsBlock },
	mixins: [objectIdLoader],
	data() {
		return { meetingId: '', error: '' }
	},

	methods: {
		/**
		 * Read the agenda item's meeting.
		 *
		 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-007-approved-insprekers-render-as-speaking-slots-on-the-agenda
		 */
		async load() {
			this.meetingId = ''
			this.error = ''
			if (!this.objectId) return
			try {
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/objects/{register}/{schema}/{id}', { register: 'decidiq', schema: 'agenda-item', id: String(this.objectId) }),
				)
				this.meetingId = typeof data?.meeting === 'string' ? data.meeting : (data?.meeting?.id || '')
			} catch (e) {
				this.error = e.response?.data?.message || this.t('decidiq', 'The public speakers could not be read.')
			}
		},
	},
}
</script>

<style scoped>
.agenda-speakers__error {
	color: var(--color-error-text);
}
</style>
