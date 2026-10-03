<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog: a member declares a conflict of interest on a motion or agenda item.

 The member picks the kind of interest, gives the reason and may keep herself
 out of the vote at once. The parent posts the declaration on @submit
 (modal isolation, ADR-004).

 @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Declare a conflict of interest')"
		data-testid="conflict-declare-dialog"
		@closing="$emit('close')">
		<template #default>
			<div class="decidiq-conflict-form">
				<NcSelect
					v-model="type"
					:options="typeOptions"
					:inputLabel="t('decidiq', 'Kind of interest')"
					:clearable="false"
					data-testid="conflict-declare-type" />
				<NcTextArea
					v-model="description"
					data-testid="conflict-declare-reason"
					:label="t('decidiq', 'Reason')"
					:placeholder="
						t('decidiq', 'For example: I own land in the plan area')
					"
					resize="vertical" />
				<NcCheckboxRadioSwitch
					v-model="recuse"
					data-testid="conflict-declare-recuse">
					{{ t('decidiq', 'I will not vote on this') }}
				</NcCheckboxRadioSwitch>
				<p v-if="error" class="decidiq-error" role="alert">
					{{ error }}
				</p>
			</div>
		</template>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('decidiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="conflict-declare-submit"
				:disabled="busy || !description.trim()"
				@click="submit">
				{{ t('decidiq', 'Declare') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcSelect,
	NcTextArea,
} from '@nextcloud/vue'

export default {
	name: 'ConflictDeclareDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcSelect,
		NcTextArea,
	},

	props: {
		/** Whether the parent is posting the declaration */
		busy: { type: Boolean, default: false },
		/** The server's refusal, shown in the dialog */
		error: { type: String, default: '' },
	},

	emits: ['submit', 'close'],

	data() {
		const typeOptions = [
			{
				id: 'financial-interest',
				label: this.t('decidiq', 'Financial interest'),
			},
			{
				id: 'personal-relationship',
				label: this.t('decidiq', 'Personal relationship'),
			},
			{
				id: 'competing-business',
				label: this.t('decidiq', 'Competing business'),
			},
			{
				id: 'prior-involvement',
				label: this.t('decidiq', 'Earlier involvement'),
			},
		]
		return {
			typeOptions,
			type: typeOptions[0],
			description: '',
			recuse: true,
		}
	},

	methods: {
		/** @spec openspec/specs/conflict-of-interest/spec.md#requirement-req-coir-001-declare-a-conflict-of-interest-from-the-page */
		submit() {
			this.$emit('submit', {
				declarationType: this.type?.id || 'financial-interest',
				description: this.description,
				recuseFromVote: this.recuse,
			})
		},
	},
}
</script>

<style scoped>
.decidiq-conflict-form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.decidiq-error {
	color: var(--color-error-text);
	margin: 0;
}
</style>
