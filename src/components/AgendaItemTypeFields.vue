<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The inputs for the fields an agenda item's type declares (#1393).

 Reads the type's `fields` (key, label, fieldType, required, enumValues) and
 renders one input per field, chosen by fieldType and captioned by label.
 v-model is the item's `typeFields` object; every change emits a new object,
 so the parent decides when to save. The field logic lives in
 src/utils/agendaItemTypeFields.js, where it is unit tested.

 @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md
-->
<template>
	<fieldset
		v-if="inputs.length > 0"
		class="decidiq-type-fields"
		data-testid="agenda-item-type-fields">
		<legend class="decidiq-type-fields__legend">
			{{ legend || t('decidiq', 'Fields for this type') }}
		</legend>
		<div
			v-for="input in inputs"
			:key="input.key"
			class="decidiq-type-fields__field"
			:data-type-field="input.key">
			<NcCheckboxRadioSwitch
				v-if="input.input === 'checkbox'"
				type="checkbox"
				:modelValue="Boolean(current[input.key])"
				:disabled="disabled"
				@update:modelValue="write(input, $event)">
				{{ input.label }}
			</NcCheckboxRadioSwitch>
			<NcSelect
				v-else-if="input.input === 'select'"
				:modelValue="current[input.key] ?? null"
				:inputLabel="caption(input)"
				:options="input.options"
				:disabled="disabled"
				@update:modelValue="write(input, $event)" />
			<NcSelect
				v-else-if="input.input === 'user'"
				:modelValue="userOption(input)"
				:inputLabel="caption(input)"
				:options="userOptions"
				:filterable="false"
				:disabled="disabled"
				@search="searchUsers"
				@update:modelValue="write(input, $event)" />
			<NcTextArea
				v-else-if="input.input === 'textarea'"
				:modelValue="text(input)"
				:label="caption(input)"
				:required="input.required"
				:disabled="disabled"
				resize="vertical"
				@update:modelValue="write(input, $event)" />
			<NcTextField
				v-else
				:modelValue="text(input)"
				:label="caption(input)"
				:type="inputType(input)"
				:required="input.required"
				:disabled="disabled"
				@update:modelValue="write(input, $event)" />
		</div>
	</fieldset>
</template>

<script>
import {
	NcCheckboxRadioSwitch,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { setTypeFieldValue, typeFieldInputs } from '../utils/agendaItemTypeFields.js'
import { searchDelegateUsers } from './userSettings/userPreferences.js'

export default {
	name: 'AgendaItemTypeFields',
	components: {
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** The AgendaItemType whose `fields` to render; null renders nothing. */
		type: { type: Object, default: null },
		/** The item's `typeFields` object. */
		modelValue: { type: Object, default: null },
		/** Optional caption for the group. */
		legend: { type: String, default: '' },
		/** Render read-only. */
		disabled: { type: Boolean, default: false },
	},

	emits: ['update:modelValue'],

	data() {
		return {
			userOptions: [],
		}
	},

	computed: {
		/** @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md */
		inputs() {
			return typeFieldInputs(this.type)
		},

		/** @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md */
		current() {
			return this.modelValue || {}
		},
	},

	methods: {
		/**
		 * @param {object} input The input definition.
		 * @return {string} The caption, with a marker for a required field.
		 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md
		 */
		caption(input) {
			return input.required ? `${input.label} *` : input.label
		},

		/**
		 * @param {object} input The input definition.
		 * @return {string} The stored value as text for a text-like control.
		 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md
		 */
		text(input) {
			const value = this.current[input.key]
			return value === undefined || value === null ? '' : String(value)
		},

		/**
		 * @param {object} input The input definition.
		 * @return {string} The HTML input type.
		 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md
		 */
		inputType(input) {
			if (input.input === 'number') return 'number'
			if (input.input === 'date') return 'date'
			return 'text'
		},

		/**
		 * The picked person as a picker option; the stored user id stands in
		 * for the name until a search returns it.
		 *
		 * @param {object} input The input definition.
		 * @return {?{id: string, label: string}} The option, or null.
		 * @spec openspec/specs/motion-management/spec.md#requirement-req-mtq-001-technical-questions-go-to-an-official-with-a-deadline
		 */
		userOption(input) {
			const id = this.current[input.key]
			if (!id) return null
			return (
				this.userOptions.find((option) => option.id === id) || {
					id,
					label: String(id),
				}
			)
		},

		/**
		 * Search the instance's users by name.
		 *
		 * @param {string} search What was typed.
		 * @spec openspec/specs/motion-management/spec.md#requirement-req-mtq-001-technical-questions-go-to-an-official-with-a-deadline
		 */
		async searchUsers(search) {
			if (!search || search.length < 2) return
			try {
				this.userOptions = await searchDelegateUsers(search)
			} catch {
				this.userOptions = []
			}
		},

		/**
		 * @param {object} input The input definition.
		 * @param {unknown} raw The value the control produced.
		 * @spec openspec/changes/questions-as-agenda-items/specs/questions-as-agenda-items/spec.md
		 */
		write(input, raw) {
			this.$emit(
				'update:modelValue',
				setTypeFieldValue(this.modelValue, input, raw),
			)
		},
	},
}
</script>

<style scoped>
.decidiq-type-fields {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
	padding: 0;
	border: none;
}

.decidiq-type-fields__legend {
	font-weight: bold;
	margin-bottom: 4px;
}
</style>
