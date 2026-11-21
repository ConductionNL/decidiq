<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  Dialog: lift a confidentiality restriction on the competent body's lifting
  decision (embargo-geheimhouding REQ-EMB-004), with optional conditions.
  Opened by the "Lift confidentiality" header action on the restriction page
  while it is imposed or ratified; the server checks who may lift and whether
  the ground needs ratification first, and the refusal is shown here.

  @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Lift confidentiality')"
		data-testid="confidentiality-lift-dialog"
		@closing="$emit('close')">
		<NcSelect
			v-model="decision"
			:options="decisions"
			label="label"
			:inputLabel="t('decidiq', 'Lifting decision')"
			:clearable="false"
			data-testid="confidentiality-lift-decision" />
		<NcTextArea
			v-model="conditions"
			:label="t('decidiq', 'Lifting conditions')"
			:maxlength="4000"
			resize="vertical"
			data-testid="confidentiality-lift-conditions" />
		<p v-if="error" class="confidentiality-dialog__error" role="alert">
			{{ error }}
		</p>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="confidentiality-lift-confirm"
				:disabled="working || payload === null"
				@click="confirm">
				{{ t('decidiq', 'Lift confidentiality') }}
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
import { NcButton, NcDialog, NcSelect, NcTextArea } from '@nextcloud/vue'
import { liftPayload, objectOptions, optionsUrl, refusalOf, restrictionUrl } from '../utils/confidentialityActions.js'

export default {
	name: 'ConfidentialityLiftDialog',

	components: { NcButton, NcDialog, NcSelect, NcTextArea },

	props: {
		/** The restriction */
		object: { type: Object, default: null },
		/** Its id */
		objectId: { type: [String, Number], default: '' },
	},

	emits: ['close'],

	data() {
		return { decision: null, decisions: [], conditions: '', working: false, error: '' }
	},

	computed: {
		/** @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery */
		payload() {
			return liftPayload(this.decision, this.conditions)
		},
	},

	/** @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery */
	async mounted() {
		try {
			const response = await axios.get(optionsUrl('decision'))
			this.decisions = objectOptions(response.data, 'decision')
		} catch (e) {
			this.error = refusalOf(e, this.t('decidiq', 'The confidentiality could not be lifted.'))
		}
	},

	methods: {
		/** @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery */
		async confirm() {
			this.working = true
			this.error = ''
			try {
				await axios.post(restrictionUrl('lift', this.objectId || this.object?.id), this.payload)
				dispatchAction({ type: 'refresh' })
				this.$emit('close')
			} catch (e) {
				this.error = refusalOf(e, this.t('decidiq', 'The confidentiality could not be lifted.'))
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
