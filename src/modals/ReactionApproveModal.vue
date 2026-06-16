<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Approve-with-optional-note dialog for the consultation reaction moderation
 queue (citizen-participation). Approval increments the consultation's derived
 submissionCount and makes the reaction eligible for publication.

 @spec openspec/changes/citizen-participation/specs/citizen-participation/spec.md
-->
<template>
	<NcDialog
		:name="t('decidesk', 'Approve reaction')"
		data-testid="reaction-approve-modal"
		@closing="$emit('close')">
		<template #default>
			<p>{{ t('decidesk', 'Approving counts this reaction toward the consultation and allows it to be published.') }}</p>
			<NcTextArea
				:value.sync="note"
				data-testid="reaction-approve-note"
				:label="t('decidesk', 'Moderation note (optional)')"
				resize="vertical" />
		</template>
		<template #actions>
			<NcButton
				type="success"
				data-testid="reaction-approve-confirm"
				@click="$emit('confirm', note.trim())">
				{{ t('decidesk', 'Approve') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ t('decidesk', 'Cancel') }}
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
