<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The public speakers of one place on a meeting's agenda (inspreekrecht-plenair,
 REQ-INS-007): approved speakers as speaking slots in their order with their
 minutes, pending requests after them, marked as pending in text (never by
 colour alone). Rejected requests are not shown; the server leaves them out.
 Shared by the agenda item and the meeting page (meeting-level speakers).

 @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-007-approved-insprekers-render-as-speaking-slots-on-the-agenda
-->
<template>
	<div class="speaking-slots" data-testid="speaking-slots">
		<NcLoadingIcon v-if="loading" :size="24" />
		<p v-else-if="error" class="speaking-slots__error" role="alert">
			{{ error }}
		</p>
		<p v-else-if="!slots.length && !pending.length" class="speaking-slots__empty">
			{{ t('decidiq', 'No public speakers have registered.') }}
		</p>
		<template v-else>
			<ol v-if="slots.length" class="speaking-slots__list" data-testid="speaking-slots-approved">
				<li v-for="slot in slots" :key="slot.id" class="speaking-slots__slot">
					<router-link
						class="speaking-slots__name"
						:to="{ name: 'SpeakingRequestDetail', params: { id: slot.id } }">
						{{ slot.speakerName || t('decidiq', 'Public speaker') }}
					</router-link>
					<span v-if="slot.organisation" class="speaking-slots__organisation">{{ slot.organisation }}</span>
					<span class="speaking-slots__minutes">
						{{ t('decidiq', '{minutes} min', { minutes: slot.minutes || 0 }) }}
					</span>
					<span class="speaking-slots__state">{{ stateLabel(slot.status) }}</span>
					<span v-if="slot.hasContribution" class="speaking-slots__state">
						{{ t('decidiq', 'Written contribution') }}
					</span>
				</li>
			</ol>
			<ul v-if="pending.length" class="speaking-slots__list speaking-slots__list--pending" data-testid="speaking-slots-pending">
				<li v-for="request in pending" :key="request.id" class="speaking-slots__slot speaking-slots__slot--pending">
					<router-link
						class="speaking-slots__name"
						:to="{ name: 'SpeakingRequestDetail', params: { id: request.id } }">
						{{ request.speakerName || t('decidiq', 'Public speaker') }}
					</router-link>
					<span v-if="request.topicText" class="speaking-slots__organisation">{{ request.topicText }}</span>
					<span class="speaking-slots__state">{{ t('decidiq', 'Pending approval') }}</span>
				</li>
			</ul>
		</template>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import { fetchSpeakingSlots } from '../../services/speakingRequestApi.js'
import { slotsFor } from '../../utils/speakingRequests.js'

export default {
	name: 'SpeakingSlotsBlock',
	components: { NcLoadingIcon },
	props: {
		meetingId: { type: String, default: '' },
		// An agenda item id; empty for the meeting's own speakers.
		place: { type: String, default: '' },
	},

	data() {
		return { loading: false, error: '', slots: [], pending: [] }
	},

	watch: {
		meetingId: {
			immediate: true,
			/** @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-007-approved-insprekers-render-as-speaking-slots-on-the-agenda */
			handler() {
				this.load()
			},
		},

		/** @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-007-approved-insprekers-render-as-speaking-slots-on-the-agenda */
		place() {
			this.load()
		},
	},

	methods: {
		/**
		 * Read the meeting's speakers and keep this place's.
		 *
		 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-007-approved-insprekers-render-as-speaking-slots-on-the-agenda
		 */
		async load() {
			this.slots = []
			this.pending = []
			if (!this.meetingId) return
			this.loading = true
			this.error = ''
			try {
				const place = slotsFor(await fetchSpeakingSlots(this.meetingId), this.place)
				this.slots = place.slots
				this.pending = place.pending
			} catch (e) {
				this.error = e.response?.data?.message || this.t('decidiq', 'The public speakers could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The text of a slot's state.
		 *
		 * @param {string} status The request's lifecycle.
		 * @return {string} The label.
		 * @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-agenda-live/spec.md#requirement-req-ins-007-approved-insprekers-render-as-speaking-slots-on-the-agenda
		 */
		stateLabel(status) {
			if (status === 'spoke') return this.t('decidiq', 'Spoke')
			if (status === 'no-show') return this.t('decidiq', 'Did not show up')
			return this.t('decidiq', 'Approved')
		},
	},
}
</script>

<style scoped>
.speaking-slots {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.speaking-slots__list {
	margin: 0;
	padding-inline-start: 20px;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.speaking-slots__list--pending {
	list-style: none;
	padding-inline-start: 0;
	border-top: 1px solid var(--color-border);
	padding-top: 8px;
}

.speaking-slots__slot {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 8px;
}

.speaking-slots__slot--pending {
	color: var(--color-text-maxcontrast);
}

.speaking-slots__name {
	font-weight: bold;
	color: var(--color-main-text);
}

.speaking-slots__organisation,
.speaking-slots__minutes {
	color: var(--color-text-maxcontrast);
}

.speaking-slots__state {
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-pill);
	padding: 0 8px;
	font-size: 0.9em;
}

.speaking-slots__empty {
	color: var(--color-text-maxcontrast);
}

.speaking-slots__error {
	color: var(--color-error-text);
}
</style>
