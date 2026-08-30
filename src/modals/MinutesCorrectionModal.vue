<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Correction-suggestion dialog for the minutes approval workflow
 (minutes-ui-v1). Any meeting participant may suggest a correction while
 the minutes are in draft or review; the author is attributed
 server-side from the session.

 @spec openspec/specs/resolution-minutes/spec.md
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Suggest a correction')"
		data-testid="minutes-correction-modal"
		@closing="$emit('close')">
		<template #default>
			<p>
				{{
					t(
						'decidiq',
						'Describe the correction you propose. The chair or secretary reviews every suggestion before approving the minutes.',
					)
				}}
			</p>
			<NcTextArea
				v-model="text"
				data-testid="minutes-correction-text"
				:label="t('decidiq', 'Correction')"
				:placeholder="
					t(
						'decidiq',
						'e.g. The vote count for item 5 should read 12 in favour',
					)
				"
				resize="vertical" />
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="minutes-correction-confirm"
				:disabled="!text.trim()"
				@click="$emit('confirm', text.trim())">
				{{ t('decidiq', 'Submit suggestion') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextArea } from '@nextcloud/vue'

export default {
	name: 'MinutesCorrectionModal',
	components: { NcButton, NcDialog, NcTextArea },
	data() {
		return {
			text: '',
		}
	},
}
</script>
