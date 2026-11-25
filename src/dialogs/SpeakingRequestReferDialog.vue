<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Refer a pending speaking request to another meeting, and optionally one of
 its agenda items (inspreekrecht-plenair, REQ-INS-005). The request stays
 pending; the server re-targets it and notifies the registrant.

 @spec openspec/changes/archive/2026-11-24-inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Refer speaking request')"
		data-testid="speaking-request-refer-dialog"
		@closing="$emit('close')">
		<p v-if="speakerName">
			{{ t('decidiq', 'Speaker: {name}', { name: speakerName }) }}
		</p>
		<NcSelect
			v-model="meeting"
			:options="meetings"
			label="label"
			:loading="loadingMeetings"
			:inputLabel="t('decidiq', 'Meeting')"
			data-testid="speaking-request-refer-meeting"
			@update:modelValue="loadItems" />
		<NcSelect
			v-model="agendaItem"
			:options="items"
			label="label"
			:disabled="!meeting"
			:inputLabel="t('decidiq', 'Agenda item (empty: the meeting as a whole)')"
			data-testid="speaking-request-refer-item" />
		<p v-if="error" class="moderation-dialog__error" role="alert">
			{{ error }}
		</p>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="speaking-request-refer-confirm"
				:disabled="working || !meeting"
				@click="confirm">
				{{ t('decidiq', 'Refer') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'
import { referSpeakingRequest } from '../services/speakingRequestApi.js'
import { agendaItemOptions, meetingOptions, runModeration, speakerNameOf } from './speakingRequestModeration.js'

export default {
	name: 'SpeakingRequestReferDialog',
	components: { NcButton, NcDialog, NcSelect },
	props: {
		object: { type: Object, default: null },
		objectId: { type: [String, Number], default: '' },
	},

	emits: ['close'],
	data() {
		return {
			meetings: [],
			items: [],
			meeting: null,
			agendaItem: null,
			loadingMeetings: false,
			working: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/changes/archive/2026-11-24-inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer */
		speakerName() {
			return speakerNameOf(this.object)
		},
	},

	/** @spec openspec/changes/archive/2026-11-24-inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer */
	async mounted() {
		this.loadingMeetings = true
		this.meetings = await meetingOptions(this.object?.governanceBody || '', this.object?.meeting || '')
		this.loadingMeetings = false
	},

	methods: {
		/** @spec openspec/changes/archive/2026-11-24-inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer */
		async loadItems() {
			this.agendaItem = null
			this.items = this.meeting ? await agendaItemOptions(this.meeting.id) : []
		},

		/** @spec openspec/changes/archive/2026-11-24-inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer */
		async confirm() {
			this.working = true
			this.error = await runModeration(
				() => referSpeakingRequest(String(this.objectId), this.meeting.id, this.agendaItem?.id || ''),
				this.t('decidiq', 'The speaking request could not be referred.'),
			)
			this.working = false
			if (!this.error) this.$emit('close')
		},
	},
}
</script>

<style scoped>
.moderation-dialog__error {
	color: var(--color-error-text);
}
</style>
