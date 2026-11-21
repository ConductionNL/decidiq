<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog for a member's own declarations on My declarations: declare another
 position (`position`), register a gift (`gift`) or end a position (`end`).
 The fields are a list per kind, so the three forms share one frame. The
 parent builds the record from the emitted form and saves it on @submit.

 @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
-->
<template>
	<NcDialog
		:name="title"
		:data-testid="`declaration-${kind}-dialog`"
		@closing="$emit('close')">
		<div class="decidiq-declaration">
			<p v-if="position">
				{{ t('decidiq', '{role} at {organisation}', { role: position.role || '', organisation: position.organisation || '' }) }}
			</p>
			<template v-for="field in fields" :key="field.key">
				<NcSelect
					v-if="field.options"
					:modelValue="selected(field)"
					:inputLabel="field.label"
					:options="field.options"
					label="label"
					:data-testid="`declaration-${field.key}`"
					@update:modelValue="(option) => (form[field.key] = option ? option.id : '')" />
				<NcCheckboxRadioSwitch
					v-else-if="field.kind === 'switch'"
					v-model="form[field.key]"
					:data-testid="`declaration-${field.key}`">
					{{ field.label }}
				</NcCheckboxRadioSwitch>
				<label
					v-else-if="field.kind === 'date'"
					class="decidiq-declaration__date">
					{{ field.label }}
					<input
						v-model="form[field.key]"
						type="date"
						:data-testid="`declaration-${field.key}`">
				</label>
				<NcTextField
					v-else
					v-model="form[field.key]"
					:type="field.kind === 'number' ? 'number' : 'text'"
					:label="field.label"
					:maxlength="maxLength"
					:data-testid="`declaration-${field.key}`" />
			</template>
			<p v-if="error" class="decidiq-declaration__error" role="alert">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton
				variant="primary"
				:data-testid="`declaration-${kind}-confirm`"
				:disabled="saving || !valid"
				@click="$emit('submit', { ...form })">
				{{ confirmLabel }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import { DIALOG_KINDS, emptyForm, formIsValid, MAX_TEXT_LENGTH } from '../utils/ownDeclarations.js'

export default {
	name: 'DeclarationDialog',

	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcSelect, NcTextField },

	props: {
		/** Which form: position, gift or end */
		kind: { type: String, required: true, validator: (kind) => DIALOG_KINDS.includes(kind) },
		/** The governance bodies to choose from, as `{ id, label }` options */
		bodies: { type: Array, default: () => [] },
		/** The position being ended, for `end` */
		position: { type: Object, default: null },
		/** True while the parent saves */
		saving: { type: Boolean, default: false },
		/** The parent's save error, shown in the dialog */
		error: { type: String, default: '' },
	},

	emits: ['submit', 'close'],

	data() {
		return {
			form: emptyForm(this.kind, this.bodies),
			maxLength: MAX_TEXT_LENGTH,
		}
	},

	computed: {
		/** @return {string} The dialog's title */
		title() {
			if (this.kind === 'gift') return this.t('decidiq', 'Register a gift')
			if (this.kind === 'end') return this.t('decidiq', 'End this position')
			return this.t('decidiq', 'Declare another position')
		},

		/** @return {string} The confirm button's label */
		confirmLabel() {
			if (this.kind === 'end') return this.t('decidiq', 'End position')
			return this.kind === 'gift' ? this.t('decidiq', 'Save') : this.t('decidiq', 'Declare')
		},

		/**
		 * The fields of this kind of form, in order.
		 *
		 * @return {Array<{key: string, label: string, kind?: string, options?: Array<object>}>} The fields.
		 * @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi
		 */
		fields() {
			const t = (text) => this.t('decidiq', text)
			if (this.kind === 'end') return [{ key: 'endDate', kind: 'date', label: t('End date') }]
			const body = { key: 'governanceBody', label: t('Governance body'), options: this.bodies }
			if (this.kind === 'gift') {
				return [
					body,
					{ key: 'type', label: t('Type'), options: [{ id: 'gift', label: t('Gift') }, { id: 'invitation', label: t('Invitation') }] },
					{ key: 'giver', label: t('Giver') },
					{ key: 'description', label: t('Description') },
					{ key: 'estimatedValue', kind: 'number', label: t('Value (EUR)') },
					{ key: 'receivedOn', kind: 'date', label: t('Received on') },
					{ key: 'decision', label: t('Decision'), options: [{ id: 'accepted', label: t('Accepted') }, { id: 'refused', label: t('Refused') }, { id: 'transferred', label: t('Handed over') }] },
					{ key: 'notes', label: t('Note (optional)') },
				]
			}
			return [
				body,
				{ key: 'organisation', label: t('Organisation') },
				{ key: 'role', label: t('Function') },
				{ key: 'hoursIndication', label: t('Time spent (optional)') },
				{ key: 'startDate', kind: 'date', label: t('Start date') },
				{ key: 'remunerated', kind: 'switch', label: t('Remunerated') },
				{ key: 'qualitateQua', kind: 'switch', label: t('Held ex officio') },
			]
		},

		/** @spec openspec/changes/interests-and-integrity/specs/interests-and-integrity/spec.md#requirement-req-int-010-self-service-register-pages-compliance-view-and-dashboard-kpi */
		valid() {
			return formIsValid(this.kind, this.form, this.position)
		},
	},

	methods: {
		/**
		 * The option a select field currently holds.
		 *
		 * @param {{key: string, options: Array<{id: string}>}} field The field.
		 * @return {object|null} The option, or null.
		 */
		selected(field) {
			return field.options.find((option) => option.id === this.form[field.key]) || null
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

.decidiq-declaration__date {
	display: flex;
	flex-direction: column;
}

.decidiq-declaration__error {
	color: var(--color-error-text);
}
</style>
