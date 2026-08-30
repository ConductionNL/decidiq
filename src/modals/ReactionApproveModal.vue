<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Approve-with-optional-note dialog for the consultation reaction moderation
 queue (citizen-participation). Approval sets the reaction to 'approved' so it
 surfaces in the consultation's reactions relation and becomes eligible for
 publication.

 @spec openspec/specs/citizen-participation/spec.md
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Approve reaction')"
		data-testid="reaction-approve-modal"
		@closing="$emit('close')">
		<template #default>
			<p>
				{{
					t(
						'decidiq',
						'Approving counts this reaction toward the consultation and allows it to be published.',
					)
				}}
			</p>
			<NcTextArea
				v-model="note"
				data-testid="reaction-approve-note"
				:label="t('decidiq', 'Moderation note (optional)')"
				resize="vertical" />
		</template>
		<template #actions>
			<NcButton
				variant="success"
				data-testid="reaction-approve-confirm"
				@click="$emit('confirm', note.trim())">
				{{ t('decidiq', 'Approve') }}
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
	name: 'ReactionApproveModal',
	components: { NcButton, NcDialog, NcTextArea },
	data() {
		return {
			note: '',
		}
	},
}
</script>
