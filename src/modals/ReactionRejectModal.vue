<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Reject-with-reason dialog for the consultation reaction moderation queue
 (citizen-participation). The reason is mandatory — the server refuses a
 rejection without one; this dialog enforces the same rule client-side.

 @spec openspec/specs/citizen-participation/spec.md
-->
<template>
	<NcDialog
		:name="t('decidesk', 'Reject reaction')"
		data-testid="reaction-reject-modal"
		@closing="$emit('close')">
		<template #default>
			<p>{{ t('decidesk', 'The reaction is retained for audit but never counts toward the consultation. A reason is required.') }}</p>
			<NcTextArea
				:value.sync="reason"
				data-testid="reaction-reject-reason"
				:label="t('decidesk', 'Rejection reason')"
				:placeholder="t('decidesk', 'e.g. Off-topic or abusive')"
				resize="vertical" />
		</template>
		<template #actions>
			<NcButton
				type="error"
				data-testid="reaction-reject-confirm"
				:disabled="!reason.trim()"
				@click="$emit('confirm', reason.trim())">
				{{ t('decidesk', 'Reject') }}
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
	name: 'ReactionRejectModal',
	components: { NcButton, NcDialog, NcTextArea },
	data() {
		return {
			reason: '',
		}
	},
}
</script>
