<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Withdraw-with-reason dialog for the public-publication flow
 (publish-decisions-via-opencatalogi). The reason is mandatory — the server
 refuses a withdraw without one; this dialog enforces the same rule client-side.

 @spec openspec/specs/public-publication/spec.md
-->
<template>
	<NcDialog
		:name="t('decidesk', 'Withdraw publication')"
		data-testid="publication-withdraw-modal"
		@closing="$emit('close')">
		<template #default>
			<p>
				{{
					t(
						'decidesk',
						'Withdrawing removes the published record from the public surface and retracts the OpenCatalogi publication. A reason is required and recorded in the audit trail (WOO correction duty).',
					)
				}}
			</p>
			<NcTextArea
				v-model="reason"
				data-testid="publication-withdraw-reason"
				:label="t('decidesk', 'Withdraw reason')"
				:placeholder="
					t(
						'decidesk',
						'e.g. Contained an error, corrected version follows',
					)
				"
				resize="vertical" />
		</template>
		<template #actions>
			<NcButton
				variant="error"
				data-testid="publication-withdraw-confirm"
				:disabled="!reason.trim()"
				@click="$emit('confirm', reason.trim())">
				{{ t('decidesk', 'Withdraw') }}
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
	name: 'PublicationWithdrawModal',
	components: { NcButton, NcDialog, NcTextArea },
	data() {
		return {
			reason: '',
		}
	},
}
</script>
