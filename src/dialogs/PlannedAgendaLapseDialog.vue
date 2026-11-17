<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Lapse dialog for a long-term agenda item. A lapsed item is final, and the
 reason is mandatory: the confirm button stays disabled without one.

 @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-003-lifecycle-is-declarative-with-shift-and-settle-states
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Mark as lapsed')"
		data-testid="planned-agenda-lapse-dialog"
		@closing="$emit('close')">
		<template #default>
			<p>
				{{ t('decidiq', 'A lapsed item is final and can no longer be rescheduled or realised.') }}
			</p>
			<NcTextArea
				v-model="reason"
				data-testid="planned-agenda-lapse-reason"
				:label="t('decidiq', 'Reason it lapses')"
				resize="vertical" />
		</template>
		<template #actions>
			<NcButton
				variant="error"
				data-testid="planned-agenda-lapse-confirm"
				:disabled="!reason.trim()"
				@click="$emit('confirm', reason.trim())">
				{{ t('decidiq', 'Mark as lapsed') }}
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
	name: 'PlannedAgendaLapseDialog',
	components: { NcButton, NcDialog, NcTextArea },
	emits: ['close', 'confirm'],
	data() {
		return { reason: '' }
	},
}
</script>
