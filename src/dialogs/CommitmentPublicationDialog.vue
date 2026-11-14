<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog: confirm publishing a commitment on the public list, or withdrawing it.
 The dialog only confirms; the parent saves on @confirm. Publication never
 happens without this explicit step (REQ-005).

 @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-005-public-toezeggingenlijst-via-the-or-published-predicate
-->
<template>
	<NcDialog
		:name="withdraw ? t('decidiq', 'Withdraw commitment') : t('decidiq', 'Publish commitment')"
		data-testid="commitment-publication-dialog"
		@closing="$emit('close')">
		<template #default>
			<p v-if="withdraw">
				{{ t('decidiq', 'The commitment leaves the public list now. Its history stays on the record.') }}
			</p>
			<p v-else>
				{{ t('decidiq', 'The commitment appears on the public list now, with its text, who made it, its deadline and its status. Later status changes show there without publishing again.') }}
			</p>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="commitment-publication-confirm"
				:disabled="saving"
				@click="$emit('confirm')">
				{{ withdraw ? t('decidiq', 'Withdraw') : t('decidiq', 'Publish') }}
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
	name: 'CommitmentPublicationDialog',

	components: { NcButton, NcDialog },

	props: {
		/** True to withdraw, false to publish */
		withdraw: { type: Boolean, default: false },
		/** True while the parent saves */
		saving: { type: Boolean, default: false },
	},

	emits: ['confirm', 'close'],
}
</script>
