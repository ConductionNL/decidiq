<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Put a decision to a body in writing (voting-written-resolution, ADR-004
 modal isolation). The chair, vice-chair or secretary picks the body, the
 deadline and the rule; the server checks their scope, the decision and the
 electorate, and its refusal is shown here as it is.

 @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Decide in writing')"
		size="normal"
		data-testid="written-resolution-modal"
		@closing="$emit('close')">
		<template #default>
			<div class="written-resolution__form">
				<p>
					{{
						t(
							'decidiq',
							'Every entitled member of the body is asked to answer. Silence is not consent.',
						)
					}}
				</p>
				<NcSelect
					v-model="body"
					:inputLabel="t('decidiq', 'Body')"
					:options="bodies"
					label="label"
					:clearable="false"
					data-testid="written-resolution-body" />
				<div class="written-resolution__field">
					<label for="decidiq-written-deadline">{{
						t('decidiq', 'Deadline')
					}}</label>
					<NcDateTimePickerNative
						id="decidiq-written-deadline"
						v-model="deadline"
						type="datetime-local"
						data-testid="written-resolution-deadline" />
				</div>
				<NcSelect
					v-model="rule"
					:inputLabel="t('decidiq', 'Rule')"
					:options="ruleOptions"
					label="label"
					:clearable="false"
					data-testid="written-resolution-rule" />
				<NcSelect
					v-if="rule && rule.id === 'threshold'"
					v-model="threshold"
					:inputLabel="t('decidiq', 'Threshold of the whole body')"
					:options="thresholdOptions"
					label="label"
					:clearable="false"
					data-testid="written-resolution-threshold" />
				<p v-if="error" class="written-resolution__error" role="alert">
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
				:disabled="saving || !body || !deadline"
				data-testid="written-resolution-confirm"
				@click="save">
				{{ t('decidiq', 'Send to the members') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDateTimePickerNative, NcDialog, NcSelect } from '@nextcloud/vue'
import { openWrittenRound, refusalOf } from '../utils/writtenRound.js'

export default {
	name: 'WrittenResolutionModal',

	components: { NcButton, NcDateTimePickerNative, NcDialog, NcSelect },

	props: {
		/** The decision put in writing. */
		decisionId: { type: String, required: true },
		/** The bodies the user presides over: { id, label }. */
		bodies: { type: Array, default: () => [] },
	},

	emits: ['close', 'saved'],

	data() {
		return {
			body: this.bodies.length === 1 ? this.bodies[0] : null,
			deadline: null,
			rule: null,
			threshold: null,
			saving: false,
			error: '',
		}
	},

	computed: {
		/** @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-003-the-result-is-counted-against-the-whole-electorate */
		ruleOptions() {
			return [
				{ id: 'unanimous', label: this.t('decidiq', 'Every member agrees') },
				{ id: 'threshold', label: this.t('decidiq', 'A threshold of the whole body') },
			]
		},

		/** @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-003-the-result-is-counted-against-the-whole-electorate */
		thresholdOptions() {
			return [
				{ id: 'simple-majority', label: this.t('decidiq', 'Simple majority') },
				{ id: 'qualified-majority-two-thirds', label: this.t('decidiq', 'Two thirds') },
				{ id: 'qualified-majority-three-quarters', label: this.t('decidiq', 'Three quarters') },
			]
		},
	},

	created() {
		this.rule = this.ruleOptions[0]
		this.threshold = this.thresholdOptions[0]
	},

	methods: {
		/**
		 * Open the written round through the guarded route.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/voting-written-resolution/specs/written-resolution/spec.md#requirement-req-wrs-001-the-chair-or-secretary-of-a-body-puts-a-decision-to-the-body-in-writing
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				const round = await openWrittenRound({
					decisionId: this.decisionId,
					governanceBodyId: this.body.id,
					votingDeadline: new Date(this.deadline).toISOString(),
					writtenRule: this.rule.id,
					voteThreshold: this.threshold.id,
				})
				this.$emit('saved', round)
			} catch (e) {
				this.error = refusalOf(e, this.t('decidiq', 'The decision could not be put in writing.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.written-resolution__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.written-resolution__field {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.written-resolution__error {
	color: var(--color-error-text);
}
</style>
