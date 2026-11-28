<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  Dialog: record the decision that ratifies a confidentiality restriction
  (`action: ratify`, REQ-EMB-003) or lifts it (`action: lift`, REQ-EMB-004,
  with optional conditions). Opened by the "Ratify confidentiality" and "Lift
  confidentiality" header actions on the restriction page, which pass the
  action. The server checks who may and whether the restriction's state and
  ground allow it, and the refusal is shown here.

  @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag
  @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery
-->
<template>
	<ConfidentialityDialogShell
		:name="lifting ? t('decidiq', 'Lift confidentiality') : t('decidiq', 'Ratify confidentiality')"
		:testId="lifting ? 'lift' : 'ratify'"
		:error="error"
		:disabled="working || payload === null"
		@confirm="confirm"
		@close="$emit('close')">
		<NcSelect
			v-model="decision"
			:options="decisions"
			label="label"
			:inputLabel="lifting ? t('decidiq', 'Lifting decision') : t('decidiq', 'Ratifying decision')"
			:clearable="false"
			data-testid="confidentiality-decision" />
		<NcTextArea
			v-if="lifting"
			v-model="conditions"
			:label="t('decidiq', 'Lifting conditions')"
			:maxlength="4000"
			resize="vertical"
			data-testid="confidentiality-lift-conditions" />
	</ConfidentialityDialogShell>
</template>

<script>
import axios from '@nextcloud/axios'
import { NcSelect, NcTextArea } from '@nextcloud/vue'
import ConfidentialityDialogShell from './ConfidentialityDialogShell.vue'
import { decisionPayload, objectOptions, optionsUrl, refusalOf, restrictionUrl } from '../utils/confidentialityActions.js'
import { runModeration } from './speakingRequestModeration.js'

export default {
	name: 'ConfidentialityDecisionDialog',

	components: { ConfidentialityDialogShell, NcSelect, NcTextArea },

	props: {
		/** The restriction */
		object: { type: Object, default: null },
		/** Its id */
		objectId: { type: [String, Number], default: '' },
		/** ratify or lift */
		action: { type: String, default: 'ratify' },
	},

	emits: ['close'],

	data() {
		return { decision: null, decisions: [], conditions: '', working: false, error: '' }
	},

	computed: {
		/** @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-004-opheffing-workflow-routing-into-the-normal-publication-machinery */
		lifting() {
			return this.action === 'lift'
		},

		/** @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag */
		payload() {
			return decisionPayload(this.action, this.decision, this.conditions)
		},

		/** @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag */
		fallback() {
			return this.lifting
				? this.t('decidiq', 'The confidentiality could not be lifted.')
				: this.t('decidiq', 'The ratification could not be recorded.')
		},
	},

	/** @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag */
	async mounted() {
		try {
			const response = await axios.get(optionsUrl('decision'))
			this.decisions = objectOptions(response.data, 'decision')
		} catch (e) {
			this.error = refusalOf(e, this.fallback)
		}
	},

	methods: {
		/** @spec openspec/changes/embargo-geheimhouding/specs/embargo-geheimhouding/spec.md#requirement-req-emb-003-bekrachtiging-workflow-with-fail-visible-overdue-flag */
		async confirm() {
			this.working = true
			this.error = await runModeration(
				() => axios.post(restrictionUrl(this.lifting ? 'lift' : 'ratify', this.objectId || this.object?.id), this.payload),
				this.fallback,
			)
			this.working = false
			if (!this.error) this.$emit('close')
		},
	},
}
</script>
