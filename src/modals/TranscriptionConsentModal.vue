<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Consent confirmation dialog for attaching a meeting recording source
 (meeting-transcription-ai-minutes). Recording requires a chair/secretary
 consent confirmation (participants informed, AVG/GDPR) before any
 transcription source is attached. The server enforces the same precondition;
 this dialog captures the explicit confirmation.

 @spec openspec/specs/meeting-transcription/spec.md
-->
<template>
	<NcDialog
		:name="t('decidesk', 'Confirm recording consent')"
		data-testid="transcription-consent-modal"
		@closing="$emit('close')">
		<template #default>
			<p>
				{{ t('decidesk', 'Attaching a recording for transcription requires confirming that all participants were informed that the meeting was recorded (AVG/GDPR). The recording and raw transcript stay restricted to this governance body and are never published.') }}
			</p>
			<NcCheckboxRadioSwitch
				v-model="confirmed"
				data-testid="transcription-consent-checkbox"
				type="checkbox">
				{{ t('decidesk', 'I confirm participants were informed of the recording.') }}
			</NcCheckboxRadioSwitch>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="transcription-consent-confirm"
				:disabled="!confirmed"
				@click="$emit('confirm')">
				{{ t('decidesk', 'Attach with consent') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ t('decidesk', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog } from '@nextcloud/vue'

export default {
	name: 'TranscriptionConsentModal',
	components: { NcButton, NcCheckboxRadioSwitch, NcDialog },
	data() {
		return {
			confirmed: false,
		}
	},
}
</script>
