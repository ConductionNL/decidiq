<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog: end another position on a given day. The parent saves the position
 as ended with endedPosition() on @submit.

 @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-002-nevenfunctie-disclosure-lifecycle-is-declarative
-->
<template>
	<NcDialog
		:name="t('decidiq', 'End this position')"
		data-testid="ancillary-position-end-dialog"
		@closing="$emit('close')">
		<template #default>
			<div class="decidiq-declaration">
				<p>{{ t('decidiq', '{role} at {organisation}', { role: position.role || '', organisation: position.organisation || '' }) }}</p>
				<label for="decidiq-ancillary-position-end">{{ t('decidiq', 'End date') }}</label>
				<input
					id="decidiq-ancillary-position-end"
					v-model="endDate"
					type="date"
					:min="minDate"
					data-testid="ancillary-position-end-date">
				<p v-if="error" class="decidiq-declaration__error" role="alert">
					{{ error }}
				</p>
			</div>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="ancillary-position-end-confirm"
				:disabled="saving || !valid"
				@click="$emit('submit', endDate)">
				{{ t('decidiq', 'End position') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { dayOf, endDateIsValid } from '../utils/ownDeclarations.js'

export default {
	name: 'AncillaryPositionEndDialog',

	components: { NcButton, NcDialog },

	props: {
		/** The position to end */
		position: { type: Object, required: true },
		/** True while the parent saves */
		saving: { type: Boolean, default: false },
		/** The parent's save error, shown in the dialog */
		error: { type: String, default: '' },
	},

	emits: ['submit', 'close'],

	data() {
		return { endDate: dayOf() }
	},

	computed: {
		/** @return {string} The earliest acceptable day: the start date */
		minDate() {
			return String(this.position.startDate || '').slice(0, 10)
		},

		/** @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-002-nevenfunctie-disclosure-lifecycle-is-declarative */
		valid() {
			return endDateIsValid(this.position, this.endDate)
		},
	},
}
</script>

<style scoped>
.decidiq-declaration {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.decidiq-declaration__error {
	color: var(--color-error-text);
}
</style>
