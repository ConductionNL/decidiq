<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog: register a gift or an invitation the member received. The parent
 builds the record with newGift() and saves it on @submit.

 @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Register a gift')"
		data-testid="declared-gift-dialog"
		@closing="$emit('close')">
		<template #default>
			<div class="decidiq-declaration">
				<NcSelect
					v-model="form.governanceBody"
					:inputLabel="t('decidiq', 'Governance body')"
					:options="bodies"
					label="label"
					data-testid="declared-gift-body" />
				<NcSelect
					v-model="type"
					:inputLabel="t('decidiq', 'Type')"
					:options="typeOptions"
					label="label"
					data-testid="declared-gift-type" />
				<NcTextField
					v-model="form.giver"
					:label="t('decidiq', 'Giver')"
					:maxlength="maxLength"
					data-testid="declared-gift-giver" />
				<NcTextField
					v-model="form.description"
					:label="t('decidiq', 'Description')"
					:maxlength="maxLength"
					data-testid="declared-gift-description" />
				<NcTextField
					v-model="form.estimatedValue"
					type="number"
					min="0"
					:label="t('decidiq', 'Value (EUR)')"
					data-testid="declared-gift-value" />
				<label for="decidiq-declared-gift-received">{{ t('decidiq', 'Received on') }}</label>
				<input
					id="decidiq-declared-gift-received"
					v-model="form.receivedOn"
					type="date"
					data-testid="declared-gift-received">
				<NcSelect
					v-model="decision"
					:inputLabel="t('decidiq', 'Decision')"
					:options="decisionOptions"
					label="label"
					data-testid="declared-gift-decision" />
				<NcTextField
					v-model="form.notes"
					:label="t('decidiq', 'Note (optional)')"
					:maxlength="maxLength"
					data-testid="declared-gift-notes" />
				<p v-if="error" class="decidiq-declaration__error" role="alert">
					{{ error }}
				</p>
			</div>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				data-testid="declared-gift-confirm"
				:disabled="saving || !valid"
				@click="$emit('submit', { ...form })">
				{{ t('decidiq', 'Save') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import { giftFormIsValid, MAX_TEXT_LENGTH } from '../utils/ownDeclarations.js'

export default {
	name: 'DeclaredGiftDialog',

	components: { NcButton, NcDialog, NcSelect, NcTextField },

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
				type: 'gift',
				giver: '',
				description: '',
				estimatedValue: '',
				receivedOn: '',
				decision: 'accepted',
				notes: '',
			},

			maxLength: MAX_TEXT_LENGTH,
		}
	},

	computed: {
		/** @return {Array<{id: string, label: string}>} The registration kinds */
		typeOptions() {
			return [
				{ id: 'gift', label: this.t('decidiq', 'Gift') },
				{ id: 'invitation', label: this.t('decidiq', 'Invitation') },
			]
		},

		/** @return {Array<{id: string, label: string}>} The decisions on a gift */
		decisionOptions() {
			return [
				{ id: 'accepted', label: this.t('decidiq', 'Accepted') },
				{ id: 'refused', label: this.t('decidiq', 'Refused') },
				{ id: 'transferred', label: this.t('decidiq', 'Handed over') },
			]
		},

		type: {
			get() {
				return this.typeOptions.find((option) => option.id === this.form.type) || null
			},

			set(option) {
				this.form.type = option ? option.id : ''
			},
		},

		decision: {
			get() {
				return this.decisionOptions.find((option) => option.id === this.form.decision) || null
			},

			set(option) {
				this.form.decision = option ? option.id : ''
			},
		},

		/** @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-005-geschenk-schema-on-openregister */
		valid() {
			return giftFormIsValid(this.form)
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
