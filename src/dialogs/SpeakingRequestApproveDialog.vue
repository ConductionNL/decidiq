<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Approve a pending speaking request from the secretariat overview
 (inspreekrecht-plenair, REQ-INS-005): optionally the minutes and the order.
 Left empty, the server takes the body's default speaking time and places the
 speaker after the approved ones.

 @spec openspec/changes/inspreekrecht-plenair/specs/inspraak-register/spec.md#requirement-req-ins-005-griffier-moderation-approve-reject-refer
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Approve speaking request')"
		data-testid="speaking-request-approve-dialog"
		@closing="$emit('close')">
		<p v-if="speakerName">
			{{ t('decidiq', 'Speaker: {name}', { name: speakerName }) }}
		</p>
		<NcTextField
			v-model="minutes"
			type="number"
			min="1"
			data-testid="speaking-request-approve-minutes"
			:label="t('decidiq', 'Speaking time in minutes (empty: the body default)')" />
		<NcTextField
			v-model="position"
			type="number"
			min="1"
			data-testid="speaking-request-approve-position"
			:label="t('decidiq', 'Order (empty: after the approved speakers)')" />
		<p v-if="error" class="moderation-dialog__error" role="alert">
			{{ error }}
		</p>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="speaking-request-approve-confirm"
				:disabled="working"
				@click="confirm">
				{{ t('decidiq', 'Approve') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'
import { approveSpeakingRequest } from '../services/speakingRequestApi.js'
import { optionalPositiveInt, runModeration, speakerNameOf } from './speakingRequestModeration.js'

export default {
	name: 'SpeakingRequestApproveDialog',
	components: { NcButton, NcDialog, NcTextField },
	props: {
		object: { type: Object, default: null },
		objectId: { type: [String, Number], default: '' },
	},

	emits: ['close'],
	data() {
		return { minutes: '', position: '', working: false, error: '' }
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
				() => approveSpeakingRequest(String(this.objectId), {
					allottedMinutes: optionalPositiveInt(this.minutes),
					position: optionalPositiveInt(this.position),
				}),
				this.t('decidiq', 'The speaking request could not be approved.'),
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
