<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Governance body — member turnover (ADR-004 dialog isolation).

 The secretariat uploads the incoming roster (the member-import CSV:
 name, email, role). The server lists who joins (an onboarding each) and
 who leaves (an offboarding each); nothing is created yet. The secretariat
 deselects what should not happen, names the batch and the leavers' end
 date, and confirms. The server checks every confirmed suggestion again and
 reports what it rejected. Memberships are never created or ended here.

 @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
-->
<template>
	<NcDialog
		:name="t('decidiq', 'Member turnover')"
		size="large"
		data-testid="member-turnover-dialog"
		@closing="$emit('close')">
		<template #default>
			<p>
				{{ t('decidiq', 'Upload the incoming roster as a CSV file with the columns: name, email, role. Nothing is created until you confirm.') }}
			</p>
			<input
				type="file"
				accept=".csv,text/csv"
				:aria-label="t('decidiq', 'CSV file')"
				data-testid="member-turnover-file"
				@change="onFile">

			<div v-if="suggestions" class="member-turnover__lists">
				<fieldset data-testid="member-turnover-joining">
					<legend>{{ t('decidiq', 'Joining ({count})', { count: suggestions.joining.length }) }}</legend>
					<p v-if="suggestions.joining.length === 0">
						{{ t('decidiq', 'Nobody joins.') }}
					</p>
					<NcCheckboxRadioSwitch
						v-for="row in suggestions.joining"
						:key="row.key"
						:modelValue="selection.joining.includes(row.key)"
						@update:modelValue="toggle('joining', row.key, $event)">
						{{ row.name || row.email }} ({{ row.role }})
					</NcCheckboxRadioSwitch>
				</fieldset>
				<fieldset data-testid="member-turnover-leaving">
					<legend>{{ t('decidiq', 'Leaving ({count})', { count: suggestions.leaving.length }) }}</legend>
					<p v-if="suggestions.leaving.length === 0">
						{{ t('decidiq', 'Nobody leaves.') }}
					</p>
					<NcCheckboxRadioSwitch
						v-for="row in suggestions.leaving"
						:key="row.membershipId"
						:modelValue="selection.leaving.includes(row.membershipId)"
						@update:modelValue="toggle('leaving', row.membershipId, $event)">
						{{ row.name || t('decidiq', 'Unknown person') }}
					</NcCheckboxRadioSwitch>
				</fieldset>
				<p v-if="suggestions.ignored.length" class="member-turnover__note">
					{{ t('decidiq', '{count} rows without an email were left out.', { count: suggestions.ignored.length }) }}
				</p>
				<NcTextField
					v-model="batch"
					:label="t('decidiq', 'Batch label')"
					data-testid="member-turnover-batch" />
				<NcDateTimePickerNative
					v-if="selection.leaving.length"
					id="member-turnover-end-date"
					v-model="endDateValue"
					type="date"
					:label="t('decidiq', 'Last day of membership for the leavers')"
					data-testid="member-turnover-end-date" />
			</div>

			<p v-if="error" class="member-turnover__error" role="alert" data-testid="member-turnover-error">
				{{ error }}
			</p>
			<p v-if="doneMessage" class="member-turnover__done" data-testid="member-turnover-done">
				{{ doneMessage }}
			</p>
		</template>
		<template #actions>
			<NcButton
				variant="primary"
				:disabled="busy || !ready"
				data-testid="member-turnover-confirm"
				@click="confirm">
				{{ t('decidiq', 'Create {count} onboardings and offboardings', { count: selectedCount }) }}
			</NcButton>
			<NcButton data-testid="member-turnover-cancel" @click="$emit('close')">
				{{ t('decidiq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDateTimePickerNative, NcDialog, NcTextField } from '@nextcloud/vue'
import { parseMemberCsv } from '../utils/memberImport.js'
import { canConfirm, confirmPayload, postTurnover, rosterRows, selectAll } from '../utils/rosterTurnover.js'

export default {
	name: 'MemberTurnoverDialog',
	components: { NcButton, NcCheckboxRadioSwitch, NcDateTimePickerNative, NcDialog, NcTextField },
	props: {
		/** OR object id of the governance body. */
		bodyId: { type: [String, Number], required: true },
	},

	emits: ['close', 'confirmed'],
	data() {
		return {
			rows: [],
			suggestions: null,
			selection: { joining: [], leaving: [] },
			batch: '',
			endDateValue: null,
			busy: false,
			error: '',
			doneMessage: '',
		}
	},

	computed: {
		/** @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import */
		endDate() {
			const value = this.endDateValue
			if (!(value instanceof Date) || Number.isNaN(value.getTime())) {
				return ''
			}
			const pad = (n) => String(n).padStart(2, '0')
			return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`
		},

		/** @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import */
		selectedCount() {
			return this.selection.joining.length + this.selection.leaving.length
		},

		/** @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import */
		ready() {
			return this.suggestions !== null && canConfirm(this.selection, { batch: this.batch, endDate: this.endDate })
		},
	},

	methods: {
		/**
		 * Read the CSV and ask the server for the suggestions.
		 *
		 * @param {Event} event The file input's change event.
		 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
		 */
		async onFile(event) {
			const file = event?.target?.files?.[0]
			this.error = ''
			this.doneMessage = ''
			this.suggestions = null
			if (!file) {
				return
			}
			const parsed = parseMemberCsv(await file.text())
			if (parsed.error) {
				this.error = this.t('decidiq', 'The file could not be read as a member list (name, email, role).')
				return
			}
			this.rows = rosterRows(parsed.rows)
			this.busy = true
			try {
				this.suggestions = await postTurnover(String(this.bodyId), 'suggestions', { rows: this.rows })
				this.selection = selectAll(this.suggestions)
			} catch (e) {
				this.error = e.message
			} finally {
				this.busy = false
			}
		},

		/**
		 * Select or deselect one suggestion.
		 *
		 * @param {string} list `joining` or `leaving`.
		 * @param {string} key The suggestion key.
		 * @param {boolean} selected Whether it is selected.
		 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
		 */
		toggle(list, key, selected) {
			const without = this.selection[list].filter((k) => k !== key)
			this.selection = { ...this.selection, [list]: selected ? [...without, key] : without }
		},

		/**
		 * Send the confirmation and report what was created and rejected.
		 *
		 * @spec openspec/changes/member-onboarding/specs/member-onboarding/spec.md#requirement-req-mob-009-raadswisseling-batch-orchestration-from-a-completed-member-import
		 */
		async confirm() {
			this.busy = true
			this.error = ''
			try {
				const result = await postTurnover(
					String(this.bodyId),
					'confirm',
					confirmPayload(this.rows, this.selection, { batch: this.batch, endDate: this.endDate }),
				)
				this.doneMessage = this.t('decidiq', '{onboardings} onboardings and {offboardings} offboardings created; {rejected} no longer applied.', {
					onboardings: result.onboardings.length,
					offboardings: result.offboardings.length,
					rejected: result.rejected.length,
				})
				this.suggestions = null
				this.selection = { joining: [], leaving: [] }
				this.$emit('confirmed', result)
			} catch (e) {
				this.error = e.message
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.member-turnover__lists {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 3);
	margin-top: calc(var(--default-grid-baseline) * 3);
}

.member-turnover__note {
	color: var(--color-text-maxcontrast);
}

.member-turnover__error {
	color: var(--color-text-error, var(--color-error));
}

.member-turnover__done {
	color: var(--color-text-success, var(--color-success));
}
</style>
