<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Reschedule dialog for a long-term agenda item. Opened by a drop on another
 period column of the board (with that period filled in) or by the card's
 Reschedule action, which is the keyboard path (WCAG 2.2 SC 2.5.7). The
 reason is mandatory; Cancel emits close and the caller writes nothing.

 @spec openspec/changes/termijnagenda/specs/termijnagenda-register/spec.md#requirement-req-lta-004-every-reschedule-records-a-mandatory-reason-and-preserves-full-shift-history
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Reschedule')"
		data-testid="planned-agenda-reschedule-dialog"
		@closing="$emit('close')">
		<template #default>
			<p>
				{{ t('decidiq', 'Currently planned for {period}.', { period: item.plannedPeriod || '—' }) }}
			</p>
			<NcTextField
				v-model="period"
				data-testid="planned-agenda-reschedule-period"
				:label="t('decidiq', 'New period (for example 2027-Q1 or 2027-03)')"
				:error="periodTouched && !periodOk"
				:helperText="periodTouched && !periodOk ? t('decidiq', 'Choose a different period, as YYYY-Qn or YYYY-MM.') : ''"
				@blur="periodTouched = true" />
			<NcTextArea
				v-model="reason"
				data-testid="planned-agenda-reschedule-reason"
				:label="t('decidiq', 'Reason for the shift')"
				resize="vertical" />
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="planned-agenda-reschedule-confirm"
				:disabled="!canConfirm"
				@click="$emit('confirm', { toPeriod: period.trim(), reason: reason.trim() })">
				{{ t('decidiq', 'Reschedule') }}
			</NcButton>
			<NcButton data-testid="planned-agenda-reschedule-cancel" @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextArea, NcTextField } from '@nextcloud/vue'
import { isValidPeriod } from '../utils/plannedAgenda.js'

export default {
	name: 'PlannedAgendaRescheduleDialog',
	components: { NcButton, NcDialog, NcTextArea, NcTextField },
	props: {
		item: { type: Object, required: true },
		initialPeriod: { type: String, default: '' },
	},

	emits: ['close', 'confirm'],
	data() {
		return {
			period: this.initialPeriod,
			periodTouched: Boolean(this.initialPeriod),
			reason: '',
		}
	},

	computed: {
		periodOk() {
			const period = this.period.trim()
			return isValidPeriod(period) && period !== this.item.plannedPeriod
		},

		canConfirm() {
			return this.periodOk && this.reason.trim() !== ''
		},
	},
}
</script>
