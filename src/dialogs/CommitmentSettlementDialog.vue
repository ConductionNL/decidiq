<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog: record how a commitment was settled. A note is required, evidence (a
 link or reference, such as the information letter) is optional. The parent
 saves the commitment as disposed on @submit.

 @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-002-lifecycle-is-declarative-with-four-states
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Record settlement')"
		data-testid="commitment-settlement-dialog"
		@closing="$emit('close')">
		<template #default>
			<div class="decidiq-settlement">
				<NcTextArea
					v-model="note"
					data-testid="commitment-settlement-note"
					:label="t('decidiq', 'How was the commitment settled?')"
					:maxlength="maxLength"
					:disabled="saving" />
				<NcTextField
					v-model="evidence"
					data-testid="commitment-settlement-evidence"
					:label="t('decidiq', 'Evidence (link or reference, optional)')"
					:disabled="saving" />
			</div>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="commitment-settlement-confirm"
				:disabled="saving || !valid"
				@click="$emit('submit', { note, evidence })">
				{{ t('decidiq', 'Record settlement') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextArea, NcTextField } from '@nextcloud/vue'
import {
	MAX_SETTLEMENT_NOTE_LENGTH,
	settlementNoteIsValid,
} from '../utils/commitmentPublication.js'

export default {
	name: 'CommitmentSettlementDialog',

	components: { NcButton, NcDialog, NcTextArea, NcTextField },

	props: {
		/** True while the parent saves */
		saving: { type: Boolean, default: false },
	},

	emits: ['submit', 'close'],

	data() {
		return {
			note: '',
			evidence: '',
			maxLength: MAX_SETTLEMENT_NOTE_LENGTH,
		}
	},

	computed: {
		/** @spec openspec/changes/toezeggingen-ingekomen-stukken/specs/toezeggingen-register/spec.md#requirement-req-002-lifecycle-is-declarative-with-four-states */
		valid() {
			return settlementNoteIsValid(this.note)
		},
	},
}
</script>

<style scoped>
.decidiq-settlement {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
}
</style>
