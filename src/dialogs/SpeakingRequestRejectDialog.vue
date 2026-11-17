<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Reject a pending speaking request from the secretariat overview
 (inspreekrecht-plenair, REQ-INS-005). The reason is required: the server
 refuses a rejection without one, and this dialog asks for it first.

 @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Reject speaking request')"
		data-testid="speaking-request-reject-dialog"
		@closing="$emit('close')">
		<p v-if="speakerName">
			{{ t('decidiq', 'Speaker: {name}', { name: speakerName }) }}
		</p>
		<NcTextArea
			v-model="reason"
			data-testid="speaking-request-reject-reason"
			:label="t('decidiq', 'Rejection reason')"
			resize="vertical" />
		<p v-if="error" class="moderation-dialog__error" role="alert">
			{{ error }}
		</p>
		<template #actions>
			<NcButton
				variant="error"
				data-testid="speaking-request-reject-confirm"
				:disabled="working || !reason.trim()"
				@click="confirm">
				{{ t('decidiq', 'Reject') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextArea } from '@nextcloud/vue'
import { rejectSpeakingRequest } from '../services/speakingRequestApi.js'
import { runModeration, speakerNameOf } from './speakingRequestModeration.js'

export default {
	name: 'SpeakingRequestRejectDialog',
	components: { NcButton, NcDialog, NcTextArea },
	props: {
		object: { type: Object, default: null },
		objectId: { type: [String, Number], default: '' },
	},

	emits: ['close'],
	data() {
		return { reason: '', working: false, error: '' }
	},

	computed: {
		/** @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer */
		speakerName() {
			return speakerNameOf(this.object)
		},
	},

	methods: {
		/** @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer */
		async confirm() {
			this.working = true
			this.error = await runModeration(
				() => rejectSpeakingRequest(String(this.objectId), this.reason.trim()),
				this.t('decidiq', 'The speaking request could not be rejected.'),
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
