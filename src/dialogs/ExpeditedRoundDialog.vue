<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  Dialog: open the expedited written round of an urgent decision with a
  response deadline in hours. The bounds come from the body's urgency policy
  and are checked again by the server.

  @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-003-expedited-variant-b--written-round-with-an-hours-based-response-deadline
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Open expedited written round')"
		data-testid="expedited-round-dialog"
		@closing="$emit('close')">
		<template #default>
			<NcTextField
				v-model="hours"
				type="number"
				:label="t('decidiq', 'Response deadline (hours)')"
				data-testid="expedited-round-hours" />
			<p v-if="min !== null && max !== null">
				{{ t('decidiq', 'Between {min} and {max} hours.', { min, max }) }}
			</p>
			<CnNoteCard v-if="error" type="error">
				{{ error }}
			</CnNoteCard>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="expedited-round-confirm"
				:disabled="!valid || saving"
				@click="$emit('submit', { responseDeadlineHours: Number(hours) })">
				{{ t('decidiq', 'Open round') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'

export default {
	name: 'ExpeditedRoundDialog',

	components: { CnNoteCard, NcButton, NcDialog, NcTextField },

	props: {
		/** Shortest allowed deadline in hours, null when unknown */
		min: { type: Number, default: null },
		/** Longest allowed deadline in hours, null when unknown */
		max: { type: Number, default: null },
		/** True while the parent saves */
		saving: { type: Boolean, default: false },
		/** The server's refusal, shown as given */
		error: { type: String, default: '' },
	},

	emits: ['submit', 'close'],

	data() {
		return {
			hours: '24',
		}
	},

	computed: {
		/** @spec openspec/changes/urgent-decision-procedure/specs/urgent-decision-procedure/spec.md#requirement-req-003-expedited-variant-b--written-round-with-an-hours-based-response-deadline */
		valid() {
			const hours = Number(this.hours)
			if (!Number.isInteger(hours) || hours < 1) return false
			if (this.min !== null && hours < this.min) return false
			return this.max === null || hours <= this.max
		},
	},
}
</script>
