<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Reject-with-comment dialog for the minutes approval workflow
 (minutes-ui-v1). The comment is mandatory — the server refuses a
 rejection without one; this dialog enforces the same rule client-side.

 @spec openspec/specs/resolution-minutes/spec.md
-->
<template>
	<NcDialog
		:name="t('decidesk', 'Reject minutes')"
		data-testid="minutes-reject-modal"
		@closing="$emit('close')">
		<template #default>
			<p>{{ t('decidesk', 'The minutes return to draft so the secretary can rework them. A comment explaining the rejection is required.') }}</p>
			<NcTextArea
				:value.sync="comment"
				data-testid="minutes-reject-comment"
				:label="t('decidesk', 'Rejection comment')"
				:placeholder="t('decidesk', 'e.g. Attendance list incomplete')"
				resize="vertical" />
		</template>
		<template #actions>
			<NcButton
				type="error"
				data-testid="minutes-reject-confirm"
				:disabled="!comment.trim()"
				@click="$emit('confirm', comment.trim())">
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
	name: 'MinutesRejectModal',
	components: { NcButton, NcDialog, NcTextArea },
	data() {
		return {
			comment: '',
		}
	},
}
</script>
