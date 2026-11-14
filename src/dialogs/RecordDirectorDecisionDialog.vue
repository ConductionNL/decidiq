<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Dialog: record the director's decision on a works-council consultation
  (WOR art. 25 lid 5). The secretary picks the outcome, writes what the
  director decided and on which date; a decision contrary to the advice shows
  the suspension term it starts (art. 25 lid 6). The parent saves on @submit,
  with the move to decision-received.
  @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-006-bestuurder-decision-recording-and-art-25-lid-6-opschortingstermijn
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Record the director\'s decision')"
		data-testid="record-director-decision-dialog"
		@closing="$emit('close')">
		<template #default>
			<div class="decidiq-decision-form">
				<NcSelect
					v-model="outcome"
					:inputLabel="t('decidiq', 'Decision')"
					:options="outcomeOptions"
					:reduce="(option) => option.id"
					label="label"
					:clearable="false"
					data-testid="director-decision-outcome" />
				<NcTextArea
					v-model="text"
					:label="t('decidiq', 'What the director decided')"
					data-testid="director-decision-text" />
				<label for="decidiq-director-decision-date">{{ t('decidiq', 'Decision date') }}</label>
				<input
					id="decidiq-director-decision-date"
					v-model="date"
					type="date"
					data-testid="director-decision-date">
				<CnNoteCard v-if="suspension" type="warning" data-testid="director-decision-suspension">
					{{ t('decidiq', 'The decision departs from the advice: it may not be carried out before {date} (WOR art. 25 lid 6).', { date: suspension }) }}
				</CnNoteCard>
			</div>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="director-decision-confirm"
				:disabled="!complete || saving"
				@click="$emit('submit', { outcome, text, date })">
				{{ t('decidiq', 'Record the decision') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog, NcSelect, NcTextArea } from '@nextcloud/vue'
import { decisionIsComplete, decisionOutcomes, suspensionUntil } from '../utils/worksCouncilResponse.js'

const OUTCOME_LABELS = {
	'in-line-with-advice': 'In line with the advice',
	'contrary-to-advice': 'Contrary to the advice',
	'in-line-with-consent': 'In line with the consent',
	'not-pursued': 'Not pursued',
}

export default {
	name: 'RecordDirectorDecisionDialog',
	components: { CnNoteCard, NcButton, NcDialog, NcSelect, NcTextArea },
	props: {
		/** The request type: adviceRequest or consent-request */
		type: { type: String, required: true },
		/** True while the parent saves */
		saving: { type: Boolean, default: false },
	},

	emits: ['submit', 'close'],
	data() {
		return {
			outcome: null,
			text: '',
			date: new Date().toISOString().slice(0, 10),
		}
	},

	computed: {
		/** @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-006-bestuurder-decision-recording-and-art-25-lid-6-opschortingstermijn */
		outcomeOptions() {
			return decisionOutcomes(this.type).map((id) => ({ id, label: this.t('decidiq', OUTCOME_LABELS[id]) }))
		},

		/** @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-006-bestuurder-decision-recording-and-art-25-lid-6-opschortingstermijn */
		suspension() {
			return suspensionUntil(this.type, this.outcome, this.date)
		},

		/** @spec openspec/changes/works-council-consultation/specs/works-council-consultation/spec.md#requirement-req-wcc-006-bestuurder-decision-recording-and-art-25-lid-6-opschortingstermijn */
		complete() {
			return decisionIsComplete(this.type, { outcome: this.outcome, date: this.date })
		},
	},
}
</script>

<style scoped>
.decidiq-decision-form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}
</style>
