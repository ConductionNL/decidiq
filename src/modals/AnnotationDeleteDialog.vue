<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Confirm deleting annotations (document-annotations): one annotation from the
 annotator's panel, or every own annotation from the user settings. The
 caller does the deleting; this only asks.

 @spec openspec/changes/document-annotations/specs/document-annotations/spec.md#requirement-req-ann-006-author-can-delete-and-export-their-own-annotations-gdpr
-->
<template>
	<NcDialog
		:name="title"
		data-testid="annotation-delete-dialog"
		@closing="$emit('close')">
		<p>{{ message }}</p>
		<p v-if="error" class="annotation-delete-dialog__error" role="alert">
			{{ error }}
		</p>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				:disabled="busy"
				data-testid="annotation-delete-confirm"
				@click="$emit('confirm')">
				{{ t('decidiq', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'AnnotationDeleteDialog',

	components: { NcButton, NcDialog },

	props: {
		/** The dialog title. */
		title: { type: String, required: true },
		/** What will be deleted. */
		message: { type: String, required: true },
		/** Whether the delete is running. */
		busy: { type: Boolean, default: false },
		/** Why the last delete failed. */
		error: { type: String, default: '' },
	},

	emits: ['confirm', 'close'],
}
</script>

<style scoped>
.annotation-delete-dialog__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
