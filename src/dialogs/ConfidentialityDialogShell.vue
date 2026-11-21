<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  The frame the confidentiality dialogs share (embargo-geheimhouding task 8):
  the dialog with its fields in the default slot, the server's refusal under
  them, and the confirm and Cancel buttons. The dialog that uses it posts and
  passes the refusal back as `error`.

  @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
-->
<template>
	<NcDialog
		:name="name"
		:data-testid="`confidentiality-${testId}-dialog`"
		@closing="$emit('close')">
		<slot />
		<CnNoteCard v-if="error" type="error">
			{{ error }}
		</CnNoteCard>
		<template #actions>
			<NcButton
				v-for="button in buttons"
				:key="button.event"
				:variant="button.variant"
				:disabled="button.disabled"
				:data-testid="button.testId"
				@click="$emit(button.event)">
				{{ button.label }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'ConfidentialityDialogShell',

	components: { CnNoteCard, NcButton, NcDialog },

	props: {
		/** The dialog's title, also the confirm button's label */
		name: { type: String, required: true },
		/** The test id stem: impose, ratify or lift */
		testId: { type: String, required: true },
		/** The server's refusal, shown as given */
		error: { type: String, default: '' },
		/** True while the form is incomplete or the parent posts */
		disabled: { type: Boolean, default: false },
	},

	emits: ['confirm', 'close'],

	computed: {
		/**
		 * Cancel, then the confirm button named after the dialog.
		 *
		 * @return {Array<object>} The buttons.
		 * @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-001-geheimhouding-record-with-structured-legal-ground
		 */
		buttons() {
			return [
				{ event: 'close', label: this.t('decidiq', 'Cancel'), variant: 'secondary', disabled: false, testId: `confidentiality-${this.testId}-cancel` },
				{ event: 'confirm', label: this.name, variant: 'primary', disabled: this.disabled, testId: `confidentiality-${this.testId}-confirm` },
			]
		},
	},
}
</script>
