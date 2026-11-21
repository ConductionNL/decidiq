<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  Dialog: record the decision that ratifies an imposed confidentiality
  restriction (embargo-geheimhouding REQ-EMB-003). Opened by the "Ratify
  confidentiality" header action on the restriction page while it is
  imposed; the server refuses a restriction whose ground needs no
  ratification, and the refusal is shown here.

  @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Ratify confidentiality')"
		data-testid="confidentiality-ratify-dialog"
		@closing="$emit('close')">
		<NcSelect
			v-model="decision"
			:options="decisions"
			label="label"
			:inputLabel="t('decidiq', 'Ratifying decision')"
			:clearable="false"
			data-testid="confidentiality-ratify-decision" />
		<p v-if="error" class="confidentiality-dialog__error" role="alert">
			{{ error }}
		</p>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="confidentiality-ratify-confirm"
				:disabled="working || payload === null"
				@click="confirm">
				{{ t('decidiq', 'Ratify confidentiality') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { dispatchAction } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'
import { objectOptions, optionsUrl, ratifyPayload, refusalOf, restrictionUrl } from '../utils/confidentialityActions.js'

export default {
	name: 'ConfidentialityRatifyDialog',

	components: { NcButton, NcDialog, NcSelect },

	props: {
		/** The restriction */
		object: { type: Object, default: null },
		/** Its id */
		objectId: { type: [String, Number], default: '' },
	},

	emits: ['close'],

	data() {
		return { decision: null, decisions: [], working: false, error: '' }
	},

	computed: {
		/** @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag */
		payload() {
			return ratifyPayload(this.decision)
		},
	},

	/** @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag */
	async mounted() {
		try {
			const response = await axios.get(optionsUrl('decision'))
			this.decisions = objectOptions(response.data, 'decision')
		} catch (e) {
			this.error = refusalOf(e, this.t('decidiq', 'The ratification could not be recorded.'))
		}
	},

	methods: {
		/** @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag */
		async confirm() {
			this.working = true
			this.error = ''
			try {
				await axios.post(restrictionUrl('ratify', this.objectId || this.object?.id), this.payload)
				dispatchAction({ type: 'refresh' })
				this.$emit('close')
			} catch (e) {
				this.error = refusalOf(e, this.t('decidiq', 'The ratification could not be recorded.'))
			} finally {
				this.working = false
			}
		},
	},
}
</script>

<style scoped>
.confidentiality-dialog__error {
	color: var(--color-error-text);
}
</style>
