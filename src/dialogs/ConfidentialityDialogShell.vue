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
		<p v-if="error" class="confidentiality-dialog__error" role="alert">
			{{ error }}
		</p>
		<template #actions>
			<NcButton
				variant="primary"
				:data-testid="`confidentiality-${testId}-confirm`"
				:disabled="disabled"
				@click="$emit('confirm')">
				{{ name }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'ConfidentialityDialogShell',

	components: { NcButton, NcDialog },

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
}
</script>

<style scoped>
.confidentiality-dialog__error {
	color: var(--color-error-text);
}
</style>
