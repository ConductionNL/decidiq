<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  Dialog: declare a decision urgent. The chair (or a role the body's urgency
  policy allows) states why; the parent posts it and shows the server's
  answer, so a refusal reads exactly as the server gave it.

  @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-001-guarded-urgency-declaration
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Declare urgent')"
		data-testid="declare-urgency-dialog"
		@closing="$emit('close')">
		<template #default>
			<p>
				{{ t('decidiq', 'An urgent decision takes effect now and is put to the ratifying body afterwards. The reason is stored with the decision and in its audit trail.') }}
			</p>
			<NcTextArea
				v-model="reason"
				:label="t('decidiq', 'Reason for urgency')"
				:maxlength="4000"
				data-testid="declare-urgency-reason"
				resize="vertical" />
			<CnNoteCard v-if="error" type="error">
				{{ error }}
			</CnNoteCard>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="declare-urgency-confirm"
				:disabled="reason.trim() === '' || saving"
				@click="$emit('submit', { reason: reason.trim() })">
				{{ t('decidiq', 'Declare urgent') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog, NcTextArea } from '@nextcloud/vue'

export default {
	name: 'DeclareUrgencyDialog',

	components: { CnNoteCard, NcButton, NcDialog, NcTextArea },

	props: {
		/** True while the parent saves */
		saving: { type: Boolean, default: false },
		/** The server's refusal, shown as given */
		error: { type: String, default: '' },
	},

	emits: ['submit', 'close'],

	data() {
		return {
			reason: '',
		}
	},
}
</script>
