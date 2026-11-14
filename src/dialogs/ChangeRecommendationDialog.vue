<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Dialog: the chair or secretary recommends a text change to a range of lines
 of a motion. Picking the lines fills the current text read-only; the parent
 saves the recommendation on @submit (modal isolation, ADR-004).

 @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-002-a-recommendation-names-its-lines-its-passage-and-its-new-wording
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Recommend a change')"
		data-testid="change-recommendation-dialog"
		@closing="$emit('close')">
		<template #default>
			<div class="decidiq-recommendation-form">
				<div class="decidiq-recommendation-form__lines">
					<NcSelect
						v-model="from"
						:options="lineOptions"
						:inputLabel="t('decidiq', 'From line')"
						:clearable="false"
						data-testid="change-recommendation-from" />
					<NcSelect
						v-model="to"
						:options="lineOptions"
						:inputLabel="t('decidiq', 'To line')"
						:clearable="false"
						data-testid="change-recommendation-to" />
				</div>
				<NcTextArea
					:modelValue="passage"
					:label="t('decidiq', 'Current text')"
					data-testid="change-recommendation-current"
					readonly
					resize="vertical" />
				<NcTextArea
					v-model="proposedText"
					:label="t('decidiq', 'New text')"
					data-testid="change-recommendation-new"
					resize="vertical" />
				<NcTextArea
					v-model="reason"
					:label="t('decidiq', 'Reason')"
					data-testid="change-recommendation-reason"
					resize="vertical" />
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
				data-testid="change-recommendation-submit"
				:disabled="busy || passage === ''"
				@click="submit">
				{{ t('decidiq', 'Recommend') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect, NcTextArea } from '@nextcloud/vue'
import { numberLines, passageOf } from '../utils/lineNumbering.js'

export default {
	name: 'ChangeRecommendationDialog',

	components: { NcButton, NcDialog, NcSelect, NcTextArea },

	props: {
		/** The motion text, numbered the same way as the line view */
		text: { type: String, default: '' },
		/** Whether the parent is saving */
		busy: { type: Boolean, default: false },
		/** The server's refusal, shown in the dialog */
		error: { type: String, default: '' },
	},

	emits: ['submit', 'close'],

	data() {
		return {
			from: null,
			to: null,
			proposedText: '',
			reason: '',
		}
	},

	computed: {
		/** @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-002-a-recommendation-names-its-lines-its-passage-and-its-new-wording */
		lineOptions() {
			return numberLines(this.text).map((line) => ({
				id: line.number,
				label: String(line.number),
			}))
		},

		/** @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-002-a-recommendation-names-its-lines-its-passage-and-its-new-wording */
		passage() {
			if (!this.from || !this.to) return ''
			return passageOf(this.text, this.from.id, this.to.id)
		},
	},

	watch: {
		/** @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-002-a-recommendation-names-its-lines-its-passage-and-its-new-wording */
		from(option) {
			if (option && (!this.to || this.to.id < option.id)) {
				this.to = option
			}
		},

		/** @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-002-a-recommendation-names-its-lines-its-passage-and-its-new-wording */
		passage(value) {
			if (this.proposedText === '') {
				this.proposedText = value
			}
		},
	},

	methods: {
		/** @spec openspec/changes/motion-change-recommendations/specs/motion-amendment/spec.md#requirement-req-mcr-002-a-recommendation-names-its-lines-its-passage-and-its-new-wording */
		submit() {
			this.$emit('submit', {
				lineFrom: this.from.id,
				lineTo: this.to.id,
				targetPassage: this.passage,
				proposedText: this.proposedText,
				reason: this.reason,
			})
		},
	},
}
</script>

<style scoped>
.decidiq-recommendation-form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.decidiq-recommendation-form__lines {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 3);
}

.decidiq-error {
	color: var(--color-error-text);
	margin: 0;
}
</style>
