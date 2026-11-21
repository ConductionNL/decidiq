<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog: declare another position the member holds outside the organisation.
 The parent builds the record with newPosition() and saves it on @submit.

 @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Declare another position')"
		data-testid="ancillary-position-dialog"
		@closing="$emit('close')">
		<template #default>
			<div class="decidiq-declaration">
				<NcSelect
					v-model="form.governanceBody"
					:inputLabel="t('decidiq', 'Governance body')"
					:options="bodies"
					label="label"
					data-testid="ancillary-position-body" />
				<NcTextField
					v-model="form.organisation"
					:label="t('decidiq', 'Organisation')"
					:maxlength="maxLength"
					data-testid="ancillary-position-organisation" />
				<NcTextField
					v-model="form.role"
					:label="t('decidiq', 'Function')"
					:maxlength="maxLength"
					data-testid="ancillary-position-role" />
				<NcTextField
					v-model="form.hoursIndication"
					:label="t('decidiq', 'Time spent (optional)')"
					:maxlength="maxLength"
					data-testid="ancillary-position-hours" />
				<label for="decidiq-ancillary-position-start">{{ t('decidiq', 'Start date') }}</label>
				<input
					id="decidiq-ancillary-position-start"
					v-model="form.startDate"
					type="date"
					data-testid="ancillary-position-start">
				<NcCheckboxRadioSwitch v-model="form.remunerated" data-testid="ancillary-position-remunerated">
					{{ t('decidiq', 'Remunerated') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="form.qualitateQua" data-testid="ancillary-position-ex-officio">
					{{ t('decidiq', 'Held ex officio') }}
				</NcCheckboxRadioSwitch>
				<p v-if="error" class="decidiq-declaration__error" role="alert">
					{{ error }}
				</p>
			</div>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="ancillary-position-confirm"
				:disabled="saving || !valid"
				@click="$emit('submit', { ...form })">
				{{ t('decidiq', 'Declare') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import { MAX_TEXT_LENGTH, positionFormIsValid } from '../utils/ownDeclarations.js'

export default {
	name: 'AncillaryPositionDialog',

	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcSelect, NcTextField },

	props: {
		/** The governance bodies to choose from, as `{ id, label }` options */
		bodies: { type: Array, default: () => [] },
		/** True while the parent saves */
		saving: { type: Boolean, default: false },
		/** The parent's save error, shown in the dialog */
		error: { type: String, default: '' },
	},

	emits: ['submit', 'close'],

	data() {
		return {
			form: {
				governanceBody: this.bodies.length === 1 ? this.bodies[0] : null,
				organisation: '',
				role: '',
				hoursIndication: '',
				startDate: '',
				remunerated: false,
				qualitateQua: false,
			},

			maxLength: MAX_TEXT_LENGTH,
		}
	},

	computed: {
		/** @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-001-nevenfunctie-schema-on-openregister */
		valid() {
			return positionFormIsValid(this.form)
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
